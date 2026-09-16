using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Compliance;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Keywords;
using BottomFunnel.Domain.Scoring;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class KeywordOpportunityService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly IJobQueue _jobs;

    public KeywordOpportunityService(IAppDbContext db, ICurrentUser current, IJobQueue jobs)
    {
        _db = db;
        _current = current;
        _jobs = jobs;
    }

    private Guid OrgId => _current.OrganizationId;

    public async Task<IReadOnlyList<KeywordDto>> ListKeywordsAsync(Guid? productId, CancellationToken ct)
    {
        var query = _db.Keywords.AsNoTracking()
            .Include(x => x.Metric)
            .Where(x => x.OrganizationId == OrgId);
        if (productId.HasValue)
            query = query.Where(x => x.ProductId == productId.Value);

        var rows = await query.OrderBy(x => x.Phrase).ToListAsync(ct);
        return rows.Select(ToDto).ToList();
    }

    public async Task<IReadOnlyList<KeywordDto>> GenerateForProductAsync(Guid productId, CancellationToken ct)
    {
        var product = await _db.Products
            .Include(x => x.Offers).ThenInclude(o => o.Merchant).ThenInclude(m => m!.Restrictions)
            .Include(x => x.Offers).ThenInclude(o => o.Restrictions)
            .FirstOrDefaultAsync(x => x.Id == productId && x.OrganizationId == OrgId, ct)
            ?? throw new KeyNotFoundException("Product not found.");

        if (!product.Offers.Any(o => o.Status == OfferStatus.Active))
            throw new InvalidOperationException("Record offer compliance before generating keywords. Attach an active offer and set PPC/brand/coupon policy first.");

        var (policy, overall) = CombineOfferCompliance(product.Offers.Where(o => o.Status == OfferStatus.Active));
        var existing = await _db.Keywords.Include(x => x.Metric)
            .Where(x => x.ProductId == productId && x.OrganizationId == OrgId)
            .ToListAsync(ct);

        foreach (var candidate in KeywordTemplateCatalog.Generate(product.Name, policy, overall))
        {
            var keyword = existing.FirstOrDefault(x => x.Phrase.Equals(candidate.Phrase, StringComparison.OrdinalIgnoreCase));
            if (keyword is null)
            {
                keyword = new Keyword
                {
                    OrganizationId = OrgId,
                    ProductId = productId,
                    Phrase = candidate.Phrase,
                    Template = candidate.Template,
                    Intent = candidate.Intent,
                    IntentScore = candidate.IntentScore
                };
                _db.Keywords.Add(keyword);
                _db.KeywordMetrics.Add(new KeywordMetric
                {
                    OrganizationId = OrgId,
                    KeywordId = keyword.Id,
                    EstimatedCpc = OpportunityScoring.DefaultCpc(candidate.Template),
                    Competition = OpportunityScoring.DefaultCompetition(candidate.Template),
                    SearchVolumeConfidence = DataConfidence.Low,
                    LowSearchVolumeRisk = LowSearchVolumeRisk.Unknown
                });
                existing.Add(keyword);
            }

            keyword.Template = candidate.Template;
            keyword.Intent = candidate.Intent;
            keyword.IntentScore = candidate.IntentScore;
            keyword.ComplianceStatus = candidate.ComplianceStatus;
            keyword.BrandStatus = candidate.BrandStatus;
            keyword.BlockedReason = candidate.BlockedReason;
            keyword.UpdatedAt = DateTimeOffset.UtcNow;
        }

        await _db.SaveChangesAsync(ct);
        await ScoreProductAsync(productId, ct);
        return await ListKeywordsAsync(productId, ct);
    }

    public async Task UpdateMetricsAsync(Guid keywordId, UpsertKeywordMetricsRequest request, CancellationToken ct)
    {
        var keyword = await _db.Keywords.Include(x => x.Metric)
            .FirstOrDefaultAsync(x => x.Id == keywordId && x.OrganizationId == OrgId, ct)
            ?? throw new KeyNotFoundException("Keyword not found.");

        if (keyword.Metric is null)
        {
            keyword.Metric = new KeywordMetric { OrganizationId = OrgId, KeywordId = keyword.Id };
            _db.KeywordMetrics.Add(keyword.Metric);
        }

        keyword.Metric.EstimatedCpc = request.EstimatedCpc;
        keyword.Metric.Competition = request.Competition;
        keyword.Metric.SearchVolume = request.SearchVolume;
        keyword.Metric.OtherCostsPerClick = request.OtherCostsPerClick;
        keyword.Metric.SearchVolumeSource = request.SearchVolumeSource;
        keyword.Metric.SearchVolumePeriod = request.SearchVolumePeriod;
        keyword.Metric.SearchVolumeConfidence = request.SearchVolumeConfidence
            ?? (request.SearchVolume.HasValue ? DataConfidence.Medium : DataConfidence.Low);
        keyword.Metric.LowSearchVolumeRisk = OpportunityScoring.InferVolumeRisk(request.SearchVolume);
        keyword.Metric.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        await ScoreProductAsync(keyword.ProductId, ct);
    }

    public async Task<IReadOnlyList<OpportunityDto>> ListOpportunitiesAsync(CancellationToken ct)
    {
        var rows = await _db.KeywordOpportunities.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .Include(x => x.Keyword)
            .Include(x => x.Offer)
            .Include(x => x.Product)
            .OrderByDescending(x => x.Score)
            .ToListAsync(ct);
        return rows.Select(ToDto).ToList();
    }

    public async Task<IReadOnlyList<OpportunityDto>> ScoreProductAsync(Guid productId, CancellationToken ct)
    {
        var product = await _db.Products
            .Include(x => x.Keywords).ThenInclude(x => x.Metric)
            .Include(x => x.Offers).ThenInclude(o => o.Merchant).ThenInclude(m => m!.Restrictions)
            .Include(x => x.Offers).ThenInclude(o => o.Restrictions)
            .FirstOrDefaultAsync(x => x.Id == productId && x.OrganizationId == OrgId, ct)
            ?? throw new KeyNotFoundException("Product not found.");

        var existing = await _db.KeywordOpportunities
            .Where(x => x.ProductId == productId && x.OrganizationId == OrgId)
            .ToListAsync(ct);

        foreach (var keyword in product.Keywords)
        {
            foreach (var offer in product.Offers.Where(o => o.Status == OfferStatus.Active))
            {
                var policy = OfferPolicyResolver.Resolve(offer, offer.Merchant);
                var restrictions = offer.Restrictions.Concat(offer.Merchant?.Restrictions ?? []);
                var overall = OfferPolicyResolver.WorstState(restrictions);
                var gate = KeywordComplianceGate.Evaluate(keyword.Template, policy, overall);

                keyword.ComplianceStatus = gate.ComplianceStatus;
                keyword.BrandStatus = gate.BrandStatus;
                keyword.BlockedReason = gate.BlockedReason;

                var cpc = keyword.Metric?.EstimatedCpc ?? OpportunityScoring.DefaultCpc(keyword.Template);
                var other = keyword.Metric?.OtherCostsPerClick ?? 0;
                var competition = keyword.Metric?.Competition ?? OpportunityScoring.DefaultCompetition(keyword.Template);
                var conversion = OpportunityScoring.DefaultConversionRate(keyword.Template);
                var volume = keyword.Metric?.SearchVolume;
                var volumeRisk = keyword.Metric?.LowSearchVolumeRisk == LowSearchVolumeRisk.Unknown
                    ? OpportunityScoring.InferVolumeRisk(volume)
                    : keyword.Metric?.LowSearchVolumeRisk ?? OpportunityScoring.InferVolumeRisk(volume);

                var payoutCad = offer.PayoutAmountCad > 0
                    ? offer.PayoutAmountCad
                    : offer.Commission;
                var result = OpportunityScoringEngine.Score(new OpportunityScoreInputs(
                    keyword.IntentScore,
                    payoutCad,
                    conversion,
                    cpc,
                    other,
                    competition,
                    volume,
                    keyword.Metric?.SearchVolumeConfidence ?? DataConfidence.Low,
                    volumeRisk,
                    gate.ComplianceStatus,
                    gate.Blocked,
                    DataConfidence.Low));

                var row = existing.FirstOrDefault(x => x.KeywordId == keyword.Id && x.OfferId == offer.Id);
                if (row is null)
                {
                    row = new KeywordOpportunity
                    {
                        OrganizationId = OrgId,
                        KeywordId = keyword.Id,
                        OfferId = offer.Id,
                        ProductId = productId
                    };
                    _db.KeywordOpportunities.Add(row);
                    existing.Add(row);
                }

                row.Score = result.Score;
                row.CommercialScore = result.CommercialScore;
                row.ViabilityScore = result.ViabilityScore;
                row.ComplianceScore = result.ComplianceScore;
                row.Confidence = result.Confidence;
                row.CommercialIntent = keyword.IntentScore;
                row.ExpectedRevenuePerClick = result.ExpectedRevenuePerClick;
                row.ExpectedProfitPerClick = result.ExpectedProfitPerClick;
                row.ExpectedProfitConservative = result.ExpectedProfitConservative;
                row.ExpectedProfitBase = result.ExpectedProfitBase;
                row.ExpectedProfitOptimistic = result.ExpectedProfitOptimistic;
                row.EstimatedConversionRate = conversion;
                row.EstimatedCpc = cpc;
                row.Commission = payoutCad;
                row.Competition = competition;
                row.Recommendation = result.Recommendation;
                row.Why = result.Why;
                row.Notes = gate.BlockedReason;
                row.UpdatedAt = DateTimeOffset.UtcNow;
            }
        }

        await _db.SaveChangesAsync(ct);
        var rows = await _db.KeywordOpportunities.AsNoTracking()
            .Where(x => x.ProductId == productId && x.OrganizationId == OrgId)
            .Include(x => x.Keyword)
            .Include(x => x.Offer)
            .Include(x => x.Product)
            .OrderByDescending(x => x.Score)
            .ToListAsync(ct);
        return rows.Select(ToDto).ToList();
    }

    public async Task EnqueueGenerateAsync(Guid productId, CancellationToken ct)
    {
        await _jobs.EnqueueAsync("generate-keywords", productId.ToString(), OrgId, ct);
    }

    public static OpportunityDto ToDto(KeywordOpportunity x) => new(
        x.Id,
        x.ProductId,
        x.Product?.Name ?? "",
        x.KeywordId,
        x.Keyword?.Phrase ?? "",
        x.OfferId,
        x.Offer?.Name ?? "",
        x.Score,
        x.CommercialScore,
        x.ViabilityScore,
        x.ComplianceScore,
        x.Confidence,
        x.CommercialIntent,
        x.EstimatedCpc,
        x.Commission,
        x.EstimatedConversionRate,
        x.ExpectedRevenuePerClick,
        x.ExpectedProfitPerClick,
        x.ExpectedProfitConservative,
        x.ExpectedProfitBase,
        x.ExpectedProfitOptimistic,
        x.Competition,
        x.Recommendation,
        x.Why,
        x.Notes ?? x.Keyword?.BlockedReason);

    private static KeywordDto ToDto(Keyword x) => new(
        x.Id,
        x.ProductId,
        x.Phrase,
        x.Template,
        x.Intent,
        x.IntentScore,
        x.Metric?.EstimatedCpc,
        x.Metric?.Competition ?? CompetitionLevel.Unknown,
        x.Metric?.SearchVolume,
        x.Metric?.LowSearchVolumeRisk ?? LowSearchVolumeRisk.Unknown,
        x.ComplianceStatus,
        x.BrandStatus,
        x.BlockedReason);

    internal static (OfferPolicy? Policy, ComplianceState Overall) CombineOfferCompliance(IEnumerable<Offer> offers)
    {
        var list = offers.ToList();
        if (list.Count == 0)
            return (null, ComplianceState.Unknown);

        var policies = list.Select(o => OfferPolicyResolver.Resolve(o, o.Merchant)).ToList();
        var combined = new OfferPolicy(
            policies.All(p => p.PpcAllowed),
            policies.All(p => p.BrandBiddingAllowed),
            policies.All(p => p.BrandPlusModifierAllowed),
            policies.All(p => p.CouponBiddingAllowed),
            policies.All(p => p.DirectLinkingAllowed));
        var restrictions = list.SelectMany(o => o.Restrictions.Concat(o.Merchant?.Restrictions ?? []));
        return (combined, OfferPolicyResolver.WorstState(restrictions));
    }
}
