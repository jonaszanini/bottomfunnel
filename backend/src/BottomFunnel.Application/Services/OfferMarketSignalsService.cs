using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public sealed class OfferMarketSignalsService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly ISearchVolumeService _search;
    private readonly IReputationAuditService _reputation;

    public OfferMarketSignalsService(
        IAppDbContext db,
        ICurrentUser current,
        ISearchVolumeService search,
        IReputationAuditService reputation)
    {
        _db = db;
        _current = current;
        _search = search;
        _reputation = reputation;
    }

    public async Task<(OfferSearchTrend Trend, OfferReputation Reputation)> RefreshAsync(Guid offerId, CancellationToken ct)
    {
        var offer = await _db.Offers
            .Include(x => x.Product)
            .Include(x => x.Merchant)
            .Include(x => x.SearchTrend)
            .Include(x => x.Reputation)
            .FirstOrDefaultAsync(x => x.Id == offerId && x.OrganizationId == _current.OrganizationId, ct)
            ?? throw new KeyNotFoundException("Offer not found.");

        var keyword = offer.Product?.Name ?? offer.Name;
        var brand = BrandQuery(offer);
        var fetchedTrend = await _search.FetchNinetyDayTrendAsync(keyword, ct);
        var fetchedReputation = await _reputation.AuditTrustpilotAsync(brand, ct);

        var trend = offer.SearchTrend ?? new OfferSearchTrend
        {
            OrganizationId = _current.OrganizationId,
            OfferId = offer.Id
        };
        if (offer.SearchTrend is null)
            _db.OfferSearchTrends.Add(trend);
        trend.CurrentMonthlyVolume = fetchedTrend.CurrentMonthlyVolume;
        trend.NinetyDayChangePercent = fetchedTrend.NinetyDayChangePercent;
        trend.Direction = fetchedTrend.Direction;
        trend.VolumeHistoryJson = fetchedTrend.VolumeHistoryJson;
        trend.LastRefreshedAt = DateTimeOffset.UtcNow;
        trend.UpdatedAt = DateTimeOffset.UtcNow;

        var reputation = offer.Reputation ?? new OfferReputation
        {
            OrganizationId = _current.OrganizationId,
            OfferId = offer.Id
        };
        if (offer.Reputation is null)
            _db.OfferReputations.Add(reputation);
        reputation.TrustpilotRating = fetchedReputation.TrustpilotRating;
        reputation.TotalReviewsCount = fetchedReputation.TotalReviewsCount;
        reputation.TrustpilotUrl = fetchedReputation.TrustpilotUrl;
        reputation.CalculatedRefundRisk = fetchedReputation.CalculatedRefundRisk;
        reputation.RecurringComplaintsJson = fetchedReputation.RecurringComplaintsJson;
        reputation.SentimentSummary = fetchedReputation.SentimentSummary;
        reputation.LastAuditedAt = DateTimeOffset.UtcNow;
        reputation.UpdatedAt = DateTimeOffset.UtcNow;

        offer.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return (trend, reputation);
    }

    internal static string BrandQuery(Offer offer)
    {
        var site = offer.Merchant?.Website;
        if (!string.IsNullOrWhiteSpace(site) && Uri.TryCreate(site, UriKind.Absolute, out var uri)
            && uri.Host is not ("localhost" or "127.0.0.1")
            && !uri.Host.Contains("hop.clickbank.net", StringComparison.OrdinalIgnoreCase)
            && !uri.Host.Contains("digistore", StringComparison.OrdinalIgnoreCase))
            return uri.Host;
        return offer.Merchant?.Name ?? offer.Product?.Name ?? offer.Name;
    }

    public static IReadOnlyList<string> Complaints(OfferReputation? reputation)
    {
        if (reputation is null || string.IsNullOrWhiteSpace(reputation.RecurringComplaintsJson))
            return [];
        try
        {
            return JsonSerializer.Deserialize<List<string>>(reputation.RecurringComplaintsJson) ?? [];
        }
        catch (JsonException)
        {
            return [];
        }
    }
}
