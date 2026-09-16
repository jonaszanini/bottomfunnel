using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Scoring;

/// <summary>
/// Scores opportunities using CAD economics. Pass <see cref="OpportunityScoreInputs.Commission"/> as PayoutAmountCad.
/// Rising search demand adds 10 points; declining demand subtracts 25. Refund risk applies an extra penalty.
/// </summary>
public static class OpportunityScoringEngine
{
    public const int RisingBonus = 10;
    public const int DecliningPenalty = 25;

    public static OpportunityScoreResult Score(OpportunityScoreInputs inputs) =>
        Score(inputs, trend: null, reputation: null);

    public static OpportunityScoreResult Score(
        OpportunityScoreInputs inputs,
        OfferSearchTrend? trend,
        OfferReputation? reputation)
    {
        var result = OpportunityScoring.Score(inputs);
        if (result.Score == 0)
            return AppendWhy(result, trend, reputation, appliedDelta: 0);

        var delta = TrendDelta(trend) - RefundRiskCalculator.ScorePenalty(reputation?.CalculatedRefundRisk ?? RefundRiskLevel.Low);
        var score = Math.Clamp(result.Score + delta, 0, 100);
        return AppendWhy(result with { Score = score }, trend, reputation, delta);
    }

    public static int TrendDelta(OfferSearchTrend? trend) => trend?.Direction switch
    {
        SearchTrendDirection.Rising => RisingBonus,
        SearchTrendDirection.Declining => -DecliningPenalty,
        _ => 0
    };

    private static OpportunityScoreResult AppendWhy(
        OpportunityScoreResult result,
        OfferSearchTrend? trend,
        OfferReputation? reputation,
        int appliedDelta)
    {
        var extra = new List<string>();
        if (trend?.Direction == SearchTrendDirection.Rising)
            extra.Add($"✓ Search demand is rising over 90 days ({trend.NinetyDayChangePercent:0.0}%, +{RisingBonus}).");
        else if (trend?.Direction == SearchTrendDirection.Declining)
            extra.Add($"⚠ Search demand is declining over 90 days ({trend.NinetyDayChangePercent:0.0}%, −{DecliningPenalty}).");
        else if (trend?.Direction == SearchTrendDirection.Stable)
            extra.Add("• Search demand is stable over 90 days.");

        if (reputation is { CalculatedRefundRisk: RefundRiskLevel.High or RefundRiskLevel.Extreme })
            extra.Add($"⚠ Trustpilot refund risk is {reputation.CalculatedRefundRisk} (rating {reputation.TrustpilotRating:0.0}/5 from {reputation.TotalReviewsCount} reviews).");
        else if (reputation?.TrustpilotRating is { } rating)
            extra.Add($"• Trustpilot {rating:0.0}/5 from {reputation.TotalReviewsCount} reviews.");

        if (extra.Count == 0)
            return result;
        if (appliedDelta != 0)
            extra.Add($"Market-signal adjustment: {(appliedDelta > 0 ? "+" : "")}{appliedDelta} to opportunity score.");
        return result with { Why = result.Why + "\n" + string.Join('\n', extra) };
    }
}
