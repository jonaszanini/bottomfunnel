using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Compliance;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class CampaignService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;

    public CampaignService(IAppDbContext db, ICurrentUser current)
    {
        _db = db;
        _current = current;
    }

    private Guid OrgId => _current.OrganizationId;

    public async Task<IReadOnlyList<CampaignDto>> ListAsync(CancellationToken ct)
    {
        var campaigns = await _db.Campaigns.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .Include(x => x.Keywords)
            .Include(x => x.Ads)
            .Include(x => x.LandingPage)
            .OrderByDescending(x => x.UpdatedAt)
            .ToListAsync(ct);
        return campaigns.Select(ToDto).ToList();
    }

    public async Task<CampaignDto> GetAsync(Guid id, CancellationToken ct) => ToDto(await Load(id, ct));

    public async Task<CampaignDto> CreateAsync(UpsertCampaignRequest request, CancellationToken ct)
    {
        var entity = new Campaign
        {
            OrganizationId = OrgId,
            TrackingCode = GenerateTrackingCode()
        };
        await ApplyAsync(entity, request, ct);
        _db.Campaigns.Add(entity);
        SyncKeywords(entity, request.KeywordIds);
        await _db.SaveChangesAsync(ct);
        return ToDto(await Load(entity.Id, ct));
    }

    public async Task<CampaignDto> UpdateAsync(Guid id, UpsertCampaignRequest request, CancellationToken ct)
    {
        var entity = await Load(id, ct);
        await ApplyAsync(entity, request, ct);
        entity.UpdatedAt = DateTimeOffset.UtcNow;
        SyncKeywords(entity, request.KeywordIds);
        await _db.SaveChangesAsync(ct);
        return ToDto(await Load(id, ct));
    }

    public async Task DeleteAsync(Guid id, CancellationToken ct)
    {
        var entity = await Load(id, ct);
        _db.Campaigns.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    public async Task<AdDto> AddAdAsync(Guid campaignId, UpsertAdRequest request, CancellationToken ct)
    {
        var campaign = await Load(campaignId, ct);
        var finalUrl = request.FinalUrl;
        if (string.IsNullOrWhiteSpace(finalUrl))
            finalUrl = GoogleFinalPath(campaign.LandingPage?.Slug);

        var ad = new Ad
        {
            OrganizationId = OrgId,
            CampaignId = campaignId,
            Headline = request.Headline,
            Description = request.Description,
            FinalUrl = finalUrl,
            IsActive = request.IsActive
        };
        _db.Ads.Add(ad);
        await _db.SaveChangesAsync(ct);
        return new AdDto(ad.Id, ad.CampaignId, ad.Headline, ad.Description, ad.FinalUrl, ad.IsActive);
    }

    public async Task DeleteAdAsync(Guid campaignId, Guid adId, CancellationToken ct)
    {
        var ad = await _db.Ads.FirstOrDefaultAsync(x => x.Id == adId && x.CampaignId == campaignId && x.OrganizationId == OrgId, ct)
                 ?? throw new KeyNotFoundException("Ad not found.");
        _db.Ads.Remove(ad);
        await _db.SaveChangesAsync(ct);
    }

    private async Task<Campaign> Load(Guid id, CancellationToken ct) =>
        await _db.Campaigns
            .Include(x => x.Keywords)
            .Include(x => x.Ads)
            .Include(x => x.LandingPage)
            .FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
        ?? throw new KeyNotFoundException("Campaign not found.");

    private async Task ApplyAsync(Campaign entity, UpsertCampaignRequest request, CancellationToken ct)
    {
        entity.Name = request.Name.Trim();
        entity.OfferId = request.OfferId;
        entity.ProductId = request.ProductId;
        entity.OpportunityId = request.OpportunityId;
        entity.LandingPageId = request.LandingPageId;
        entity.Country = request.Country;

        if (request.Status == CampaignStatus.Live)
            await EnsurePublishedLandingPageAsync(request.LandingPageId ?? entity.LandingPageId, ct);

        entity.Status = request.Status;
    }

    private async Task EnsurePublishedLandingPageAsync(Guid? landingPageId, CancellationToken ct)
    {
        if (!landingPageId.HasValue)
            throw new InvalidOperationException("A published landing page is required before a campaign can go Live. Google Ads final URL must be /p/{slug}, never /t/{code}.");

        var page = await _db.LandingPages.FirstOrDefaultAsync(x => x.Id == landingPageId.Value && x.OrganizationId == OrgId, ct)
                   ?? throw new InvalidOperationException("Landing page not found.");
        if (!page.IsPublished)
            throw new InvalidOperationException("Publish the landing page before setting the campaign Live.");
        if (!LandingPageCompleteness.CanPublish(page, out var reason))
            throw new InvalidOperationException(reason ?? "Landing page is incomplete.");
    }

    private void SyncKeywords(Campaign entity, IReadOnlyList<Guid>? keywordIds)
    {
        var desired = (keywordIds ?? []).Distinct().ToHashSet();
        var existing = entity.Keywords.ToList();
        foreach (var row in existing.Where(x => !desired.Contains(x.KeywordId)))
            _db.CampaignKeywords.Remove(row);
        foreach (var keywordId in desired.Where(id => existing.All(x => x.KeywordId != id)))
        {
            _db.CampaignKeywords.Add(new CampaignKeyword
            {
                OrganizationId = OrgId,
                CampaignId = entity.Id,
                KeywordId = keywordId
            });
        }
    }

    public static CampaignDto ToDto(Campaign x)
    {
        var slug = x.LandingPage?.Slug;
        return new(
            x.Id, x.Name, x.Status, x.OfferId, x.ProductId, x.LandingPageId, slug, x.TrackingCode,
            GoogleFinalPath(slug),
            slug is null ? null : $"/t/{x.TrackingCode}",
            x.Country,
            x.Keywords.Select(k => k.KeywordId).ToList(),
            x.Ads.Select(a => new AdDto(a.Id, a.CampaignId, a.Headline, a.Description, a.FinalUrl, a.IsActive)).ToList());
    }

    public static string? GoogleFinalPath(string? slug) =>
        string.IsNullOrWhiteSpace(slug) ? null : $"/p/{slug}";

    private static string GenerateTrackingCode() => Convert.ToHexString(Guid.NewGuid().ToByteArray())[..12].ToLowerInvariant();
}

