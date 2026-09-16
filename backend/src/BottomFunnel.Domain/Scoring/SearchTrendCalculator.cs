using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Scoring;

public static class SearchTrendCalculator
{
    public const decimal RisingThreshold = 15m;
    public const decimal DecliningThreshold = -15m;

    public static (decimal ChangePercent, SearchTrendDirection Direction) FromMonths(int monthMinus3, int monthMinus2, int monthMinus1)
    {
        _ = monthMinus2;
        if (monthMinus3 <= 0)
        {
            if (monthMinus1 <= 0)
                return (0, SearchTrendDirection.Unknown);
            return (100, SearchTrendDirection.Rising);
        }

        var delta = (monthMinus1 - monthMinus3) / (decimal)monthMinus3 * 100m;
        delta = Math.Round(delta, 4, MidpointRounding.AwayFromZero);
        var direction = delta > RisingThreshold
            ? SearchTrendDirection.Rising
            : delta < DecliningThreshold
                ? SearchTrendDirection.Declining
                : SearchTrendDirection.Stable;
        return (delta, direction);
    }
}

public static class RefundRiskCalculator
{
    public static RefundRiskLevel From(decimal? trustpilotRating, int totalReviews)
    {
        if (trustpilotRating is null || totalReviews <= 0)
            return RefundRiskLevel.Low;
        if (trustpilotRating < 2.0m && totalReviews > 50)
            return RefundRiskLevel.Extreme;
        if (trustpilotRating < 2.5m && totalReviews > 50)
            return RefundRiskLevel.High;
        if (trustpilotRating < 3.2m && totalReviews > 20)
            return RefundRiskLevel.Moderate;
        return RefundRiskLevel.Low;
    }

    public static int ScorePenalty(RefundRiskLevel risk) => risk switch
    {
        RefundRiskLevel.Moderate => 5,
        RefundRiskLevel.High => 12,
        RefundRiskLevel.Extreme => 20,
        _ => 0
    };
}
