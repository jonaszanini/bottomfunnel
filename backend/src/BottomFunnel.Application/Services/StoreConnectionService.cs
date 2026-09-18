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

    public StoreConnectionService(
        IAppDbContext db,
        ICurrentUser current,
        IStoreConnectorRegistry registry,
        ICredentialProtector protector)
    {
        _db = db;
        _current = current;
        _registry = registry;
        _protector = protector;
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

    private static void ValidateCredentials(IStoreConnector connector, Dictionary<string, string> credentials)
    {
        foreach (var field in connector.CredentialSchema.Where(x => x.Required))
        {
            if (!credentials.TryGetValue(field.Key, out var value) || string.IsNullOrWhiteSpace(value))
                throw new InvalidOperationException($"Missing credential: {field.Label}");
        }
    }
}
