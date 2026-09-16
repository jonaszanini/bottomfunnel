using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;
using FluentAssertions;

namespace BottomFunnel.UnitTests;

public class OfferPolicyAuditParserTests
{
    [Fact]
    public void Parses_fenced_json_and_clamps_score()
    {
        var result = OfferPolicyAuditParser.Parse("""
            ```json
            {
              "overallRisk": "severe",
              "riskScore": 140,
              "allowsBrandBidding": false,
              "mandatoryNegativeKeywords": ["Scam", "scam", " fake "],
              "violations": [
                {
                  "policyCategory": "Misrepresentation",
                  "triggerPhrase": "cures diabetes",
                  "explanation": "Disease treatment claim.",
                  "severity": "Critical"
                }
              ],
              "approvedAngles": ["Independent ingredient review"],
              "disallowedAngles": ["Cures osteoarthritis"],
              "executiveSummary": "High risk. Do not buy traffic yet."
            }
            ```
            """);

        result.OverallRisk.Should().Be(PolicyRiskLevel.Severe);
        result.RiskScore.Should().Be(100);
        result.AllowsBrandBidding.Should().BeFalse();
        result.MandatoryNegativeKeywords.Should().Equal("Scam", "fake");
        result.Violations.Should().ContainSingle(v => v.TriggerPhrase == "cures diabetes");
        result.ExecutiveSummary.Should().Contain("High risk");
    }

    [Fact]
    public void Infers_risk_from_score_when_enum_missing()
    {
        var result = OfferPolicyAuditParser.Parse("""{"riskScore":22,"allowsBrandBidding":true}""");
        result.OverallRisk.Should().Be(PolicyRiskLevel.Low);
        result.RiskScore.Should().Be(22);
        result.MandatoryNegativeKeywords.Should().BeEmpty();
        result.Violations.Should().BeEmpty();
    }
}

public class HtmlContentSanitizerTests
{
    [Fact]
    public void Strips_scripts_styles_and_tags()
    {
        var text = HtmlContentSanitizer.ToPlainText("""
            <html><head><style>body{color:red}</style></head>
            <body>
              <script>alert(1)</script>
              <h1>Joint Comfort</h1>
              <p>Supports&nbsp;mobility.</p>
            </body></html>
            """);

        text.Should().Be("Joint Comfort Supports mobility.");
        text.Should().NotContain("<");
        text.Should().NotContain("alert");
        text.Should().NotContain("color:red");
    }
}

public class OfferTrafficRulesEnforcerTests
{
    [Fact]
    public void ProDentim_rule_4_bans_brand_ppc_and_extracts_negatives()
    {
        const string rules = """
            Rule 4. You may not bid on the brand name "ProDentim" or close variants.
            Required negative keywords: scam, fake, reviews, complaint, lawsuit.
            """;

        OfferTrafficRulesEnforcer.BansBrandBidding(rules).Should().BeTrue();
        var applied = OfferTrafficRulesEnforcer.Apply(
            new OfferPolicyAuditResult(PolicyRiskLevel.Low, 12, true, [], [], [], [], "Looks fine."),
            rules,
            "ProDentim");

        applied.AllowsBrandBidding.Should().BeFalse();
        applied.MandatoryNegativeKeywords.Should().Contain(["ProDentim", "scam", "fake", "reviews", "complaint", "lawsuit"]);
        applied.ExecutiveSummary.Should().StartWith("Brand-name PPC is banned by the seller");
        applied.DisallowedAngles.Should().Contain(x => x.Contains("Brand-name PPC"));
    }
}
