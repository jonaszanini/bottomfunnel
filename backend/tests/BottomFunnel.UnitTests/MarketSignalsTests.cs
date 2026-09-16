using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Services;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Scoring;
using BottomFunnel.Infrastructure.MarketSignals;
using BottomFunnel.Infrastructure.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;

namespace BottomFunnel.UnitTests;

public class SearchTrendCalculatorTests
{
    [Fact]
    public void Rising_when_velocity_exceeds_15_percent()
    {
        var (delta, direction) = SearchTrendCalculator.FromMonths(100, 120, 140);
        delta.Should().Be(40m);
        direction.Should().Be(SearchTrendDirection.Rising);
    }

    [Fact]
    public void Stable_within_plus_or_minus_15_percent()
    {
        var (delta, direction) = SearchTrendCalculator.FromMonths(100, 105, 110);
        delta.Should().Be(10m);
        direction.Should().Be(SearchTrendDirection.Stable);
    }

    [Fact]
    public void Declining_when_velocity_is_below_minus_15_percent()
    {
        var (delta, direction) = SearchTrendCalculator.FromMonths(200, 150, 100);
        delta.Should().Be(-50m);
        direction.Should().Be(SearchTrendDirection.Declining);
    }
}

public class RefundRiskCalculatorTests
{
    [Fact]
    public void High_when_rating_below_2_5_and_reviews_exceed_50()
    {
        RefundRiskCalculator.From(2.4m, 51).Should().Be(RefundRiskLevel.High);
    }

    [Fact]
    public void Extreme_when_rating_below_2_and_reviews_exceed_50()
    {
        RefundRiskCalculator.From(1.9m, 80).Should().Be(RefundRiskLevel.Extreme);
    }

    [Fact]
    public void Low_when_reviews_are_sparse()
    {
        RefundRiskCalculator.From(1.2m, 10).Should().Be(RefundRiskLevel.Low);
    }
}

public class OpportunityScoringEngineMarketSignalTests
{
    [Fact]
    public void Rising_demand_adds_10_points()
    {
        var baseline = OpportunityScoringEngine.Score(HappyPath());
        var rising = OpportunityScoringEngine.Score(HappyPath(), new OfferSearchTrend
        {
            Direction = SearchTrendDirection.Rising,
            NinetyDayChangePercent = 40
        }, null);

        baseline.Score.Should().BeGreaterThan(0);
        rising.Score.Should().Be(Math.Clamp(baseline.Score + 10, 0, 100));
        rising.Why.Should().Contain("rising");
    }

    [Fact]
    public void Declining_demand_subtracts_25_points()
    {
        var baseline = OpportunityScoringEngine.Score(HappyPath());
        var declining = OpportunityScoringEngine.Score(HappyPath(), new OfferSearchTrend
        {
            Direction = SearchTrendDirection.Declining,
            NinetyDayChangePercent = -40
        }, null);

        declining.Score.Should().Be(Math.Clamp(baseline.Score - 25, 0, 100));
        declining.Why.Should().Contain("declining");
    }

    [Fact]
    public void Extreme_refund_risk_applies_penalty_on_top_of_momentum()
    {
        var baseline = OpportunityScoringEngine.Score(HappyPath());
        var scored = OpportunityScoringEngine.Score(
            HappyPath(),
            new OfferSearchTrend { Direction = SearchTrendDirection.Rising, NinetyDayChangePercent = 30 },
            new OfferReputation
            {
                TrustpilotRating = 1.8m,
                TotalReviewsCount = 120,
                CalculatedRefundRisk = RefundRiskLevel.Extreme
            });

        scored.Score.Should().Be(Math.Clamp(baseline.Score + 10 - 20, 0, 100));
        scored.Why.Should().Contain("refund risk");
    }

