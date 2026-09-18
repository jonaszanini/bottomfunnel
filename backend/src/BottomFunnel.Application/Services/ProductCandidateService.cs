using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Scoring;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public sealed class ProductCandidateService
{
    public const string ClickBankAffiliate = "jzanini";

    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly ICurrencyExchangeService _fx;
    private readonly IJobQueue _jobs;
    private readonly IAiService _ai;
    private readonly IHttpClientFactory _http;
    private readonly IStoreConnectorRegistry _stores;
    private readonly ICredentialProtector _protector;

    public ProductCandidateService(
        IAppDbContext db,
        ICurrentUser current,
        ICurrencyExchangeService fx,
        IJobQueue jobs,
        IAiService ai,
        IHttpClientFactory http,
        IStoreConnectorRegistry stores,
        ICredentialProtector protector)
    {
        _db = db;
        _current = current;
        _fx = fx;
        _jobs = jobs;
        _ai = ai;
        _http = http;
        _stores = stores;
        _protector = protector;
    }

    public async Task<PagedProductCandidatesDto> ListAsync(GetProductCandidatesQuery query, CancellationToken ct)
    {
        var page = Math.Max(1, query.Page);
        var pageSize = Math.Clamp(query.PageSize <= 0 ? 50 : query.PageSize, 1, 200);
        var rows = _db.ProductCandidates.AsNoTracking().AsQueryable();
        if (query.Status is { } status)
            rows = rows.Where(x => x.Status == status);
        if (!string.IsNullOrWhiteSpace(query.Search))
        {
            var term = query.Search.Trim().ToLower();
            rows = rows.Where(x =>
                x.Title.ToLower().Contains(term)
                || x.ExternalId.ToLower().Contains(term)
                || (x.Vendor != null && x.Vendor.ToLower().Contains(term))
                || (x.Description != null && x.Description.ToLower().Contains(term)));
        }

        var total = await rows.CountAsync(ct);
        var items = await rows
            .OrderByDescending(x => x.Gravity)
            .ThenBy(x => x.Title)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(ct);
        return new PagedProductCandidatesDto(items.Select(ToDto).ToList(), total, page, pageSize);
    }

    public async Task<AuditCandidateResponse> AuditAsync(Guid id, CancellationToken ct)
    {
        if (!await _ai.IsConfiguredAsync(ct))
            throw new InvalidOperationException("Configure an AI provider in Settings before running a policy audit.");

        var candidate = await _db.ProductCandidates.FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Candidate not found.");

        var hop = ClickBankHop(candidate.ExternalId);
        var pageUrl = FirstUrl(candidate.AffiliatePageUrl, hop);
        var salesPage = await DownloadAsync(pageUrl, ct);
        if (string.IsNullOrWhiteSpace(salesPage) && !string.Equals(pageUrl, hop, StringComparison.OrdinalIgnoreCase))
            salesPage = await DownloadAsync(hop, ct);
        if (string.IsNullOrWhiteSpace(salesPage))
            throw new InvalidOperationException("Could not download the candidate affiliate or sales page to audit.");

        var result = await _ai.AuditOfferSalesPageAsync(candidate.Title, salesPage, null, null, ct);
        if (candidate.Status != CandidateStatus.Promoted)
        {
            if (result.OverallRisk is PolicyRiskLevel.Severe or PolicyRiskLevel.Critical)
            {
                candidate.Status = CandidateStatus.Disqualified;
                candidate.DisqualificationReason = Truncate(
                    string.IsNullOrWhiteSpace(result.ExecutiveSummary)
                        ? $"{result.OverallRisk} Google Ads policy risk ({result.RiskScore}/100)."
                        : result.ExecutiveSummary,
                    2000);
            }
            else
            {
                candidate.Status = CandidateStatus.Qualified;
                candidate.DisqualificationReason = null;
            }
        }

        candidate.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return new AuditCandidateResponse(ToDto(candidate), result);
    }

    public async Task<CreateUnifiedOfferResponse> PromoteAsync(
        PromoteCandidateToOfferCommand command,
        PromoteCandidateToOfferRequest? extras,
        CancellationToken ct)
    {
        if (command.PayoutAmount < 0)
            throw new InvalidOperationException("Payout amount cannot be negative.");

        var candidate = await _db.ProductCandidates.FirstOrDefaultAsync(x => x.Id == command.CandidateId, ct)
                        ?? throw new KeyNotFoundException("Candidate not found.");
        if (candidate.Status == CandidateStatus.Promoted)
            throw new InvalidOperationException("This candidate is already promoted to an offer.");
        if (candidate.Status == CandidateStatus.Disqualified)
            throw new InvalidOperationException("Disqualified candidates cannot be promoted. Re-run Audit if the policy risk was a false positive.");

        var orgId = _current.OrganizationId;
        var currency = string.IsNullOrWhiteSpace(command.Currency) ? "USD" : command.Currency.Trim().ToUpperInvariant();
        var productId = candidate.ExternalId.Trim();
        var vendor = string.IsNullOrWhiteSpace(candidate.Vendor) ? productId : candidate.Vendor.Trim();
        var hop = await ResolveHopAsync(candidate, vendor, productId, extras?.AffiliateUrl, ct);

        await using var tx = await _db.Database.BeginTransactionAsync(ct);
        try
        {
            var merchant = await ResolveMerchantAsync(orgId, vendor, candidate.AffiliatePageUrl, candidate.ProviderKey, extras, ct);
            var product = await ResolveProductAsync(orgId, candidate.Title, candidate.Category, currency, vendor, candidate.ProviderKey, ct);
            var rate = await _fx.GetExchangeRateAsync(currency, "CAD", ct);
            var payoutCad = ProfitabilityCalculator.RoundMoney(command.PayoutAmount * rate);

            var offer = new Offer
            {
                OrganizationId = orgId,
                ProductId = product.Id,
                MerchantId = merchant.Id,
                Name = candidate.Title,
                AffiliateUrl = hop,
                Commission = command.PayoutAmount,
                PayoutAmountCad = payoutCad,
                ExchangeRateUsed = rate,
                Currency = currency,
                Category = candidate.Category,
                Status = OfferStatus.Active,
                ProviderKey = candidate.ProviderKey,
                ExternalVendorId = vendor,
                ExternalProductId = productId,
                BrandBiddingAllowed = extras?.BrandBiddingAllowed ?? false,
                DirectLinkingAllowed = extras?.DirectLinkingAllowed ?? false,
                PpcAllowed = true,
                TrafficRestrictions = string.IsNullOrWhiteSpace(extras?.TrafficRestrictions)
                    ? null
                    : extras.TrafficRestrictions.Trim()
            };
            _db.Offers.Add(offer);

            candidate.Status = CandidateStatus.Promoted;
            candidate.DisqualificationReason = null;
            candidate.UpdatedAt = DateTimeOffset.UtcNow;

            await _db.SaveChangesAsync(ct);
            await tx.CommitAsync(ct);
            await _jobs.EnqueueAsync("offer-market-signals", offer.Id.ToString(), orgId, ct);

            return new CreateUnifiedOfferResponse(
                offer.Id,
                product.Id,
                merchant.Id,
                offer.Commission,
                offer.PayoutAmountCad,
                offer.ExchangeRateUsed,
                offer.Currency);
        }
        catch
        {
            await tx.RollbackAsync(ct);
            throw;
        }
    }

    private async Task<Merchant> ResolveMerchantAsync(
        Guid orgId,
        string vendor,
        string? affiliatePageUrl,
        string providerKey,
        PromoteCandidateToOfferRequest? extras,
        CancellationToken ct)
    {
        var existing = await _db.Merchants.FirstOrDefaultAsync(
            x => x.OrganizationId == orgId && x.Name.ToLower() == vendor.ToLower(), ct);
        if (existing is not null)
        {
            if (!string.IsNullOrWhiteSpace(affiliatePageUrl))
                existing.Website = affiliatePageUrl;
            existing.ProviderKey ??= providerKey;
            return existing;
        }

        var merchant = new Merchant
        {
            OrganizationId = orgId,
            Name = vendor,
            Website = affiliatePageUrl,
            ProviderKey = providerKey,
            ExternalVendorId = vendor,
            BrandBiddingAllowed = extras?.BrandBiddingAllowed ?? false,
            DirectLinkingAllowed = extras?.DirectLinkingAllowed ?? false
        };
        _db.Merchants.Add(merchant);
        return merchant;
    }

    private async Task<Product> ResolveProductAsync(
        Guid orgId,
        string title,
        string? category,
        string currency,
        string vendor,
        string providerKey,
        CancellationToken ct)
    {
        var existing = await _db.Products.FirstOrDefaultAsync(
            x => x.OrganizationId == orgId && x.Name.ToLower() == title.ToLower(), ct);
        if (existing is not null)
            return existing;

        var product = new Product
        {
            OrganizationId = orgId,
            Name = title,
            Category = category,
            Currency = currency,
            ProviderKey = providerKey,
            ExternalProductId = vendor
        };
        _db.Products.Add(product);
        return product;
    }

    internal static string ClickBankHop(string vendor)
    {
        var nickname = new string(vendor.Trim().ToLowerInvariant().Where(char.IsLetterOrDigit).ToArray());
        return nickname.Length == 0
            ? ""
            : $"https://{nickname}.{ClickBankAffiliate}.hop.clickbank.net/";
    }

    public async Task<ClickBankFeedSyncResult> SyncDigistore24MarketplaceAsync(CancellationToken ct)
    {
        var connection = await _db.StoreConnections
            .Where(x => x.OrganizationId == _current.OrganizationId && x.ProviderKey == "digistore24")
            .OrderByDescending(x => x.Status == StoreConnectionStatus.Connected)
            .ThenByDescending(x => x.UpdatedAt)
            .FirstOrDefaultAsync(ct)
            ?? throw new InvalidOperationException("Save a Digistore24 API key on Networks before syncing the marketplace.");

        var creds = _protector.Unprotect(connection.EncryptedCredentials);
        if (!creds.TryGetValue("apiKey", out var apiKey) || string.IsNullOrWhiteSpace(apiKey))
            throw new InvalidOperationException("Save a Digistore24 API key on Networks before syncing the marketplace.");

        var products = await _stores.GetRequired("digistore24").ListProductsAsync(creds, ct);
        if (products.Count == 0)
            throw new InvalidOperationException("Digistore24 listMarketplaceEntries returned no entries for the API key saved on Networks. That call lists this account's own marketplace listings, not the public affiliate catalog.");
        var existingRows = await _db.ProductCandidates
            .Where(x => x.ProviderKey == "digistore24")
            .ToListAsync(ct);
        var existing = existingRows.ToDictionary(x => x.ExternalId, StringComparer.OrdinalIgnoreCase);
        var added = new List<ProductCandidate>();
        var result = UpsertMarketplace("digistore24", products, existing, added, DateTimeOffset.UtcNow);
        if (added.Count > 0)
            _db.ProductCandidates.AddRange(added);
        await _db.SaveChangesAsync(ct);
        return result;
    }

    internal static ClickBankFeedSyncResult UpsertMarketplace(
        string providerKey,
        IEnumerable<CanonicalProduct> products,
        Dictionary<string, ProductCandidate> existing,
        List<ProductCandidate> added,
        DateTimeOffset now)
    {
        var inserted = 0;
        var updated = 0;
        var skipped = 0;
        var examined = 0;
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var item in products)
        {
            examined++;
            var externalId = item.ExternalProductId?.Trim() ?? "";
            if (externalId.Length == 0 || string.IsNullOrWhiteSpace(item.Name) || !seen.Add(externalId))
            {
                skipped++;
                continue;
            }

            if (!existing.TryGetValue(externalId, out var row))
            {
                row = new ProductCandidate
                {
                    ProviderKey = providerKey,
                    ExternalId = externalId,
                    Status = CandidateStatus.PendingReview,
                    DiscoveredAt = now
                };
                existing[externalId] = row;
                added.Add(row);
                inserted++;
            }
            else
            {
                updated++;
            }

            row.Title = Truncate(item.Name.Trim(), 300);
            row.Description = item.Description ?? "";
            row.Vendor = string.IsNullOrWhiteSpace(item.VendorName) ? row.Vendor : Truncate(item.VendorName.Trim(), 200);
            row.Gravity = item.Commission;
            row.Category = string.IsNullOrWhiteSpace(item.Category) ? row.Category : Truncate(item.Category.Trim(), 200);
            if (!string.IsNullOrWhiteSpace(item.AffiliateUrlTemplate))
                row.AffiliatePageUrl = Truncate(item.AffiliateUrlTemplate.Trim(), 2000);
            row.UpdatedAt = now;
        }

        return new ClickBankFeedSyncResult(inserted, updated, skipped, examined);
    }

    private async Task<string> ResolveHopAsync(
        ProductCandidate candidate,
        string vendor,
        string productId,
        string? requestedUrl,
        CancellationToken ct)
    {
        if (!string.IsNullOrWhiteSpace(requestedUrl))
            return requestedUrl.Trim();
        if (!string.Equals(candidate.ProviderKey, "digistore24", StringComparison.OrdinalIgnoreCase))
            return ClickBankHop(vendor);

        var connection = await _db.StoreConnections.FirstOrDefaultAsync(
            x => x.OrganizationId == _current.OrganizationId && x.ProviderKey == "digistore24", ct);
        var affiliate = "affiliate";
        if (connection is not null)
        {
            var creds = _protector.Unprotect(connection.EncryptedCredentials);
            if (creds.TryGetValue("affiliateId", out var id) && !string.IsNullOrWhiteSpace(id))
                affiliate = id.Trim();
        }

        return $"https://www.digistore24.com/redir/{Uri.EscapeDataString(productId)}/{Uri.EscapeDataString(affiliate)}/";
    }

    private async Task<string> DownloadAsync(string? url, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(url) || !Uri.TryCreate(url, UriKind.Absolute, out var uri)
            || uri.Scheme is not ("http" or "https"))
            return "";
        try
        {
            var client = _http.CreateClient("webpage");
            using var response = await client.GetAsync(uri, ct);
            if (!response.IsSuccessStatusCode)
                return "";
            return HtmlContentSanitizer.ToPlainText(await response.Content.ReadAsStringAsync(ct));
        }
        catch (Exception)
        {
            return "";
        }
    }

    private static string? FirstUrl(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }
        return null;
    }

    private static string Truncate(string value, int max) =>
        value.Length <= max ? value : value[..max];

    public static ProductCandidateDto ToDto(ProductCandidate x) => new(
        x.Id,
        x.ProviderKey,
        x.ExternalId,
        x.Vendor,
        x.Title,
        x.Description,
        x.Gravity,
        x.Category,
        x.AffiliatePageUrl,
        x.Status,
        x.DisqualificationReason,
        x.DiscoveredAt,
        x.UpdatedAt);
}
