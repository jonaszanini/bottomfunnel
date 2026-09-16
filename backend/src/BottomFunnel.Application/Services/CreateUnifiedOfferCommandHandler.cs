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

    public CreateUnifiedOfferCommandHandler(IAppDbContext db, ICurrentUser current, ICurrencyExchangeService fx)
    {
        _db = db;
        _current = current;
        _fx = fx;
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

            var offer = new Offer
            {
                OrganizationId = orgId,
                ProductId = product.Id,
                MerchantId = merchant.Id,
                Name = productName,
                AffiliateUrl = string.IsNullOrWhiteSpace(request.AffiliateUrl) ? null : request.AffiliateUrl.Trim(),
                Commission = request.PayoutAmount,
                PayoutAmountCad = payoutCad,
                ExchangeRateUsed = rate,
                CommissionType = request.CommissionType,
                Currency = currency,
                Category = request.Category,
                Status = OfferStatus.Active,
                ProviderKey = "manual",
                BrandBiddingAllowed = request.BrandBiddingAllowed,
                DirectLinkingAllowed = request.DirectLinkingAllowed,
                PpcAllowed = request.PpcAllowed
            };
            _db.Offers.Add(offer);
            await _db.SaveChangesAsync(ct);
            await tx.CommitAsync(ct);

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
        if (existing is not null)
            return existing;

        var merchant = new Merchant
        {
            OrganizationId = orgId,
            Name = name,
            PpcAllowed = request.PpcAllowed,
            BrandBiddingAllowed = request.BrandBiddingAllowed,
            DirectLinkingAllowed = request.DirectLinkingAllowed
        };
        _db.Merchants.Add(merchant);
        return merchant;
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