    [Fact]
    public void Blocked_keywords_stay_at_zero()
    {
        var inputs = HappyPath() with { KeywordBlocked = true, ComplianceState = ComplianceState.Prohibited };
        var scored = OpportunityScoringEngine.Score(inputs, new OfferSearchTrend
        {
            Direction = SearchTrendDirection.Rising,
            NinetyDayChangePercent = 80
        }, null);
        scored.Score.Should().Be(0);
    }

    private static OpportunityScoreInputs HappyPath() => new(
        IntentScore: 100,
        Commission: 80,
        EstimatedConversionRate: 0.04m,
        Cpc: 1.50m,
        OtherCostsPerClick: 0,
        Competition: CompetitionLevel.Medium,
        SearchVolume: 2500,
        VolumeConfidence: DataConfidence.High,
        LowVolumeRisk: LowSearchVolumeRisk.Low,
        ComplianceState: ComplianceState.Verified,
        ConversionConfidence: DataConfidence.Medium);
}

public class SearchVolumeServiceTests
{
    [Fact]
    public void FromVolumes_uses_last_three_months_for_velocity()
    {
        var trend = SearchVolumeService.FromVolumes([10, 80, 100, 160]);
        trend.CurrentMonthlyVolume.Should().Be(160);
        trend.NinetyDayChangePercent.Should().Be(100m);
        trend.Direction.Should().Be(SearchTrendDirection.Rising);
        trend.VolumeHistoryJson.Should().Be("[80,100,160]");
    }

    [Fact]
    public void FromVolumes_is_unknown_when_all_zero()
    {
        var trend = SearchVolumeService.FromVolumes([]);
        trend.Direction.Should().Be(SearchTrendDirection.Unknown);
        trend.CurrentMonthlyVolume.Should().Be(0);
    }

    [Fact]
    public void Google_monthly_volumes_are_sorted_chronologically()
    {
        using var doc = JsonDocument.Parse("""
            {
              "results": [{
                "keywordMetrics": {
                  "monthlySearchVolumes": [
                    { "year": 2026, "month": "MARCH", "monthlySearches": 300 },
                    { "year": 2026, "month": "JANUARY", "monthlySearches": 100 },
                    { "year": 2026, "month": "FEBRUARY", "monthlySearches": 200 }
                  ]
                }
              }]
            }
            """);
        SearchVolumeService.ReadGoogleMonthly(doc.RootElement).Should().Equal(100, 200, 300);
    }

    [Fact]
    public void DataForSeo_monthly_volumes_are_sorted_chronologically()
    {
        using var doc = JsonDocument.Parse("""
            {
              "tasks": [{
                "result": [{
                  "monthly_searches": [
                    { "year": 2026, "month": 3, "search_volume": 90 },
                    { "year": 2026, "month": 1, "search_volume": 200 },
                    { "year": 2026, "month": 2, "search_volume": 150 }
                  ]
                }]
              }]
            }
            """);
        SearchVolumeService.ReadDataForSeoMonthly(doc.RootElement).Should().Equal(200, 150, 90);
    }
}

public class TrustpilotHtmlParserTests
{
    [Fact]
    public void Reads_aggregate_rating_from_ld_json()
    {
        const string html = """
            <html><head>
            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "LocalBusiness",
              "aggregateRating": {
                "@type": "AggregateRating",
                "ratingValue": "2.1",
                "reviewCount": "128"
              },
              "review": [
                { "@type": "Review", "reviewBody": "Denied my 60 day refund after two emails.", "reviewRating": { "ratingValue": 1 } },
                { "@type": "Review", "reviewBody": "Shipped three weeks late with no tracking.", "reviewRating": { "ratingValue": 1 } }
              ]
            }
            </script>
            </head></html>
            """;

        var snapshot = TrustpilotHtmlParser.Parse(html, "https://www.trustpilot.com/review/example.com");
        snapshot.Rating.Should().Be(2.1m);
        snapshot.ReviewCount.Should().Be(128);
        snapshot.OneStarSnippets.Should().HaveCount(2);
        snapshot.OneStarSnippets[0].Should().Contain("refund");
    }
}

