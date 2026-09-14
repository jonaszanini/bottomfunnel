using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class AiOrchestrator
{
    private readonly IAiService _ai;
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;

    public AiOrchestrator(IAiService ai, IAppDbContext db, ICurrentUser current)
    {
        _ai = ai;
        _db = db;
        _current = current;
    }

    public bool IsConfigured => _ai.IsConfigured;

    public async Task<AiAnalyzeResponse> AnalyzeAsync(AiAnalyzeRequest request, CancellationToken ct)
    {
        var (system, user) = await BuildPrompt(request, ct);
        string result;
        var usedAi = _ai.IsConfigured;
        if (usedAi)
            result = await _ai.CompleteAsync(system, user, ct);
        else
            result = Fallback(request, user);

        _db.AiAnalyses.Add(new AiAnalysis
        {
            OrganizationId = _current.OrganizationId,
            SubjectType = request.SubjectType,
            SubjectId = request.SubjectId,
            Kind = request.Kind,
            Prompt = user,
            Result = result
        });
        await _db.SaveChangesAsync(ct);
        return new AiAnalyzeResponse(result, usedAi);
    }

    private async Task<(string System, string User)> BuildPrompt(AiAnalyzeRequest request, CancellationToken ct)
    {
        if (request.SubjectType.Equals("opportunity", StringComparison.OrdinalIgnoreCase))
        {
            var opp = await _db.KeywordOpportunities
                .Include(x => x.Keyword)
                .Include(x => x.Offer)
                .Include(x => x.Product)
                .FirstOrDefaultAsync(x => x.Id == request.SubjectId && x.OrganizationId == _current.OrganizationId, ct)
                ?? throw new KeyNotFoundException("Opportunity not found.");

            var user = $"""
                Product: {opp.Product?.Name}
                Keyword: {opp.Keyword?.Phrase}
                Offer: {opp.Offer?.Name}
                Commercial score: {opp.CommercialScore}
                Viability score: {opp.ViabilityScore}
                Compliance score: {opp.ComplianceScore}
                Confidence: {opp.Confidence}
                CPC: {opp.EstimatedCpc}
                Commission: {opp.Commission}
                Expected profit range: {opp.ExpectedProfitConservative} / {opp.ExpectedProfitBase} / {opp.ExpectedProfitOptimistic}
                Recommendation: {opp.Recommendation}
                Deterministic WHY:
                {opp.Why}
                Restate this honestly. Do not invent metrics or claim last-click attribution is truth.
                """;
            return ("You explain affiliate opportunity scores from structured economics. Be concise.", user);
        }

        if (request.SubjectType.Equals("campaign", StringComparison.OrdinalIgnoreCase))
        {
            var campaign = await _db.Campaigns.FirstOrDefaultAsync(x => x.Id == request.SubjectId && x.OrganizationId == _current.OrganizationId, ct)
                           ?? throw new KeyNotFoundException("Campaign not found.");
            var spend = await _db.Costs.Where(x => x.CampaignId == campaign.Id).SumAsync(x => (decimal?)x.Amount, ct) ?? 0;
            var revenue = await _db.Conversions.Where(x => x.CampaignId == campaign.Id).SumAsync(x => (decimal?)x.Commission, ct) ?? 0;
            var clicks = await _db.Clicks.CountAsync(x => x.CampaignId == campaign.Id, ct);
            var user = $"Campaign {campaign.Name} status {campaign.Status}. Spend {spend}. Commission {revenue}. Clicks {clicks}. Summarize performance and what to do next.";
            return ("You are a performance marketing analyst. Use only provided numbers.", user);
        }

        if (request.SubjectType.Equals("landing-page", StringComparison.OrdinalIgnoreCase))
        {
            var page = await _db.LandingPages.FirstOrDefaultAsync(x => x.Id == request.SubjectId && x.OrganizationId == _current.OrganizationId, ct)
                       ?? throw new KeyNotFoundException("Landing page not found.");
            var user = $"Write improved headline, 5 benefits, CTA, and 3 FAQs for landing page '{page.Name}' currently titled '{page.Headline}'. Keep claims conservative.";
            return ("You write bottom-of-funnel landing page copy. No medical or income claims.", user);
        }

        return ("You help with affiliate opportunity analysis.", $"{request.Kind} for {request.SubjectType} {request.SubjectId}");
    }

    private static string Fallback(AiAnalyzeRequest request, string user) =>
        $"[AI not configured] Deterministic summary for {request.SubjectType}/{request.Kind}: {user.Split('\n').FirstOrDefault()}";
}
