using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Scoring;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class TrackingAnalyticsService
{
    private readonly IAppDbContext _db;
    private readonly IStoreConnectorRegistry _registry;
    private readonly ICredentialProtector _protector;

    public TrackingAnalyticsService(IAppDbContext db, IStoreConnectorRegistry registry, ICredentialProtector protector)
    {
        _db = db;
        _registry = registry;
        _protector = protector;
    }

    public async Task<CaptureVisitResponse> CaptureVisitAsync(CaptureVisitRequest request, string? userAgent, string? referrer, CancellationToken ct)
    {
        var page = await _db.LandingPages.FirstOrDefaultAsync(x => x.Slug == request.Slug && x.IsPublished, ct)
                   ?? throw new KeyNotFoundException("Landing page not found.");

        var sessionKey = string.IsNullOrWhiteSpace(request.SessionKey)
            ? Guid.NewGuid().ToString("N")
            : request.SessionKey.Trim();

        var session = await _db.VisitorSessions.FirstOrDefaultAsync(
            x => x.OrganizationId == page.OrganizationId && x.SessionKey == sessionKey, ct);
        if (session is null)
        {
            session = new VisitorSession
            {
                OrganizationId = page.OrganizationId,
                SessionKey = sessionKey,
                LastSeenAt = DateTimeOffset.UtcNow
            };
            _db.VisitorSessions.Add(session);
        }
        else
        {
            session.LastSeenAt = DateTimeOffset.UtcNow;
        }

        var campaign = await ResolveCampaignForPage(page.Id, request.CampaignId, ct);
        var visit = new Visit
        {
            OrganizationId = page.OrganizationId,
            SessionId = session.Id,
            LandingPageId = page.Id,
            CampaignId = campaign?.Id ?? request.CampaignId,
            KeywordId = request.KeywordId,
            Gclid = string.IsNullOrWhiteSpace(request.Gclid) ? null : request.Gclid,
            UserAgent = request.UserAgent ?? userAgent,
            Device = request.Device,
            Referrer = request.Referrer ?? referrer,
            OccurredAt = DateTimeOffset.UtcNow
        };
        _db.Visits.Add(visit);
        await _db.SaveChangesAsync(ct);

        var trackingCode = campaign?.TrackingCode;
        var ctaUrl = trackingCode is null
            ? null
            : $"/t/{trackingCode}?sessionId={visit.SessionId:N}&visitId={visit.Id:N}"
              + (string.IsNullOrWhiteSpace(visit.Gclid) ? "" : $"&gclid={Uri.EscapeDataString(visit.Gclid)}");

        return new CaptureVisitResponse(visit.Id, session.Id, trackingCode, ctaUrl);
    }

    public async Task<string> TrackAndRedirectAsync(
        string trackingCode,
        string? userAgent,
        string? ip,
        string? referrer,
        Guid? keywordId,
        Guid? adId,
        Guid? sessionId,
        Guid? visitId,
        string? gclid,
        CancellationToken ct)
    {
        var campaign = await _db.Campaigns
            .Include(x => x.Offer)
            .FirstOrDefaultAsync(x => x.TrackingCode == trackingCode, ct)
            ?? throw new KeyNotFoundException("Unknown tracking code.");

        if (sessionId is null && visitId.HasValue)
        {
            var visit = await _db.Visits.FirstOrDefaultAsync(x => x.Id == visitId.Value, ct);
            sessionId = visit?.SessionId;
            gclid ??= visit?.Gclid;
        }

        var destination = await BuildDestination(campaign, ct);
        var click = new Click
        {
            OrganizationId = campaign.OrganizationId,
            CampaignId = campaign.Id,
            OfferId = campaign.OfferId,
            LandingPageId = campaign.LandingPageId,
            KeywordId = keywordId,
            AdId = adId,
            SessionId = sessionId,
            VisitId = visitId,
            TrackingCode = trackingCode,
            DestinationUrl = destination,
            UserAgent = userAgent,
            IpHash = string.IsNullOrWhiteSpace(ip) ? null : Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(ip))),
            Referrer = referrer,
            Gclid = string.IsNullOrWhiteSpace(gclid) ? null : gclid,
            SubId = null,
            OccurredAt = DateTimeOffset.UtcNow
        };
        _db.Clicks.Add(click);
        await _db.SaveChangesAsync(ct);

        click.SubId = click.Id.ToString("N");
        var resolved = destination.Replace("{clickId}", click.Id.ToString("N"), StringComparison.OrdinalIgnoreCase);
        click.DestinationUrl = resolved;
        await _db.SaveChangesAsync(ct);
        return resolved;
    }

    public async Task RecordPostbackAsync(Guid? clickId, string? trackingId, decimal commission, decimal? amount, string currency, string providerKey, CancellationToken ct)
    {
        Click? click = null;
        if (clickId.HasValue)
            click = await _db.Clicks.FirstOrDefaultAsync(x => x.Id == clickId.Value, ct);
        else if (!string.IsNullOrWhiteSpace(trackingId) && Guid.TryParse(trackingId, out var parsed))
            click = await _db.Clicks.FirstOrDefaultAsync(x => x.Id == parsed, ct);

        if (click is null && !string.IsNullOrWhiteSpace(trackingId))
            click = await FindClickBySubId(trackingId, ct);

        await PersistConversionAsync(
            click,
            organizationId: click?.OrganizationId ?? Guid.Empty,
            providerKey,
            externalOrderId: trackingId,
            eventType: ConversionEventType.Sale,
            amount: amount ?? commission,
            commission,
            currency,
            occurredAt: DateTimeOffset.UtcNow,
            raw: null,
            ct);
    }

    public async Task ApplyCanonicalConversionAsync(CanonicalConversion conversion, Guid organizationId, CancellationToken ct)
    {
        Guid? clickId = null;
        if (Guid.TryParse(conversion.ClickId, out var parsed))
            clickId = parsed;

        var click = clickId.HasValue
            ? await _db.Clicks.FirstOrDefaultAsync(x => x.Id == clickId.Value, ct)
            : null;
        if (click is null && !string.IsNullOrWhiteSpace(conversion.ClickId))
            click = await FindClickBySubId(conversion.ClickId, ct);

        await PersistConversionAsync(
            click,
            organizationId,
            "webhook",
            conversion.ExternalOrderId,
            conversion.EventType,
            conversion.Amount,
            conversion.Commission,
            conversion.Currency,
            conversion.OccurredAt,
            conversion.RawPayloadJson,
            ct);
    }

    public async Task<IReadOnlyList<ClickDto>> ListClicksAsync(Guid organizationId, CancellationToken ct) =>
        await _db.Clicks.AsNoTracking()
            .Where(x => x.OrganizationId == organizationId)
            .OrderByDescending(x => x.OccurredAt)
            .Take(200)
            .Select(x => new ClickDto(x.Id, x.CampaignId, x.KeywordId, x.AdId, x.TrackingCode, x.DestinationUrl, x.OccurredAt, x.Gclid, null))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<ConversionDto>> ListConversionsAsync(Guid organizationId, CancellationToken ct) =>
        await _db.Conversions.AsNoTracking()
            .Where(x => x.OrganizationId == organizationId)
            .OrderByDescending(x => x.OccurredAt)
            .Take(200)
            .Select(x => new ConversionDto(x.Id, x.ClickId, x.CampaignId, x.ProviderKey, x.EventType, x.Provenance, x.AttributionConfidence, x.Amount, x.Commission, x.Currency, x.OccurredAt))
            .ToListAsync(ct);

    public async Task AddManualConversionAsync(Guid organizationId, CreateConversionRequest request, CancellationToken ct)
    {
        Click? click = null;
        if (request.ClickId.HasValue)
            click = await _db.Clicks.FirstOrDefaultAsync(x => x.Id == request.ClickId.Value, ct);

        await PersistConversionAsync(
            click,
            organizationId,
            "manual",
            null,
            request.EventType,
            request.Amount,
            request.Commission,
            request.Currency,
            DateTimeOffset.UtcNow,
            null,
            ct,
            request.CampaignId,
            request.OfferId);
    }

    public async Task AddCostAsync(Guid organizationId, CreateCostRequest request, CancellationToken ct)
    {
        _db.Costs.Add(new Cost
        {
            OrganizationId = organizationId,
            CampaignId = request.CampaignId,
            KeywordId = request.KeywordId,
            OfferId = request.OfferId,
            Amount = request.Amount,
            Currency = request.Currency,
            Notes = request.Notes,
            Source = "manual"
        });
        await _db.SaveChangesAsync(ct);
    }

    public async Task<DashboardDto> GetDashboardAsync(Guid organizationId, CancellationToken ct)
    {
        var visits = await _db.Visits.CountAsync(x => x.OrganizationId == organizationId, ct);
        var clicks = await _db.Clicks.CountAsync(x => x.OrganizationId == organizationId, ct);

        var conversions = await _db.Conversions.AsNoTracking()
            .Where(x => x.OrganizationId == organizationId && x.EventType == ConversionEventType.Sale)
            .ToListAsync(ct);

        var reportedCommission = conversions.Sum(x => x.Commission);
        var attributable = conversions
            .Where(x => x.Provenance is ConversionProvenance.Observed or ConversionProvenance.Attributed)
            .Sum(x => x.Commission);
        var observed = conversions.Where(x => x.Provenance == ConversionProvenance.Observed).Sum(x => x.Commission);

        var spend = await _db.Costs.Where(x => x.OrganizationId == organizationId).SumAsync(x => (decimal?)x.Amount, ct) ?? 0;
        var profit = ProfitabilityCalculator.ContributionProfit(reportedCommission, spend, 0);
        var roi = ProfitabilityCalculator.RoiPercent(profit, spend);
        var observedRoas = spend == 0 ? 0 : ProfitabilityCalculator.RoundMoney(observed / spend);
        var reportedRoas = spend == 0 ? 0 : ProfitabilityCalculator.RoundMoney(reportedCommission / spend);

        var attributionConfidence = conversions.Count == 0
            ? 0
            : (int)Math.Round(conversions.Average(x => x.AttributionConfidence), MidpointRounding.AwayFromZero);

        var opportunities = await _db.KeywordOpportunities.AsNoTracking()
            .Where(x => x.OrganizationId == organizationId)
            .Include(x => x.Keyword)
            .Include(x => x.Offer)
            .Include(x => x.Product)
            .OrderByDescending(x => x.Score)
            .Take(5)
            .ToListAsync(ct);

        var campaigns = await _db.Campaigns.AsNoTracking()
            .Where(x => x.OrganizationId == organizationId)
            .ToListAsync(ct);

        var campaignProfits = new List<CampaignProfitDto>();
        foreach (var campaign in campaigns)
        {
            var cSpend = await _db.Costs.Where(x => x.CampaignId == campaign.Id).SumAsync(x => (decimal?)x.Amount, ct) ?? 0;
            var cConvs = conversions.Where(x => x.CampaignId == campaign.Id).ToList();
            var reported = cConvs.Sum(x => x.Commission);
            var obs = cConvs.Where(x => x.Provenance == ConversionProvenance.Observed).Sum(x => x.Commission);
            var conf = cConvs.Count == 0 ? 0 : (int)Math.Round(cConvs.Average(x => x.AttributionConfidence), MidpointRounding.AwayFromZero);
            var insufficient = conf < 50;
            campaignProfits.Add(new CampaignProfitDto(
                campaign.Id,
                campaign.Name,
                cSpend,
                reported,
                ProfitabilityCalculator.ContributionProfit(reported, cSpend, 0),
                obs,
                reported,
                cSpend == 0 ? 0 : ProfitabilityCalculator.RoundMoney(obs / cSpend),
                cSpend == 0 ? 0 : ProfitabilityCalculator.RoundMoney(reported / cSpend),
                conf,
                insufficient));
        }

        return new DashboardDto(
            reportedCommission,
            spend,
            profit,
            roi,
            visits,
            clicks,
            reportedCommission,
            attributable,
            attributionConfidence,
            observedRoas,
            reportedRoas,
            opportunities.Select(KeywordOpportunityService.ToDto).ToList(),
            campaignProfits.OrderByDescending(x => x.Profit).Take(5).ToList());
    }

    private async Task PersistConversionAsync(
        Click? click,
        Guid organizationId,
        string providerKey,
        string? externalOrderId,
        ConversionEventType eventType,
        decimal amount,
        decimal commission,
        string currency,
        DateTimeOffset occurredAt,
        string? raw,
        CancellationToken ct,
        Guid? campaignId = null,
        Guid? offerId = null)
    {
        var orgId = click?.OrganizationId ?? organizationId;
        if (orgId == Guid.Empty)
            throw new InvalidOperationException("Unable to attribute conversion.");

        var (provenance, confidence) = ClassifyProvenance(click, externalOrderId);
        var conversion = new Conversion
        {
            OrganizationId = orgId,
            ClickId = click?.Id,
            CampaignId = click?.CampaignId ?? campaignId,
            OfferId = click?.OfferId ?? offerId,
            KeywordId = click?.KeywordId,
            LandingPageId = click?.LandingPageId,
            SessionId = click?.SessionId,
            ProviderKey = providerKey,
            ExternalOrderId = externalOrderId,
            Gclid = click?.Gclid,
            SubId = click?.SubId ?? click?.Id.ToString("N"),
            EventType = eventType,
            Provenance = provenance,
            AttributionConfidence = confidence,
            Amount = amount,
            Commission = commission,
            Currency = string.IsNullOrWhiteSpace(currency) ? "USD" : currency,
            OccurredAt = occurredAt,
            RawPayloadJson = raw
        };

        _db.Conversions.Add(conversion);
        _db.CommissionLedgers.Add(new CommissionLedger
        {
            OrganizationId = orgId,
            ConversionId = conversion.Id,
            OfferId = conversion.OfferId,
            Amount = eventType is ConversionEventType.Refund or ConversionEventType.Chargeback
                ? -Math.Abs(conversion.Commission)
                : conversion.Commission,
            Currency = conversion.Currency,
            OccurredAt = occurredAt
        });
        await _db.SaveChangesAsync(ct);
    }

    private async Task<Click?> FindClickBySubId(string trackingId, CancellationToken ct)
    {
        var windowStart = DateTimeOffset.UtcNow.AddDays(-30);
        return await _db.Clicks
            .Where(x => x.OccurredAt >= windowStart && (x.SubId == trackingId || x.Id.ToString() == trackingId))
            .OrderByDescending(x => x.OccurredAt)
            .FirstOrDefaultAsync(ct);
    }

    private static (ConversionProvenance Provenance, int Confidence) ClassifyProvenance(Click? click, string? trackingId)
    {
        if (click is null)
            return (ConversionProvenance.Reported, 20);

        if (string.IsNullOrWhiteSpace(trackingId)
            || click.Id.ToString("N").Equals(trackingId, StringComparison.OrdinalIgnoreCase)
            || click.Id.ToString().Equals(trackingId, StringComparison.OrdinalIgnoreCase)
            || string.Equals(click.SubId, trackingId, StringComparison.OrdinalIgnoreCase))
            return (ConversionProvenance.Observed, 90);

        return (ConversionProvenance.Attributed, 70);
    }

    private async Task<Campaign?> ResolveCampaignForPage(Guid landingPageId, Guid? campaignId, CancellationToken ct)
    {
        if (campaignId.HasValue)
            return await _db.Campaigns.FirstOrDefaultAsync(x => x.Id == campaignId.Value, ct);

        return await _db.Campaigns
            .Where(x => x.LandingPageId == landingPageId && (x.Status == CampaignStatus.Live || x.Status == CampaignStatus.Ready))
            .OrderByDescending(x => x.Status == CampaignStatus.Live)
            .ThenByDescending(x => x.UpdatedAt)
            .FirstOrDefaultAsync(ct);
    }

    private async Task<string> BuildDestination(Campaign campaign, CancellationToken ct)
    {
        var offer = campaign.Offer ?? (campaign.OfferId.HasValue
            ? await _db.Offers.FirstOrDefaultAsync(x => x.Id == campaign.OfferId.Value, ct)
            : null);

        if (offer is null)
            throw new InvalidOperationException("Campaign has no offer.");

        var connector = _registry.Find(offer.ProviderKey);
        Dictionary<string, string> creds = [];
        if (offer.StoreConnectionId.HasValue)
        {
            var connection = await _db.StoreConnections.FirstOrDefaultAsync(x => x.Id == offer.StoreConnectionId.Value, ct);
            if (connection is not null)
                creds = _protector.Unprotect(connection.EncryptedCredentials);
        }

        var context = new CanonicalOfferContext(
            offer.ProviderKey,
            offer.ExternalProductId,
            offer.ExternalVendorId,
            offer.AffiliateUrl,
            offer.AffiliateUrlTemplate);

        if (connector is not null)
            return connector.BuildTrackedAffiliateUrl(context, "{clickId}", creds);

        if (!string.IsNullOrWhiteSpace(offer.AffiliateUrl))
        {
            var url = offer.AffiliateUrl;
            return url.Contains('?') ? $"{url}&tid={{clickId}}" : $"{url}?tid={{clickId}}";
        }

        throw new InvalidOperationException("Offer has no affiliate URL.");
    }
}
