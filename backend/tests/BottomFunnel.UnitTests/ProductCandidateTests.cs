using System.Text;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Services;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Infrastructure.Feeds;
using BottomFunnel.Infrastructure.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;

namespace BottomFunnel.UnitTests;

public class ClickBankMarketplaceFeedParserTests
{
    [Fact]
    public void Streams_nested_sites_without_requiring_full_dom()
    {
        const string xml = """
            <?xml version="1.0" encoding="ISO-8859-1"?>
            <Catalog>
              <Category>
                <Name>Health &amp; Fitness</Name>
                <Site>
                  <Id>prodentim</Id>
                  <Name>prodentim</Name>
                  <Title>ProDentim</Title>
                  <Description>Oral probiotic</Description>
                  <Gravity>85.4</Gravity>
                  <Url>https://www.prodentim.com/affiliates</Url>
                </Site>
                <Site>
                  <Id>lowgrav</Id>
                  <Title>Skip Me</Title>
                  <Gravity>12.0</Gravity>
                </Site>
                <Category>
                  <Name>Dental</Name>
                  <Site>
                    <Id>probioticx</Id>
                    <Title>Probiotic X</Title>
                    <Gravity>30.0</Gravity>
                  </Site>
                </Category>
              </Category>
            </Catalog>
            """;

        using var stream = new MemoryStream(Encoding.UTF8.GetBytes(xml));
        var sites = ClickBankMarketplaceFeedParser.ReadSites(stream).ToList();

        sites.Should().HaveCount(3);
        sites[0].ExternalId.Should().Be("prodentim");
        sites[0].Gravity.Should().Be(85.4m);
        sites[0].Category.Should().Be("Health & Fitness");
        sites[0].AffiliatePageUrl.Should().Contain("prodentim.com");
        sites[2].ExternalId.Should().Be("probioticx");
        sites[2].Gravity.Should().Be(30m);
        sites[2].Category.Should().Be("Health & Fitness / Dental");
    }
}

public class ClickBankFeedIngestionTests
{
    [Fact]
    public void Persists_only_gravity_at_or_above_30_and_upserts_by_vendor()
    {
        var existing = new Dictionary<string, ProductCandidate>(StringComparer.OrdinalIgnoreCase)
        {
            ["prodentim"] = new ProductCandidate
            {
                ProviderKey = "clickbank",
                ExternalId = "prodentim",
                Title = "Old Title",
                Gravity = 40m,
                Status = CandidateStatus.Qualified
            }
        };
        var added = new List<ProductCandidate>();
        var sites = new[]
        {
            new ClickBankFeedSite("prodentim", "ProDentim", "updated", 142.5m, "Health", "https://example.com"),
            new ClickBankFeedSite("alphavend", "Alpha Formula", "new", 88m, "Health", null),
            new ClickBankFeedSite("tiny", "Too Small", "", 12m, "Health", null)
        };

        var result = ClickBankFeedIngestionService.UpsertFromSites(sites, existing, added);

        result.Inserted.Should().Be(1);
        result.Updated.Should().Be(1);
        result.Skipped.Should().Be(1);
        added.Should().ContainSingle(x => x.ExternalId == "alphavend" && x.Status == CandidateStatus.PendingReview);
        existing["prodentim"].Gravity.Should().Be(142.5m);
        existing["prodentim"].Title.Should().Be("ProDentim");
        existing["prodentim"].Status.Should().Be(CandidateStatus.Qualified);
    }
}

public class ProductCandidateServiceTests
{
    [Fact]
    public async Task Filters_and_pages_candidates()
    {
        var (service, db) = Create();
        db.ProductCandidates.AddRange(
            new ProductCandidate { ProviderKey = "clickbank", ExternalId = "alpha", Title = "Alpha Formula", Gravity = 90, Status = CandidateStatus.PendingReview },
            new ProductCandidate { ProviderKey = "clickbank", ExternalId = "beta", Title = "Beta Course", Gravity = 40, Status = CandidateStatus.Qualified },
            new ProductCandidate { ProviderKey = "clickbank", ExternalId = "gamma", Title = "Gamma Kit", Gravity = 70, Status = CandidateStatus.PendingReview });
        await db.SaveChangesAsync();

        var page = await service.ListAsync(new GetProductCandidatesQuery(CandidateStatus.PendingReview, "a", 1, 10), CancellationToken.None);
        page.Total.Should().Be(2);
        page.Items.Select(x => x.ExternalId).Should().Equal("alpha", "gamma");
    }

