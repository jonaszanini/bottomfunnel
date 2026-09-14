using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Scoring;

public sealed record ProfitInputs(
    decimal Commission,
    decimal EstimatedConversionRate,
    decimal Cpc,
    decimal OtherCostsPerClick = 0);

public sealed record ProfitResult(
    decimal ExpectedRevenuePerClick,
    decimal ExpectedProfitPerClick);

public sealed record OpportunityScoreInputs(
    int IntentScore,
    decimal Commission,
    decimal EstimatedConversionRate,
    decimal Cpc,
    decimal OtherCostsPerClick,
    CompetitionLevel Competition,
    long? SearchVolume = null,
    DataConfidence VolumeConfidence = DataConfidence.Low,
    LowSearchVolumeRisk LowVolumeRisk = LowSearchVolumeRisk.Unknown,
    ComplianceState ComplianceState = ComplianceState.Unknown,
    bool KeywordBlocked = false,
    DataConfidence ConversionConfidence = DataConfidence.Low);

public sealed record OpportunityScoreResult(
    int Score,
    int CommercialScore,
    int ViabilityScore,
    int ComplianceScore,
    int Confidence,
    decimal ExpectedRevenuePerClick,
    decimal ExpectedProfitPerClick,
    decimal ExpectedProfitConservative,
    decimal ExpectedProfitBase,
    decimal ExpectedProfitOptimistic,
    OpportunityRecommendation Recommendation,
    string Why);

public static class ProfitabilityCalculator
{
    public static ProfitResult Calculate(ProfitInputs inputs)
    {
        var conversionRate = Clamp(inputs.EstimatedConversionRate, 0, 1);
        var expectedRevenue = RoundMoney(conversionRate * inputs.Commission);
        var expectedProfit = RoundMoney(expectedRevenue - inputs.Cpc - inputs.OtherCostsPerClick);
        return new ProfitResult(expectedRevenue, expectedProfit);
    }

    public static decimal ContributionProfit(decimal affiliateRevenue, decimal adSpend, decimal otherCosts) =>
        RoundMoney(affiliateRevenue - adSpend - otherCosts);

    public static decimal RoiPercent(decimal profit, decimal adSpend) =>
        adSpend == 0 ? 0 : RoundMoney(profit / adSpend * 100);

    public static decimal RoundMoney(decimal value) => Math.Round(value, 4, MidpointRounding.AwayFromZero);

    public static decimal Clamp(decimal value, decimal min, decimal max) =>
        Math.Min(max, Math.Max(min, value));
}

public static class OpportunityScoring
{
    public static OpportunityScoreResult Score(OpportunityScoreInputs inputs)
    {
        var baseProfit = ProfitabilityCalculator.Calculate(new ProfitInputs(
            inputs.Commission, inputs.EstimatedConversionRate, inputs.Cpc, inputs.OtherCostsPerClick));

        var conservative = ProfitabilityCalculator.Calculate(new ProfitInputs(
            inputs.Commission,
            ProfitabilityCalculator.Clamp(inputs.EstimatedConversionRate * 0.5m, 0, 1),
            inputs.Cpc * 1.22m,
            inputs.OtherCostsPerClick));

        var optimistic = ProfitabilityCalculator.Calculate(new ProfitInputs(
            inputs.Commission,
            ProfitabilityCalculator.Clamp(inputs.EstimatedConversionRate * 1.25m, 0, 1),
            Math.Max(0.01m, inputs.Cpc * 0.83m),
            inputs.OtherCostsPerClick));

        var commercial = CommercialScore(inputs.IntentScore, baseProfit.ExpectedProfitPerClick, inputs.Cpc, inputs.EstimatedConversionRate);
        var volumeRisk = ResolveVolumeRisk(inputs.SearchVolume, inputs.LowVolumeRisk);
        var viability = ViabilityScore(inputs.SearchVolume, volumeRisk, inputs.Competition, inputs.Cpc);
        if (volumeRisk == LowSearchVolumeRisk.High)
            viability = Math.Min(viability, 25);
        var compliance = ComplianceScore(inputs.ComplianceState, inputs.KeywordBlocked);
        var confidence = ConfidenceScore(inputs.VolumeConfidence, inputs.ConversionConfidence, inputs.ComplianceState);

        var overall = inputs.KeywordBlocked || inputs.ComplianceState == ComplianceState.Prohibited
            ? 0
            : (int)Math.Round((commercial + viability) / 2m, MidpointRounding.AwayFromZero);

        var recommendation = Recommend(
            commercial,
            viability,
            compliance,
            volumeRisk,
            baseProfit.ExpectedProfitPerClick,
            inputs.KeywordBlocked,
            inputs.ComplianceState);

        var why = BuildWhy(inputs, commercial, viability, compliance, volumeRisk, recommendation, conservative, baseProfit, optimistic);

        return new OpportunityScoreResult(
            overall,
            commercial,
            viability,
            compliance,
            confidence,
            baseProfit.ExpectedRevenuePerClick,
            baseProfit.ExpectedProfitPerClick,
            conservative.ExpectedProfitPerClick,
            baseProfit.ExpectedProfitPerClick,
            optimistic.ExpectedProfitPerClick,
            recommendation,
            why);
    }

