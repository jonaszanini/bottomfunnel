using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Compliance;
using BottomFunnel.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class CatalogService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;

    public CatalogService(IAppDbContext db, ICurrentUser current)
    {
        _db = db;
        _current = current;
    }

    private Guid OrgId => _current.OrganizationId;

    public async Task<IReadOnlyList<MerchantDto>> ListMerchantsAsync(CancellationToken ct)
    {
        var merchants = await _db.Merchants.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .Include(x => x.Restrictions)
            .OrderBy(x => x.Name)
            .ToListAsync(ct);
        return merchants.Select(ToDto).ToList();
    }

    public async Task<MerchantDto> GetMerchantAsync(Guid id, CancellationToken ct) => ToDto(await LoadMerchant(id, ct));

    public async Task<MerchantDto> CreateMerchantAsync(UpsertMerchantRequest request, CancellationToken ct)
    {
        var entity = new Merchant { OrganizationId = OrgId };
        MapMerchant(entity, request);
        _db.Merchants.Add(entity);
        await _db.SaveChangesAsync(ct);
        return ToDto(entity);
    }

    public async Task<MerchantDto> UpdateMerchantAsync(Guid id, UpsertMerchantRequest request, CancellationToken ct)
    {
        var entity = await LoadMerchant(id, ct);
        MapMerchant(entity, request);
        entity.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return ToDto(entity);
    }

    public async Task DeleteMerchantAsync(Guid id, CancellationToken ct)
    {
        var entity = await GetMerchant(id, ct);
        _db.Merchants.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    public async Task<IReadOnlyList<ProductDto>> ListProductsAsync(CancellationToken ct) =>
        await _db.Products.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .OrderBy(x => x.Name)
            .Select(x => new ProductDto(x.Id, x.Name, x.Description, x.Category, x.Country, x.Currency, x.AverageOrderValue, x.ProviderKey, x.ExternalProductId))
            .ToListAsync(ct);

    public async Task<ProductDto> GetProductAsync(Guid id, CancellationToken ct)
    {
        var x = await GetProduct(id, ct);
        return new ProductDto(x.Id, x.Name, x.Description, x.Category, x.Country, x.Currency, x.AverageOrderValue, x.ProviderKey, x.ExternalProductId);
    }

    public async Task<ProductDto> CreateProductAsync(UpsertProductRequest request, CancellationToken ct)
    {
        var entity = new Product
        {
            OrganizationId = OrgId,
            Name = request.Name.Trim(),
            Description = request.Description,
            Category = request.Category,
            Country = request.Country,
            Currency = string.IsNullOrWhiteSpace(request.Currency) ? "USD" : request.Currency,
            AverageOrderValue = request.AverageOrderValue
        };
        _db.Products.Add(entity);
        await _db.SaveChangesAsync(ct);
        return ToDto(entity);
    }

    public async Task<ProductDto> UpdateProductAsync(Guid id, UpsertProductRequest request, CancellationToken ct)
    {
        var entity = await GetProduct(id, ct);
        entity.Name = request.Name.Trim();
        entity.Description = request.Description;
        entity.Category = request.Category;
        entity.Country = request.Country;
        entity.Currency = string.IsNullOrWhiteSpace(request.Currency) ? entity.Currency : request.Currency;
        entity.AverageOrderValue = request.AverageOrderValue;
        entity.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return ToDto(entity);
    }

    public async Task DeleteProductAsync(Guid id, CancellationToken ct)
    {
        var entity = await GetProduct(id, ct);
        _db.Products.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    public async Task<IReadOnlyList<OfferDto>> ListOffersAsync(CancellationToken ct)
    {
        var offers = await OfferQuery().AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .OrderBy(x => x.Name)
            .ToListAsync(ct);
        return offers.Select(ToDto).ToList();
    }

    public async Task<OfferDto> GetOfferAsync(Guid id, CancellationToken ct) =>
        ToDto(await OfferQuery().FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
              ?? throw new KeyNotFoundException("Offer not found."));

    public async Task<OfferDto> CreateOfferAsync(UpsertOfferRequest request, CancellationToken ct)
    {
        await GetProduct(request.ProductId, ct);
        var entity = MapOffer(new Offer { OrganizationId = OrgId }, request);
        _db.Offers.Add(entity);
        await _db.SaveChangesAsync(ct);
        return await GetOfferAsync(entity.Id, ct);
    }

    public async Task<OfferDto> UpdateOfferAsync(Guid id, UpsertOfferRequest request, CancellationToken ct)
    {
        var entity = await _db.Offers.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
                     ?? throw new KeyNotFoundException("Offer not found.");
        MapOffer(entity, request);
        entity.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return await GetOfferAsync(entity.Id, ct);
    }

    public async Task DeleteOfferAsync(Guid id, CancellationToken ct)
    {
        var entity = await _db.Offers.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
                     ?? throw new KeyNotFoundException("Offer not found.");
        _db.Offers.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    private IQueryable<Offer> OfferQuery() =>
        _db.Offers
            .Include(x => x.Product)
            .Include(x => x.Merchant).ThenInclude(m => m!.Restrictions)
            .Include(x => x.Restrictions);

    private async Task<Merchant> LoadMerchant(Guid id, CancellationToken ct) =>
        await _db.Merchants.Include(x => x.Restrictions)
            .FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
        ?? throw new KeyNotFoundException("Merchant not found.");

    private async Task<Merchant> GetMerchant(Guid id, CancellationToken ct) =>
        await _db.Merchants.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
        ?? throw new KeyNotFoundException("Merchant not found.");

    private async Task<Product> GetProduct(Guid id, CancellationToken ct) =>
        await _db.Products.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
        ?? throw new KeyNotFoundException("Product not found.");

    private static ProductDto ToDto(Product x) =>
        new(x.Id, x.Name, x.Description, x.Category, x.Country, x.Currency, x.AverageOrderValue, x.ProviderKey, x.ExternalProductId);

    public static MerchantDto ToDto(Merchant x) => new(
        x.Id, x.Name, x.Website, x.Country, x.ProviderKey,
        x.PpcAllowed, x.BrandBiddingAllowed, x.BrandPlusModifierAllowed, x.CouponBiddingAllowed, x.DirectLinkingAllowed,
        x.TrademarkNotes, x.AdRestrictions, x.LandingPageRestrictions, x.PolicyNotes,
        x.Restrictions.Select(ComplianceMapper.ToDto).ToList());

    public static OfferDto ToDto(Offer x)
    {
        var policy = OfferPolicyResolver.Resolve(x, x.Merchant);
        var restrictions = x.Restrictions.Concat(x.Merchant?.Restrictions ?? []).ToList();
        return new OfferDto(
            x.Id, x.ProductId, x.Product?.Name ?? "", x.MerchantId, x.Merchant?.Name, x.Name, x.AffiliateUrl, x.Commission,
            x.CommissionType, x.RecurringCommission, x.AverageOrderValue, x.Currency, x.Country, x.Category, x.Status,
            x.ProviderKey, x.ExternalProductId,
            x.PpcAllowed, x.BrandBiddingAllowed, x.BrandPlusModifierAllowed, x.CouponBiddingAllowed, x.DirectLinkingAllowed,
            x.TrademarkNotes, x.AdRestrictions, x.LandingPageRestrictions, x.PolicyNotes,
            new PolicyFlagsDto(policy.PpcAllowed, policy.BrandBiddingAllowed, policy.BrandPlusModifierAllowed, policy.CouponBiddingAllowed, policy.DirectLinkingAllowed),
            OfferPolicyResolver.WorstState(restrictions),
            restrictions.Select(ComplianceMapper.ToDto).ToList());
    }

    private static void MapMerchant(Merchant entity, UpsertMerchantRequest request)
    {
        entity.Name = request.Name.Trim();
        entity.Website = request.Website;
        entity.Country = request.Country;
        entity.PpcAllowed = request.PpcAllowed;
        entity.BrandBiddingAllowed = request.BrandBiddingAllowed;
        entity.BrandPlusModifierAllowed = request.BrandPlusModifierAllowed;
        entity.CouponBiddingAllowed = request.CouponBiddingAllowed;
        entity.DirectLinkingAllowed = request.DirectLinkingAllowed;
        entity.TrademarkNotes = request.TrademarkNotes;
        entity.AdRestrictions = request.AdRestrictions;
        entity.LandingPageRestrictions = request.LandingPageRestrictions;
        entity.PolicyNotes = request.PolicyNotes;
    }

    private static Offer MapOffer(Offer entity, UpsertOfferRequest request)
    {
        entity.ProductId = request.ProductId;
        entity.MerchantId = request.MerchantId;
        entity.AffiliateNetworkId = request.AffiliateNetworkId;
        entity.Name = request.Name.Trim();
        entity.AffiliateUrl = request.AffiliateUrl;
        entity.Commission = request.Commission;
        entity.CommissionType = request.CommissionType;
        entity.RecurringCommission = request.RecurringCommission;
        entity.AverageOrderValue = request.AverageOrderValue;
        entity.Currency = string.IsNullOrWhiteSpace(request.Currency) ? "USD" : request.Currency;
        entity.Country = request.Country;
        entity.Category = request.Category;
        entity.ApprovalRequirements = request.ApprovalRequirements;
        entity.Status = request.Status;
        entity.PpcAllowed = request.PpcAllowed;
        entity.BrandBiddingAllowed = request.BrandBiddingAllowed;
        entity.BrandPlusModifierAllowed = request.BrandPlusModifierAllowed;
        entity.CouponBiddingAllowed = request.CouponBiddingAllowed;
        entity.DirectLinkingAllowed = request.DirectLinkingAllowed;
        entity.TrademarkNotes = request.TrademarkNotes;
        entity.AdRestrictions = request.AdRestrictions;
        entity.LandingPageRestrictions = request.LandingPageRestrictions;
        entity.PolicyNotes = request.PolicyNotes;
        return entity;
    }
}

public static class ComplianceMapper
{
    public static ComplianceRestrictionDto ToDto(ComplianceRestriction x) => new(
        x.Id, x.MerchantId, x.OfferId, x.Kind, x.State, x.Source, x.Evidence, x.CheckedAtUtc, x.NextReviewAtUtc, x.Confidence);
}