    [Fact]
    public async Task Promote_creates_product_merchant_offer_in_cad_and_marks_promoted()
    {
        var (service, db) = Create();
        var candidate = new ProductCandidate
        {
            ProviderKey = "clickbank",
            ExternalId = "alphavend",
            Title = "Alpha Formula",
            Gravity = 88,
            Category = "Health",
            AffiliatePageUrl = "https://www.example.com/affiliates",
            Status = CandidateStatus.Qualified
        };
        db.ProductCandidates.Add(candidate);
        await db.SaveChangesAsync();

        var result = await service.PromoteAsync(
            new PromoteCandidateToOfferCommand(candidate.Id, 80, "USD"),
            new PromoteCandidateToOfferRequest(80, "USD"),
            CancellationToken.None);

        result.PayoutAmountCad.Should().Be(110.4m);
        (await db.Offers.CountAsync()).Should().Be(1);
        (await db.Merchants.SingleAsync()).Name.Should().Be("alphavend");
        (await db.Products.SingleAsync()).Name.Should().Be("Alpha Formula");
        (await db.ProductCandidates.SingleAsync()).Status.Should().Be(CandidateStatus.Promoted);
        var offer = await db.Offers.SingleAsync();
        offer.AffiliateUrl.Should().Be("https://alphavend.jzanini.hop.clickbank.net/");
        offer.Status.Should().Be(OfferStatus.Active);
    }

    [Fact]
    public void Builds_clickbank_hop_from_vendor_nickname()
    {
        ProductCandidateService.ClickBankHop("Pro Dentim!").Should().Be("https://prodentim.jzanini.hop.clickbank.net/");
    }

    private static (ProductCandidateService Service, AppDbContext Db) Create()
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning))
            .Options;
        var db = new AppDbContext(options);
        var service = new ProductCandidateService(
            db,
            new FakeCurrentUser(orgId),
            new StubFx(1.38m),
            new FakeJobQueue(),
            new FakeAi(),
            new UnusedHttp());
        return (service, db);
    }

    private sealed class FakeCurrentUser : ICurrentUser
    {
        public FakeCurrentUser(Guid organizationId) => User = new CurrentUser("u1", "admin@local", organizationId, "Owner");
        public CurrentUser User { get; }
        public Guid OrganizationId => User.OrganizationId;
    }

    private sealed class StubFx : ICurrencyExchangeService
    {
        private readonly decimal _rate;
        public StubFx(decimal rate) => _rate = rate;
        public Task<decimal> GetExchangeRateAsync(string fromCurrency, string toCurrency, CancellationToken ct = default) =>
            Task.FromResult(_rate);
    }

    private sealed class FakeJobQueue : IJobQueue
    {
        public Task EnqueueAsync(string jobType, string payloadJson, Guid? organizationId = null, CancellationToken cancellationToken = default) =>
            Task.CompletedTask;
    }

    private sealed class FakeAi : IAiService
    {
        public Task<bool> IsConfiguredAsync(CancellationToken cancellationToken = default) => Task.FromResult(false);
        public Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default) =>
            Task.FromResult("");
        public Task<BottomFunnel.Application.Offers.Models.OfferPolicyAuditResult> AuditOfferSalesPageAsync(
            string productName, string salesPageTextOrHtml, string? affiliateTermsText, string? trafficRestrictionsText = null, CancellationToken ct = default) =>
            throw new NotSupportedException();
    }

    private sealed class UnusedHttp : IHttpClientFactory
    {
        public HttpClient CreateClient(string name) => throw new NotSupportedException();
    }
}