    public static OpportunityRecommendation Recommend(
        int commercialScore,
        int viabilityScore,
        int complianceScore,
        LowSearchVolumeRisk volumeRisk,
        decimal expectedProfitPerClick,
        bool keywordBlocked,
        ComplianceState complianceState)
    {
        if (keywordBlocked || complianceState == ComplianceState.Prohibited || complianceScore == 0)
            return OpportunityRecommendation.Skip;
        if (expectedProfitPerClick < 0)
            return OpportunityRecommendation.Skip;
        if (volumeRisk == LowSearchVolumeRisk.High)
            return OpportunityRecommendation.Skip;
        if (complianceState is ComplianceState.Unknown or ComplianceState.Researching)
            return OpportunityRecommendation.Investigate;
        if (commercialScore >= 70 && viabilityScore >= 50 &&
            complianceState is ComplianceState.Verified or ComplianceState.Restricted)
            return OpportunityRecommendation.Test;
        if (commercialScore < 40 || viabilityScore < 30)
            return OpportunityRecommendation.Skip;
        return OpportunityRecommendation.Investigate;
    }

    public static LowSearchVolumeRisk InferVolumeRisk(long? searchVolume)
    {
        if (searchVolume is null)
            return LowSearchVolumeRisk.Unknown;
        if (searchVolume < 100)
            return LowSearchVolumeRisk.High;
        if (searchVolume < 500)
            return LowSearchVolumeRisk.Medium;
        return LowSearchVolumeRisk.Low;
    }

    public static decimal DefaultConversionRate(KeywordTemplateKind template) => template switch
    {
        KeywordTemplateKind.Buy => 0.05m,
        KeywordTemplateKind.Coupon or KeywordTemplateKind.Discount or KeywordTemplateKind.Price => 0.04m,
        KeywordTemplateKind.Official => 0.035m,
        KeywordTemplateKind.Review => 0.025m,
        KeywordTemplateKind.Alternative or KeywordTemplateKind.Versus => 0.02m,
        _ => 0.015m
    };

    public static decimal DefaultCpc(KeywordTemplateKind template) => template switch
    {
        KeywordTemplateKind.Buy => 2.40m,
        KeywordTemplateKind.Coupon or KeywordTemplateKind.Discount => 1.90m,
        KeywordTemplateKind.Price => 1.80m,
        KeywordTemplateKind.Official => 1.60m,
        KeywordTemplateKind.Review => 1.40m,
        _ => 1.20m
    };

    public static CompetitionLevel DefaultCompetition(KeywordTemplateKind template) => template switch
    {
        KeywordTemplateKind.Buy or KeywordTemplateKind.Official => CompetitionLevel.High,
        KeywordTemplateKind.Price or KeywordTemplateKind.Coupon or KeywordTemplateKind.Review => CompetitionLevel.Medium,
        _ => CompetitionLevel.Low
    };

    private static int CommercialScore(int intent, decimal epc, decimal cpc, decimal conversionRate)
    {
        var intentPart = Clamp(intent);
        var economics = EconomicsScore(epc, cpc);
        var conversion = ConversionScore(conversionRate);
        return (int)Math.Round(intentPart * 0.45m + economics * 0.40m + conversion * 0.15m, MidpointRounding.AwayFromZero);
    }

