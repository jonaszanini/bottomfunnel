using BottomFunnel.Domain.Compliance;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Keywords;
using BottomFunnel.Domain.Scoring;
using BottomFunnel.Infrastructure.Stores.ClickBank;
using BottomFunnel.Infrastructure.Stores.Digistore24;
using FluentAssertions;

namespace BottomFunnel.UnitTests;

public class ProfitabilityCalculatorTests
{
    [Fact]
    public void Expected_revenue_and_profit_per_click()
    {
        var result = ProfitabilityCalculator.Calculate(new ProfitInputs(80m, 0.04m, 1.50m));
        result.ExpectedRevenuePerClick.Should().Be(3.20m);
        result.ExpectedProfitPerClick.Should().Be(1.70m);
    }

    [Fact]
    public void Contribution_profit_and_roi()
    {
        var profit = ProfitabilityCalculator.ContributionProfit(12480m, 7210m, 0);
        profit.Should().Be(5270m);
        ProfitabilityCalculator.RoiPercent(profit, 7210m).Should().BeApproximately(73.0929m, 0.01m);
    }
}

public class OpportunityScoringTests
{
    [Fact]
    public void High_intent_profitable_keyword_is_test()
    {
        var result = ScoreHappyPath();

        result.ExpectedProfitPerClick.Should().Be(1.70m);
        result.Recommendation.Should().Be(OpportunityRecommendation.Test);
        result.CommercialScore.Should().BeGreaterThanOrEqualTo(70);
        result.ViabilityScore.Should().BeGreaterThanOrEqualTo(50);
        result.Score.Should().BeGreaterThanOrEqualTo(50);
    }

    [Fact]
    public void Negative_epc_is_skip()
    {
        var result = OpportunityScoring.Score(new OpportunityScoreInputs(
            90, 10m, 0.01m, 5m, 0, CompetitionLevel.High,
            SearchVolume: 8000, VolumeConfidence: DataConfidence.High,
            LowVolumeRisk: LowSearchVolumeRisk.Low,
            ComplianceState: ComplianceState.Verified));
        result.ExpectedProfitPerClick.Should().BeNegative();
        result.Recommendation.Should().Be(OpportunityRecommendation.Skip);
    }

    [Fact]
    public void Zero_conversion_rate_is_skip()
    {
        var result = OpportunityScoring.Score(new OpportunityScoreInputs(
            100, 80m, 0m, 1.50m, 0, CompetitionLevel.Low,
            SearchVolume: 8000, VolumeConfidence: DataConfidence.High,
            LowVolumeRisk: LowSearchVolumeRisk.Low,
            ComplianceState: ComplianceState.Verified));
        result.ExpectedProfitPerClick.Should().BeNegative();
        result.Recommendation.Should().Be(OpportunityRecommendation.Skip);
        result.Why.Should().Contain("unprofitable");
    }

    [Fact]
    public void Extreme_cpc_is_skip()
    {
        var result = OpportunityScoring.Score(new OpportunityScoreInputs(
            100, 40m, 0.04m, 25m, 0, CompetitionLevel.High,
            SearchVolume: 8000, VolumeConfidence: DataConfidence.High,
            LowVolumeRisk: LowSearchVolumeRisk.Low,
            ComplianceState: ComplianceState.Verified));
        result.ExpectedProfitPerClick.Should().BeNegative();
        result.Recommendation.Should().Be(OpportunityRecommendation.Skip);
    }

    [Fact]
    public void Low_volume_is_skip_even_when_intent_is_high()
    {
        var result = OpportunityScoring.Score(new OpportunityScoreInputs(
            100, 80m, 0.04m, 1.50m, 0, CompetitionLevel.Low,
            SearchVolume: 3, VolumeConfidence: DataConfidence.High,
            LowVolumeRisk: LowSearchVolumeRisk.High,
            ComplianceState: ComplianceState.Verified));
        result.Recommendation.Should().Be(OpportunityRecommendation.Skip);
        result.ViabilityScore.Should().BeLessThan(40);
        result.Why.Should().Contain("too low");
    }

    [Fact]
    public void Brand_prohibited_keyword_is_skip_not_test()
    {
        var result = OpportunityScoring.Score(new OpportunityScoreInputs(
            100, 80m, 0.04m, 1.50m, 0, CompetitionLevel.Low,
            SearchVolume: 8000, VolumeConfidence: DataConfidence.High,
            LowVolumeRisk: LowSearchVolumeRisk.Low,
            ComplianceState: ComplianceState.Prohibited,
            KeywordBlocked: true));
        result.Recommendation.Should().Be(OpportunityRecommendation.Skip);
        result.ComplianceScore.Should().Be(0);
        result.Score.Should().Be(0);
    }

