namespace BottomFunnel.Domain.Scoring;

/// <summary>
/// Scores opportunities using CAD economics. Pass <see cref="OpportunityScoreInputs.Commission"/> as PayoutAmountCad.
/// </summary>
public static class OpportunityScoringEngine
{
    public static OpportunityScoreResult Score(OpportunityScoreInputs inputs) => OpportunityScoring.Score(inputs);
}
