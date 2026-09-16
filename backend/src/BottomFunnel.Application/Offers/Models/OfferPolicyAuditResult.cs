namespace BottomFunnel.Application.Offers.Models;

public enum PolicyRiskLevel
{
    Low,
    Moderate,
    Severe,
    Critical
}

public sealed record PolicyViolation(
    string PolicyCategory,
    string TriggerPhrase,
    string Explanation,
    string Severity
);

public sealed record OfferPolicyAuditResult(
    PolicyRiskLevel OverallRisk,
    int RiskScore,
    bool AllowsBrandBidding,
    List<string> MandatoryNegativeKeywords,
    List<PolicyViolation> Violations,
    List<string> ApprovedAngles,
    List<string> DisallowedAngles,
    string ExecutiveSummary
);