    [Fact]
    public void Forecast_range_is_sensitive_to_cpc_and_conversion_bands()
    {
        var result = ScoreHappyPath();
        result.ExpectedProfitConservative.Should().BeLessThan(result.ExpectedProfitBase);
        result.ExpectedProfitBase.Should().BeLessThan(result.ExpectedProfitOptimistic);
        result.ExpectedProfitBase.Should().Be(result.ExpectedProfitPerClick);
    }

    [Fact]
    public void Unknown_compliance_is_investigate()
    {
        OpportunityScoring.Recommend(80, 80, 40, LowSearchVolumeRisk.Low, 1.70m, false, ComplianceState.Unknown)
            .Should().Be(OpportunityRecommendation.Investigate);
    }

    private static OpportunityScoreResult ScoreHappyPath() =>
        OpportunityScoring.Score(new OpportunityScoreInputs(
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
            ConversionConfidence: DataConfidence.Medium));
}

public class KeywordTemplateTests
{
    [Fact]
    public void Generates_bottom_of_funnel_candidates()
    {
        var list = KeywordTemplateCatalog.Generate("Product X");
        list.Should().Contain(x => x.Phrase == "buy Product X" && x.IntentScore == 100);
        list.Should().Contain(x => x.Phrase == "Product X coupon" && x.IntentScore == 90);
        list.Should().HaveCount(9);
    }

    [Fact]
    public void Classifies_transactional_phrases()
    {
        KeywordIntentClassifier.Classify("buy Product X").Score.Should().Be(100);
        KeywordIntentClassifier.Classify("what is Product X").Score.Should().Be(30);
        KeywordIntentClassifier.Classify("Product X reviews").Score.Should().Be(75);
    }

    [Fact]
    public void Brand_prohibition_blocks_brand_templates_but_keeps_alternatives()
    {
        var policy = new OfferPolicy(true, false, false, false, false);
        var list = KeywordTemplateCatalog.Generate("Product X", policy, ComplianceState.Restricted);

        list.Should().Contain(x => x.Template == KeywordTemplateKind.Buy && x.Blocked && x.BlockedReason != null);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Official && x.Blocked);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Coupon && x.Blocked);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Alternative && !x.Blocked);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Versus && !x.Blocked);
    }

    [Fact]
    public void Brand_plus_modifier_allows_official_but_not_core_brand()
    {
        var policy = new OfferPolicy(true, false, true, false, false);
        var list = KeywordTemplateCatalog.Generate("Product X", policy, ComplianceState.Verified);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Official && !x.Blocked);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Product && x.Blocked);
        list.Should().Contain(x => x.Template == KeywordTemplateKind.Alternative && !x.Blocked);
    }

    [Fact]
    public void Ppc_prohibition_blocks_every_template()
    {
        var policy = new OfferPolicy(false, true, true, true, true);
        KeywordTemplateCatalog.Generate("Product X", policy, ComplianceState.Prohibited)
            .Should().OnlyContain(x => x.Blocked && x.ComplianceStatus == ComplianceState.Prohibited);
    }
}

public class LandingPageCompletenessTests
{
    [Fact]
    public void Publish_requires_disclosure_body_and_a_utility_section()
    {
        var page = new LandingPage { Headline = "Title" };
        LandingPageCompleteness.CanPublish(page, out var reason).Should().BeFalse();
        reason.Should().Contain("disclosure");

        page.Disclosure = "I may earn a commission.";
        page.Body = "Original evaluation of the offer.";
        LandingPageCompleteness.CanPublish(page, out reason).Should().BeFalse();
        reason.Should().Contain("non-CTA");

        page.WhoItsFor = "People comparing checkout options.";
        LandingPageCompleteness.CanPublish(page, out reason).Should().BeTrue();
        reason.Should().BeNull();
    }
}

public class StoreConnectorMappingTests
{
    [Fact]
    public void Maps_clickbank_product_list()
    {
        const string json = """
        {
          "products": {
            "product": {
              "sku": "1",
              "title": "Alpha Formula",
              "site": "alphavend",
              "status": "ACTIVE",
              "commission": { "purchase": 75.5, "rebill": 40 },
              "digitalRecurring": true
            }
          }
        }
        """;

        var products = ClickBankConnector.MapProducts(json);
        products.Should().ContainSingle();
        products[0].Name.Should().Be("Alpha Formula");
        products[0].Commission.Should().Be(75.5m);
        products[0].CommissionType.Should().Be(CommissionType.Recurring);
        products[0].VendorId.Should().Be("alphavend");
    }

    [Fact]
    public void Maps_digistore24_product_list()
    {
        const string json = """
        {
          "result": "success",
          "data": [
            {
              "product_id": "12345",
              "name": "Beta Course",
              "price": 97,
              "affiliate_commission": 40,
              "currency": "EUR"
            }
          ]
        }
        """;

        var products = Digistore24Connector.MapProducts(json);
        products.Should().ContainSingle();
        products[0].ExternalProductId.Should().Be("12345");
        products[0].Commission.Should().Be(40);
        products[0].Currency.Should().Be("EUR");
    }
}
