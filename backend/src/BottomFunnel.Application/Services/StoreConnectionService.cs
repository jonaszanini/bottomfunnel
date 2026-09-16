using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class StoreConnectionService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly IStoreConnectorRegistry _registry;
    private readonly ICredentialProtector _protector;
    private readonly IJobQueue _jobs;

    public StoreConnectionService(
        IAppDbContext db,
        ICurrentUser current,
        IStoreConnectorRegistry registry,
        ICredentialProtector protector,
        IJobQueue jobs)
    {
        _db = db;
        _current = current;
        _registry = registry;
        _protector = protector;
        _jobs = jobs;
    }

    private Guid OrgId => _current.OrganizationId;

    public IReadOnlyList<StoreProviderDto> ListProviders() =>
        _registry.All.Select(x => new StoreProviderDto(
            x.ProviderKey,
            x.DisplayName,
            x.Capabilities,
            x.CredentialSchema.Select(f => new CredentialFieldDto(f.Key, f.Label, f.Secret, f.Required, f.Placeholder)).ToList()
        )).ToList();

    public async Task<IReadOnlyList<StoreConnectionDto>> ListAsync(CancellationToken ct) =>
        await _db.StoreConnections.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .OrderBy(x => x.DisplayName)
            .Select(x => new StoreConnectionDto(x.Id, x.ProviderKey, x.DisplayName, x.Status, x.LastSyncedAt, x.LastError))
            .ToListAsync(ct);

    public async Task<StoreConnectionDto> SaveAsync(SaveStoreConnectionRequest request, CancellationToken ct)
    {
        var connector = _registry.GetRequired(request.ProviderKey);
        ValidateCredentials(connector, request.Credentials);
        await connector.TestConnectionAsync(request.Credentials, ct);

        var existing = await _db.StoreConnections
            .FirstOrDefaultAsync(x => x.OrganizationId == OrgId && x.ProviderKey == request.ProviderKey, ct);

        if (existing is null)
        {
            existing = new StoreConnection
            {
                OrganizationId = OrgId,
                ProviderKey = request.ProviderKey
            };
            _db.StoreConnections.Add(existing);
        }

        existing.DisplayName = string.IsNullOrWhiteSpace(request.DisplayName) ? connector.DisplayName : request.DisplayName;
        existing.EncryptedCredentials = _protector.Protect(request.Credentials);
        existing.Status = StoreConnectionStatus.Connected;
        existing.LastError = null;
        existing.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);

        return new StoreConnectionDto(existing.Id, existing.ProviderKey, existing.DisplayName, existing.Status, existing.LastSyncedAt, existing.LastError);
    }

    public async Task TestAsync(Guid id, CancellationToken ct)
    {
        var (connection, connector, creds) = await Load(id, ct);
        await connector.TestConnectionAsync(creds, ct);
        connection.Status = StoreConnectionStatus.Connected;
        connection.LastError = null;
        await _db.SaveChangesAsync(ct);
    }

    public async Task SyncAsync(Guid id, CancellationToken ct)
    {
        try
        {
            await SyncNowAsync(id, ct);
        }
        catch (Exception ex)
        {
            await RecordSyncFailureAsync(id, ex.Message, ct);
            throw;
        }
    }

    public async Task RecordSyncFailureAsync(Guid connectionId, string error, CancellationToken ct)
    {
        var connection = await _db.StoreConnections.FirstOrDefaultAsync(x => x.Id == connectionId, ct);
        if (connection is null)
            return;
        connection.Status = StoreConnectionStatus.Error;
        connection.LastError = error;
        connection.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
    }

    public async Task SyncNowAsync(Guid connectionId, CancellationToken ct)
    {
        var (connection, connector, creds) = await Load(connectionId, ct);
        if (!connector.Capabilities.HasFlag(StoreCapabilities.CanListProducts))
            throw new InvalidOperationException($"{connector.DisplayName} cannot list products.");

        var products = await connector.ListProductsAsync(creds, ct);
        var network = await EnsureNetwork(connector, ct);

        foreach (var item in products)
        {
            var merchant = await EnsureMerchant(item, connector.ProviderKey, ct);
            var product = await _db.Products.FirstOrDefaultAsync(x =>
                x.OrganizationId == connection.OrganizationId
                && x.ProviderKey == connector.ProviderKey
                && x.ExternalProductId == item.ExternalProductId, ct);

            if (product is null)
            {
                product = new Product
                {
                    OrganizationId = connection.OrganizationId,
                    ProviderKey = connector.ProviderKey,
                    ExternalProductId = item.ExternalProductId
                };
                _db.Products.Add(product);
            }

            product.Name = item.Name;
            product.Description = item.Description;
            product.Category = item.Category;
            product.Country = item.Country;
            product.Currency = item.Currency;
            product.AverageOrderValue = item.AverageOrderValue ?? item.Price;
            product.UpdatedAt = DateTimeOffset.UtcNow;

            var offer = await _db.Offers.FirstOrDefaultAsync(x =>
                x.OrganizationId == connection.OrganizationId
                && x.ProviderKey == connector.ProviderKey
                && x.ExternalProductId == item.ExternalProductId, ct);

            if (offer is null)
            {
                offer = new Offer
                {
                    OrganizationId = connection.OrganizationId,
                    ProviderKey = connector.ProviderKey,
                    ExternalProductId = item.ExternalProductId
                };
                _db.Offers.Add(offer);
            }

            offer.ProductId = product.Id;
            offer.MerchantId = merchant.Id;
            offer.AffiliateNetworkId = network.Id;
            offer.StoreConnectionId = connection.Id;
            offer.Name = item.Name;
            offer.AffiliateUrlTemplate = item.AffiliateUrlTemplate;
            offer.Commission = item.Commission;
            offer.CommissionType = item.CommissionType;
            offer.RecurringCommission = item.RecurringCommission;
            offer.AverageOrderValue = item.AverageOrderValue ?? item.Price;
            offer.Currency = item.Currency;
            offer.Country = item.Country;
            offer.Category = item.Category;
            offer.Status = item.Status;
            offer.ExternalVendorId = item.VendorId;
            offer.RawPayloadJson = item.RawPayloadJson;
            offer.UpdatedAt = DateTimeOffset.UtcNow;

            await _jobs.EnqueueAsync("generate-keywords", product.Id.ToString(), connection.OrganizationId, ct);
        }

        connection.LastSyncedAt = DateTimeOffset.UtcNow;
        connection.Status = StoreConnectionStatus.Connected;
        connection.LastError = null;
        await _db.SaveChangesAsync(ct);
    }

    public async Task<(StoreConnection Connection, IStoreConnector Connector, Dictionary<string, string> Credentials)> Load(Guid id, CancellationToken ct)
    {
        var connection = await _db.StoreConnections.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
                         ?? throw new KeyNotFoundException("Store connection not found.");
        var connector = _registry.GetRequired(connection.ProviderKey);
        var creds = _protector.Unprotect(connection.EncryptedCredentials);
        return (connection, connector, creds);
    }

    public async Task<(StoreConnection Connection, IStoreConnector Connector, Dictionary<string, string> Credentials)?> LoadForWebhookAsync(string providerKey, CancellationToken ct)
    {
        var connection = await _db.StoreConnections
            .FirstOrDefaultAsync(x => x.ProviderKey == providerKey && x.Status == StoreConnectionStatus.Connected, ct);
        if (connection is null)
            return null;
        var connector = _registry.Find(providerKey);
        if (connector is null)
            return null;
        return (connection, connector, _protector.Unprotect(connection.EncryptedCredentials));
    }

    private async Task<AffiliateNetwork> EnsureNetwork(IStoreConnector connector, CancellationToken ct)
    {
        var network = await _db.AffiliateNetworks.FirstOrDefaultAsync(x =>
            x.OrganizationId == OrgId && x.ProviderKey == connector.ProviderKey, ct);
        if (network is not null)
            return network;
        network = new AffiliateNetwork
        {
            OrganizationId = OrgId,
            Name = connector.DisplayName,
            ProviderKey = connector.ProviderKey
        };
        _db.AffiliateNetworks.Add(network);
        await _db.SaveChangesAsync(ct);
        return network;
    }

    private async Task<Merchant> EnsureMerchant(CanonicalProduct item, string providerKey, CancellationToken ct)
    {
        var name = item.VendorName ?? item.Name;
        var merchant = await _db.Merchants.FirstOrDefaultAsync(x =>
            x.OrganizationId == OrgId
            && x.ProviderKey == providerKey
            && (item.VendorId != null && x.ExternalVendorId == item.VendorId || x.Name == name), ct);

        if (merchant is null)
        {
            merchant = new Merchant
            {
                OrganizationId = OrgId,
                ProviderKey = providerKey,
                ExternalVendorId = item.VendorId,
                Name = name
            };
            _db.Merchants.Add(merchant);
            await _db.SaveChangesAsync(ct);
        }

        return merchant;
    }

    private static void ValidateCredentials(IStoreConnector connector, Dictionary<string, string> credentials)
    {
        foreach (var field in connector.CredentialSchema.Where(x => x.Required))
        {
            if (!credentials.TryGetValue(field.Key, out var value) || string.IsNullOrWhiteSpace(value))
                throw new InvalidOperationException($"Missing credential: {field.Label}");
        }
    }
}