public class LandingPageService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;

    public LandingPageService(IAppDbContext db, ICurrentUser current)
    {
        _db = db;
        _current = current;
    }

    private Guid OrgId => _current.OrganizationId;

    public async Task<IReadOnlyList<LandingPageDto>> ListAsync(CancellationToken ct)
    {
        var pages = await _db.LandingPages.AsNoTracking()
            .Where(x => x.OrganizationId == OrgId)
            .OrderByDescending(x => x.UpdatedAt)
            .ToListAsync(ct);
        var codes = await TrackingCodesByPage(pages.Select(p => p.Id), ct);
        return pages.Select(p => ToDto(p, codes.GetValueOrDefault(p.Id))).ToList();
    }

    public async Task<LandingPageDto> GetAsync(Guid id, CancellationToken ct)
    {
        var page = await Load(id, ct);
        var codes = await TrackingCodesByPage([page.Id], ct);
        return ToDto(page, codes.GetValueOrDefault(page.Id));
    }

    public async Task<LandingPageDto?> GetBySlugAsync(string slug, CancellationToken ct)
    {
        var page = await _db.LandingPages.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Slug == slug && x.IsPublished, ct);
        if (page is null)
            return null;
        var codes = await TrackingCodesByPage([page.Id], ct);
        return ToDto(page, codes.GetValueOrDefault(page.Id));
    }

    public async Task<LandingPageDto> CreateAsync(UpsertLandingPageRequest request, CancellationToken ct)
    {
        var entity = new LandingPage { OrganizationId = OrgId };
        Apply(entity, request);
        _db.LandingPages.Add(entity);
        await _db.SaveChangesAsync(ct);
        return ToDto(entity, null);
    }

    public async Task<LandingPageDto> UpdateAsync(Guid id, UpsertLandingPageRequest request, CancellationToken ct)
    {
        var entity = await Load(id, ct);
        Apply(entity, request);
        entity.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        var codes = await TrackingCodesByPage([entity.Id], ct);
        return ToDto(entity, codes.GetValueOrDefault(entity.Id));
    }

    public async Task DeleteAsync(Guid id, CancellationToken ct)
    {
        var entity = await Load(id, ct);
        _db.LandingPages.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    private async Task<LandingPage> Load(Guid id, CancellationToken ct) =>
        await _db.LandingPages.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
        ?? throw new KeyNotFoundException("Landing page not found.");

    private async Task<Dictionary<Guid, string>> TrackingCodesByPage(IEnumerable<Guid> pageIds, CancellationToken ct)
    {
        var ids = pageIds.Distinct().ToList();
        if (ids.Count == 0)
            return [];

        var campaigns = await _db.Campaigns.AsNoTracking()
            .Where(x => x.LandingPageId != null && ids.Contains(x.LandingPageId.Value)
                        && (x.Status == CampaignStatus.Live || x.Status == CampaignStatus.Ready))
            .OrderByDescending(x => x.Status == CampaignStatus.Live)
            .ThenByDescending(x => x.UpdatedAt)
            .ToListAsync(ct);

        return campaigns
            .GroupBy(x => x.LandingPageId!.Value)
            .ToDictionary(g => g.Key, g => g.First().TrackingCode);
    }

    private static void Apply(LandingPage entity, UpsertLandingPageRequest request)
    {
        entity.Name = request.Name.Trim();
        entity.Slug = request.Slug.Trim().ToLowerInvariant();
        entity.Headline = request.Headline;
        entity.Subheadline = request.Subheadline;
        entity.BenefitsJson = JsonSerializer.Serialize(request.Benefits ?? []);
        entity.ComparisonJson = request.Comparison;
        entity.CtaText = string.IsNullOrWhiteSpace(request.CtaText) ? "Get it now" : request.CtaText;
        entity.FaqJson = JsonSerializer.Serialize(request.Faqs ?? []);
        entity.Body = request.Body;
        entity.Disclosure = request.Disclosure;
        entity.WhoItsFor = request.WhoItsFor;
        entity.WhoItsNotFor = request.WhoItsNotFor;
        entity.ProsJson = JsonSerializer.Serialize(request.Pros ?? []);
        entity.ConsJson = JsonSerializer.Serialize(request.Cons ?? []);
        entity.Alternatives = request.Alternatives;
        entity.OfferId = request.OfferId;
        entity.ProductId = request.ProductId;

        if (request.IsPublished)
        {
            if (!LandingPageCompleteness.CanPublish(entity, out var reason))
                throw new InvalidOperationException(reason ?? "Landing page is incomplete.");
            entity.IsPublished = true;
        }
        else
        {
            entity.IsPublished = false;
        }
    }

    public static LandingPageDto ToDto(LandingPage x, string? trackingCode)
    {
        var google = CampaignService.GoogleFinalPath(x.Slug);
        return new(
            x.Id, x.Name, x.Slug, x.Headline, x.Subheadline,
            DeserializeList(x.BenefitsJson),
            x.ComparisonJson,
            x.CtaText,
            DeserializeFaqs(x.FaqJson),
            x.Body,
            x.Disclosure,
            x.WhoItsFor,
            x.WhoItsNotFor,
            DeserializeList(x.ProsJson),
            DeserializeList(x.ConsJson),
            x.Alternatives,
            x.IsPublished,
            x.OfferId,
            x.ProductId,
            trackingCode,
            google,
            trackingCode is null ? null : $"/t/{trackingCode}");
    }

    private static IReadOnlyList<string> DeserializeList(string? json) =>
        string.IsNullOrWhiteSpace(json) ? [] : JsonSerializer.Deserialize<List<string>>(json) ?? [];

    private static IReadOnlyList<FaqItemDto> DeserializeFaqs(string? json) =>
        string.IsNullOrWhiteSpace(json) ? [] : JsonSerializer.Deserialize<List<FaqItemDto>>(json) ?? [];
}
