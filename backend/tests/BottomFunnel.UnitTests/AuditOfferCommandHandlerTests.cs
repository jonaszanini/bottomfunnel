using System.Net;
using System.Net.Http.Headers;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;
using BottomFunnel.Application.Services;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Infrastructure.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;

namespace BottomFunnel.UnitTests;

public class AuditOfferCommandHandlerTests
{
    [Fact]
    public async Task Persists_audit_and_disables_brand_bidding_when_vendor_forbids_it()
    {
        var (handler, db, offer, ai) = Create();
        ai.Result = Sample(allowsBrandBidding: false, negatives: ["scam", "fake"]);

        var result = await handler.HandleAsync(new AuditOfferCommand(offer.Id), CancellationToken.None);

        result.RiskScore.Should().Be(72);
        var stored = await db.OfferAudits.SingleAsync();
        stored.OfferId.Should().Be(offer.Id);
        stored.RiskScore.Should().Be(72);
        stored.AllowsBrandBidding.Should().BeFalse();
        stored.ResultJson.Should().Contain("executiveSummary");
        (await db.Offers.SingleAsync()).BrandBiddingAllowed.Should().BeFalse();
        ai.LastProductName.Should().Be("Alpha Formula");
        ai.LastSalesPage.Should().Contain("Joint Comfort");
        ai.LastTerms.Should().Contain("Do not bid on brand");
    }

    [Fact]
    public async Task Passes_traffic_restrictions_and_forces_brand_ban_from_seller_rules()
    {
        var (handler, db, offer, ai) = Create();
        offer.TrafficRestrictions = """
            Rule 4. You may not bid on the brand name "ProDentim" or close variants.
            Required negative keywords: scam, fake, reviews, complaint, lawsuit.
            """;
        await db.SaveChangesAsync();
        ai.Result = Sample(allowsBrandBidding: true, negatives: []);

        var result = await handler.HandleAsync(new AuditOfferCommand(offer.Id), CancellationToken.None);

        result.AllowsBrandBidding.Should().BeFalse();
        result.MandatoryNegativeKeywords.Should().Contain("scam");
        result.MandatoryNegativeKeywords.Should().Contain("ProDentim");
        result.MandatoryNegativeKeywords.Should().Contain("reviews");
        result.ExecutiveSummary.Should().Contain("Brand-name PPC is banned by the seller");
        (await db.Offers.SingleAsync()).BrandBiddingAllowed.Should().BeFalse();
        ai.LastTrafficRestrictions.Should().Contain("Rule 4");
    }

    [Fact]
    public async Task Throws_when_ai_is_not_configured()
    {
        var (handler, _, offer, ai) = Create();
        ai.Configured = false;

        var act = () => handler.HandleAsync(new AuditOfferCommand(offer.Id), CancellationToken.None);

        await act.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*Configure an AI provider*");
    }

    [Fact]
    public async Task Add_negatives_creates_prohibited_keywords()
    {
        var (handler, db, offer, _) = Create();

        var added = await handler.AddNegativesAsync(
            new AddAuditNegativesCommand(offer.Id, ["scam", "SCAM", "fake"]),
            CancellationToken.None);

        added.Should().Be(2);
        var keywords = await db.Keywords.ToListAsync();
        keywords.Should().HaveCount(2);
        keywords.Should().OnlyContain(x => x.Template == KeywordTemplateKind.Negative);
        keywords.Should().OnlyContain(x => x.ComplianceStatus == ComplianceState.Prohibited);
        keywords.Should().OnlyContain(x => x.BlockedReason == "AI policy audit negative keyword");
    }

    private static (AuditOfferCommandHandler Handler, AppDbContext Db, Offer Offer, FakeAi Ai) Create()
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning))
            .Options;
        var db = new AppDbContext(options);
        var product = new Product { OrganizationId = orgId, Name = "Alpha Formula", Category = "Health" };
        var merchant = new Merchant
        {
            OrganizationId = orgId,
            Name = "Vendor Co",
            Website = "https://vendor.example/affiliates",
            TrademarkNotes = "Do not bid on brand"
        };
        var offer = new Offer
        {
            OrganizationId = orgId,
            Product = product,
            Merchant = merchant,
            Name = "Alpha Formula",
            AffiliateUrl = "https://vendor.example/sales",
            BrandBiddingAllowed = true,
            Currency = "USD"
        };
        db.Products.Add(product);
        db.Merchants.Add(merchant);
        db.Offers.Add(offer);
        db.SaveChanges();

        var ai = new FakeAi();
        var http = new StubHttpFactory(new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["https://vendor.example/sales"] = "<html><script>track()</script><h1>Joint Comfort</h1></html>",
            ["https://vendor.example/affiliates"] = "<p>Affiliate terms. Negative keywords: scam.</p>"
        });
        var handler = new AuditOfferCommandHandler(db, new FakeCurrentUser(orgId), ai, http);
        return (handler, db, offer, ai);
    }

    private static OfferPolicyAuditResult Sample(bool allowsBrandBidding, string[] negatives) =>
        new(PolicyRiskLevel.Severe, 72, allowsBrandBidding, [.. negatives],
            [new PolicyViolation("Healthcare", "cures arthritis", "Disease claim.", "Critical")],
            ["Independent ingredient review"],
            ["Cures osteoarthritis"],
            "Severe healthcare claims. Do not buy traffic.");

    private sealed class FakeAi : IAiService
    {
        public bool Configured { get; set; } = true;
        public OfferPolicyAuditResult Result { get; set; } = new(
            PolicyRiskLevel.Low, 10, true, [], [], [], [], "Low risk.");
        public string? LastProductName { get; private set; }
        public string? LastSalesPage { get; private set; }
        public string? LastTerms { get; private set; }
        public string? LastTrafficRestrictions { get; private set; }

        public Task<bool> IsConfiguredAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(Configured);

        public Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default) =>
            Task.FromResult("ok");

        public Task<OfferPolicyAuditResult> AuditOfferSalesPageAsync(
            string productName,
            string salesPageTextOrHtml,
            string? affiliateTermsText,
            string? trafficRestrictionsText = null,
            CancellationToken ct = default)
        {
            LastProductName = productName;
            LastSalesPage = salesPageTextOrHtml;
            LastTerms = affiliateTermsText;
            LastTrafficRestrictions = trafficRestrictionsText;
            return Task.FromResult(Result);
        }
    }
}

file sealed class FakeCurrentUser : ICurrentUser
{
    public FakeCurrentUser(Guid organizationId) => User = new CurrentUser("u1", "admin@local", organizationId, "Owner");
    public CurrentUser User { get; }
    public Guid OrganizationId => User.OrganizationId;
}

file sealed class StubHttpFactory : IHttpClientFactory
{
    private readonly HttpClient _client;

    public StubHttpFactory(Dictionary<string, string> pages) =>
        _client = new HttpClient(new StubHandler(pages)) { Timeout = TimeSpan.FromSeconds(5) };

    public HttpClient CreateClient(string name) => _client;
}

file sealed class StubHandler : HttpMessageHandler
{
    private readonly Dictionary<string, string> _pages;

    public StubHandler(Dictionary<string, string> pages) => _pages = pages;

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var url = request.RequestUri?.ToString() ?? "";
        if (!_pages.TryGetValue(url.TrimEnd('/'), out var html) && !_pages.TryGetValue(url, out html))
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.NotFound));
        return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(html, new MediaTypeHeaderValue("text/html"))
        });
    }
}
