using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Scoring;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public sealed class CreateUnifiedOfferCommandHandler
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly ICurrencyExchangeService _fx;
    private readonly IJobQueue _jobs;

    public CreateUnifiedOfferCommandHandler(IAppDbContext db, ICurrentUser current, ICurrencyExchangeService fx, IJobQueue jobs)
    {
        _db = db;
        _current = current;
        _fx = fx;
        _jobs = jobs;
    }

    public async Task<CreateUnifiedOfferResponse> HandleAsync(CreateUnifiedOfferRequest request, CancellationToken ct)
    {
        var orgId = _current.OrganizationId;
        var productName = request.ProductName.Trim();
        var merchantName = request.MerchantName.Trim();
        if (string.IsNullOrWhiteSpace(productName))
            throw new InvalidOperationException("Product name is required.");
        if (string.IsNullOrWhiteSpace(merchantName))
            throw new InvalidOperationException("Merchant name is required.");
        if (request.PayoutAmount < 0)
            throw new InvalidOperationException("Payout amount cannot be negative.");

        var currency = string.IsNullOrWhiteSpace(request.Currency) ? "USD" : request.Currency.Trim().ToUpperInvariant();
        await using var tx = await _db.Database.BeginTransactionAsync(ct);
        try
        {
            var merchant = await ResolveMerchantAsync(orgId, merchantName, request, ct);
            var product = await ResolveProductAsync(orgId, productName, request.Category, currency, ct);
            var rate = request.ExchangeRateOverride is > 0
                ? Math.Round(request.ExchangeRateOverride.Value, 4, MidpointRounding.AwayFromZero)
                : await _fx.GetExchangeRateAsync(currency, "CAD", ct);
            var payoutCad = ProfitabilityCalculator.RoundMoney(request.PayoutAmount * rate);
            var providerKey = NormalizeProviderKey(request.ProviderKey);
            var hop = FirstValue(request.AffiliateUrl, request.BaseAffiliateUrl);

            var offer = new Offer
            {
                OrganizationId = orgId,
                ProductId = product.Id,
                MerchantId = merchant.Id,
                Name = productName,
                AffiliateUrl = hop,
                Commission = request.PayoutAmount,
                PayoutAmountCad = payoutCad,
                ExchangeRateUsed = rate,
                CommissionType = request.CommissionType,
                Currency = currency,
                Category = request.Category,
                Status = OfferStatus.Active,
                ProviderKey = providerKey,
                ExternalVendorId = providerKey == "clickbank" ? merchantName : null,
                BrandBiddingAllowed = request.BrandBiddingAllowed,
                DirectLinkingAllowed = request.DirectLinkingAllowed,
                PpcAllowed = request.PpcAllowed,
                TrafficRestrictions = string.IsNullOrWhiteSpace(request.TrafficRestrictions)
                    ? null
                    : request.TrafficRestrictions.Trim()
            };
            _db.Offers.Add(offer);
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

    private async Task<Merchant> ResolveMerchantAsync(Guid orgId, string name, CreateUnifiedOfferRequest request, CancellationToken ct)
    {
        var existing = await _db.Merchants.FirstOrDefaultAsync(
            x => x.OrganizationId == orgId && x.Name.ToLower() == name.ToLower(), ct);
        var pageUrl = string.IsNullOrWhiteSpace(request.AffiliatePageUrl) ? null : request.AffiliatePageUrl.Trim();
        var providerKey = NormalizeProviderKey(request.ProviderKey);
        if (existing is not null)
        {
            if (pageUrl is not null)
                existing.Website = pageUrl;
            existing.ProviderKey ??= providerKey;
            return existing;
        }

        var merchant = new Merchant
        {
            OrganizationId = orgId,
            Name = name,
            Website = pageUrl,
            ProviderKey = providerKey,
            PpcAllowed = request.PpcAllowed,
            BrandBiddingAllowed = request.BrandBiddingAllowed,
            DirectLinkingAllowed = request.DirectLinkingAllowed
        };
        _db.Merchants.Add(merchant);
        return merchant;
    }

    private static string NormalizeProviderKey(string? value)
    {
        var key = (value ?? "manual").Trim().ToLowerInvariant();
        return key is "clickbank" or "digistore24" or "manual" ? key : "manual";
    }

    private static string? FirstValue(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }
        return null;
    }

    private async Task<Product> ResolveProductAsync(Guid orgId, string name, string? category, string currency, CancellationToken ct)
    {
        var existing = await _db.Products.FirstOrDefaultAsync(
            x => x.OrganizationId == orgId && x.Name.ToLower() == name.ToLower(), ct);
        if (existing is not null)
            return existing;

        var product = new Product
        {
            OrganizationId = orgId,
            Name = name,
            Category = category,
            Currency = currency
        };
        _db.Products.Add(product);
        return product;
    }
}
