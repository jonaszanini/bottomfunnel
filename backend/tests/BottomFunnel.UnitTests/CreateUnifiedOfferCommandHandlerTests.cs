using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Services;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Infrastructure.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;

namespace BottomFunnel.UnitTests;

public class CreateUnifiedOfferCommandHandlerTests
{
    [Fact]
    public async Task Creates_merchant_and_product_and_converts_payout_to_cad()
    {
        var (handler, db, orgId) = Create();
        var result = await handler.HandleAsync(new CreateUnifiedOfferRequest(
            "Alpha Formula",
            "Health",
            "Vendor Co",
            BrandBiddingAllowed: true,
            DirectLinkingAllowed: false,
            PayoutAmount: 80,
            Currency: "USD",
            CommissionType: CommissionType.OneTime,
            AffiliateUrl: "https://alpha.jzanini.hop.clickbank.net/"), CancellationToken.None);

        result.PayoutAmount.Should().Be(80);
        result.ExchangeRateUsed.Should().Be(1.38m);
        result.PayoutAmountCad.Should().Be(110.4m);
        (await db.Merchants.CountAsync()).Should().Be(1);
        (await db.Products.CountAsync()).Should().Be(1);
        var merchant = await db.Merchants.SingleAsync();
        merchant.OrganizationId.Should().Be(orgId);
        merchant.BrandBiddingAllowed.Should().BeTrue();
        var product = await db.Products.SingleAsync();
        product.Category.Should().Be("Health");
        var offer = await db.Offers.SingleAsync();
        offer.Id.Should().Be(result.OfferId);
        offer.PayoutAmountCad.Should().Be(110.4m);
        offer.ProviderKey.Should().Be("manual");
    }

    [Fact]
    public async Task Maps_provider_hop_and_affiliate_page_without_new_columns()
    {
        var (handler, db, _) = Create();
        var result = await handler.HandleAsync(new CreateUnifiedOfferRequest(
            "ProDentim",
            "Health",
            "prodentim",
            PayoutAmount: 165.68m,
            Currency: "USD",
            BaseAffiliateUrl: "https://prodentim.jzanini.hop.clickbank.net/",
            AffiliatePageUrl: "https://www.prodentim.com/affiliates",
            ProviderKey: "clickbank"), CancellationToken.None);

        var offer = await db.Offers.SingleAsync(x => x.Id == result.OfferId);
        offer.ProviderKey.Should().Be("clickbank");
        offer.AffiliateUrl.Should().Be("https://prodentim.jzanini.hop.clickbank.net/");
        offer.ExternalVendorId.Should().Be("prodentim");
        var merchant = await db.Merchants.SingleAsync();
        merchant.Name.Should().Be("prodentim");
        merchant.Website.Should().Be("https://www.prodentim.com/affiliates");
        merchant.ProviderKey.Should().Be("clickbank");
    }

    [Fact]
    public async Task Persists_pasted_traffic_restrictions()
    {
        var (handler, db, _) = Create();
        await handler.HandleAsync(new CreateUnifiedOfferRequest(
            "ProDentim",
            "Health",
            "prodentim",
            PayoutAmount: 165.68m,
            Currency: "USD",
            ProviderKey: "clickbank",
            TrafficRestrictions: "Rule 4. You may not bid on the brand name ProDentim. Negative keywords: scam, fake, reviews."),
            CancellationToken.None);

        var offer = await db.Offers.SingleAsync();
        offer.TrafficRestrictions.Should().Contain("may not bid on the brand name");
        offer.TrafficRestrictions.Should().Contain("scam, fake, reviews");
    }

    [Fact]
    public async Task Reuses_merchant_and_product_by_name()
    {
        var (handler, db, _) = Create();
        var first = await handler.HandleAsync(NewRequest("Alpha Formula", "Vendor Co"), CancellationToken.None);
        var second = await handler.HandleAsync(NewRequest("alpha formula", "vendor co"), CancellationToken.None);

        second.MerchantId.Should().Be(first.MerchantId);
        second.ProductId.Should().Be(first.ProductId);
        second.OfferId.Should().NotBe(first.OfferId);
        (await db.Merchants.CountAsync()).Should().Be(1);
        (await db.Products.CountAsync()).Should().Be(1);
        (await db.Offers.CountAsync()).Should().Be(2);
    }

    [Fact]
    public async Task Enqueues_offer_market_signals_job()
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning))
            .Options;
        var db = new AppDbContext(options);
        var jobs = new FakeJobQueue();
        var handler = new CreateUnifiedOfferCommandHandler(db, new FakeCurrentUser(orgId), new StubFx(1.38m), jobs);

        var result = await handler.HandleAsync(NewRequest("Alpha Formula", "Vendor Co"), CancellationToken.None);

        jobs.Items.Should().ContainSingle(x => x.Type == "offer-market-signals" && x.Payload == result.OfferId.ToString());
    }

    private static CreateUnifiedOfferRequest NewRequest(string product, string merchant) =>
        new(product, "Health", merchant, PayoutAmount: 50, Currency: "USD");

    private static (CreateUnifiedOfferCommandHandler Handler, AppDbContext Db, Guid OrgId) Create()
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning))
            .Options;
        var db = new AppDbContext(options);
        var handler = new CreateUnifiedOfferCommandHandler(db, new FakeCurrentUser(orgId), new StubFx(1.38m), new FakeJobQueue());
        return (handler, db, orgId);
    }
}

file sealed class FakeCurrentUser : ICurrentUser
{
    public FakeCurrentUser(Guid organizationId) => User = new CurrentUser("u1", "admin@local", organizationId, "Owner");
    public CurrentUser User { get; }
    public Guid OrganizationId => User.OrganizationId;
}

file sealed class FakeJobQueue : IJobQueue
{
    public List<(string Type, string Payload, Guid? Org)> Items { get; } = [];

    public Task EnqueueAsync(string jobType, string payloadJson, Guid? organizationId = null, CancellationToken cancellationToken = default)
    {
        Items.Add((jobType, payloadJson, organizationId));
        return Task.CompletedTask;
    }
}

file sealed class StubFx : ICurrencyExchangeService
{
    private readonly decimal _rate;
    public StubFx(decimal rate) => _rate = rate;
    public Task<decimal> GetExchangeRateAsync(string fromCurrency, string toCurrency, CancellationToken ct = default) =>
        Task.FromResult(_rate);
}