    private static int ViabilityScore(long? volume, LowSearchVolumeRisk risk, CompetitionLevel competition, decimal cpc)
    {
        var volumeScore = risk switch
        {
            LowSearchVolumeRisk.High => 5,
            LowSearchVolumeRisk.Medium => 45,
            LowSearchVolumeRisk.Low => 90,
            _ => volume is > 0 ? 40 : 20
        };
        if (volume is >= 2000)
            volumeScore = 100;
        else if (volume is >= 500)
            volumeScore = Math.Max(volumeScore, 75);

        var competitionScore = competition switch
        {
            CompetitionLevel.Low => 100,
            CompetitionLevel.Medium => 55,
            CompetitionLevel.High => 20,
            _ => 50
        };
        var cpcScore = cpc switch
        {
            <= 0.5m => 90,
            <= 1.5m => 70,
            <= 3m => 45,
            _ => 20
        };
        return (int)Math.Round(volumeScore * 0.50m + competitionScore * 0.30m + cpcScore * 0.20m, MidpointRounding.AwayFromZero);
    }

    private static int ComplianceScore(ComplianceState state, bool blocked)
    {
        if (blocked)
            return 0;
        return state switch
        {
            ComplianceState.Prohibited => 0,
            ComplianceState.Unknown => 40,
            ComplianceState.Researching => 50,
            ComplianceState.Restricted => 70,
            ComplianceState.Verified => 100,
            _ => 40
        };
    }

    private static int ConfidenceScore(DataConfidence volume, DataConfidence conversion, ComplianceState compliance)
    {
        int Map(DataConfidence c) => c switch { DataConfidence.High => 90, DataConfidence.Medium => 65, _ => 35 };
        var complianceConf = compliance switch
        {
            ComplianceState.Verified => 90,
            ComplianceState.Restricted => 70,
            ComplianceState.Researching => 45,
            ComplianceState.Prohibited => 90,
            _ => 30
        };
        return Math.Min(Map(volume), Math.Min(Map(conversion), complianceConf));
    }

    private static LowSearchVolumeRisk ResolveVolumeRisk(long? volume, LowSearchVolumeRisk provided) =>
        provided == LowSearchVolumeRisk.Unknown ? InferVolumeRisk(volume) : provided;

    private static decimal EconomicsScore(decimal expectedProfitPerClick, decimal cpc)
    {
        if (expectedProfitPerClick <= 0)
            return 0;
        if (cpc <= 0)
            return 100;
        var ratio = expectedProfitPerClick / cpc;
        if (ratio >= 2)
            return 100;
        if (ratio >= 1)
            return 70 + (ratio - 1) * 30;
        return ratio * 70;
    }

    private static decimal ConversionScore(decimal conversionRate)
    {
        if (conversionRate <= 0)
            return 0;
        if (conversionRate >= 0.08m)
            return 100;
        return conversionRate / 0.08m * 100;
    }

    private static string BuildWhy(
        OpportunityScoreInputs inputs,
        int commercial,
        int viability,
        int compliance,
        LowSearchVolumeRisk volumeRisk,
        OpportunityRecommendation recommendation,
        ProfitResult conservative,
        ProfitResult baseline,
        ProfitResult optimistic)
    {
        var lines = new List<string>
        {
            $"STATUS: {recommendation.ToString().ToUpperInvariant()}",
            $"Commercial: {commercial}  Viability: {viability}  Compliance: {compliance}  Confidence: {ConfidenceScore(inputs.VolumeConfidence, inputs.ConversionConfidence, inputs.ComplianceState)}",
            "",
            "WHY?"
        };

        if (inputs.KeywordBlocked)
            lines.Add("x Keyword blocked by affiliate/search policy.");
        else
            lines.Add(inputs.ComplianceState is ComplianceState.Verified or ComplianceState.Restricted
                ? "✓ Policy check recorded for this offer."
                : "? Compliance is not verified yet.");

        if (volumeRisk == LowSearchVolumeRisk.High)
            lines.Add("x Search volume is too low to enter auction reliably.");
        else if (inputs.SearchVolume is > 0)
            lines.Add($"✓ Addressable volume estimate: {inputs.SearchVolume}/month.");
        else
            lines.Add("? Search volume is unknown — treat viability as low confidence.");

        if (baseline.ExpectedProfitPerClick > 0)
            lines.Add($"✓ Base expected profit/click {baseline.ExpectedProfitPerClick:0.00} (commission supports estimated CPC).");
        else
            lines.Add("x Base case is unprofitable at estimated CPC.");

        lines.Add($"Range (conservative → optimistic): {conservative.ExpectedProfitPerClick:0.00} → {optimistic.ExpectedProfitPerClick:0.00} profit/click.");
        if (inputs.ConversionConfidence == DataConfidence.Low)
            lines.Add("⚠ Conversion rate estimate has low confidence.");

        return string.Join('\n', lines);
    }

    private static decimal Clamp(int score) => Math.Min(100, Math.Max(0, score));
}