public class TrustpilotReputationServiceTests
{
    [Fact]
    public void ParseComplaints_reads_json_object()
    {
        var (complaints, summary) = TrustpilotReputationService.ParseComplaints("""
            Here you go
            {"complaints":["delayed shipping","denied refund"],"summary":"Refunds are the main friction."}
            """);
        complaints.Should().Equal("delayed shipping", "denied refund");
        summary.Should().Contain("Refunds");
    }

    [Fact]
    public void Slug_uses_host_from_url()
    {
        TrustpilotReputationService.ToTrustpilotSlug("https://www.prodentim.com/affiliates")
            .Should().Be("www.prodentim.com");
    }
}

public class OfferMarketSignalsServiceTests
{
    [Fact]
    public void BrandQuery_skips_clickbank_hop_hosts()
    {
        var offer = new Offer
        {
            Name = "ProDentim",
            Merchant = new Merchant { Name = "prodentim", Website = "https://prodentim.jzanini.hop.clickbank.net/" },
            Product = new Product { Name = "ProDentim" }
        };
        OfferMarketSignalsService.BrandQuery(offer).Should().Be("prodentim");
    }

    [Fact]
    public async Task RefreshAsync_upserts_trend_and_reputation()
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning))
            .Options;
        var db = new AppDbContext(options);
        var product = new Product { OrganizationId = orgId, Name = "Alpha Formula" };
        var merchant = new Merchant { OrganizationId = orgId, Name = "alphaco", Website = "https://alphaco.com" };
        var offer = new Offer
        {
            OrganizationId = orgId,
            Name = "Alpha Formula",
            Product = product,
            Merchant = merchant,
            Commission = 80
        };
        db.Products.Add(product);
        db.Merchants.Add(merchant);
        db.Offers.Add(offer);
        await db.SaveChangesAsync();

        var service = new OfferMarketSignalsService(
            db,
            new FakeCurrentUser(orgId),
            new StubSearch(),
            new StubReputation());

        var (trend, reputation) = await service.RefreshAsync(offer.Id, CancellationToken.None);

        trend.Direction.Should().Be(SearchTrendDirection.Rising);
        trend.NinetyDayChangePercent.Should().Be(50);
        reputation.TrustpilotRating.Should().Be(4.2m);
        reputation.CalculatedRefundRisk.Should().Be(RefundRiskLevel.Low);
        (await db.OfferSearchTrends.CountAsync()).Should().Be(1);
        (await db.OfferReputations.CountAsync()).Should().Be(1);
    }

    private sealed class FakeCurrentUser : ICurrentUser
    {
        public FakeCurrentUser(Guid organizationId) => User = new CurrentUser("u1", "admin@local", organizationId, "Owner");
        public CurrentUser User { get; }
        public Guid OrganizationId => User.OrganizationId;
    }

    private sealed class StubSearch : ISearchVolumeService
    {
        public Task<OfferSearchTrend> FetchNinetyDayTrendAsync(string keyword, CancellationToken ct = default) =>
            Task.FromResult(new OfferSearchTrend
            {
                CurrentMonthlyVolume = 150,
                NinetyDayChangePercent = 50,
                Direction = SearchTrendDirection.Rising,
                VolumeHistoryJson = "[100,120,150]"
            });
    }

    private sealed class StubReputation : IReputationAuditService
    {
        public Task<OfferReputation> AuditTrustpilotAsync(string domainOrBrand, CancellationToken ct = default) =>
            Task.FromResult(new OfferReputation
            {
                TrustpilotRating = 4.2m,
                TotalReviewsCount = 88,
                TrustpilotUrl = "https://www.trustpilot.com/review/alphaco.com",
                CalculatedRefundRisk = RefundRiskLevel.Low,
                RecurringComplaintsJson = "[\"delayed shipping\"]",
                SentimentSummary = "Mostly shipping delays."
            });
    }
}
