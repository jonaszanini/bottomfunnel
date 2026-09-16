using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public sealed class AuditOfferCommandHandler
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly IAiService _ai;
    private readonly IHttpClientFactory _http;

    public AuditOfferCommandHandler(IAppDbContext db, ICurrentUser current, IAiService ai, IHttpClientFactory http)
    {
        _db = db;
        _current = current;
        _ai = ai;
        _http = http;
    }

    public async Task<OfferPolicyAuditResult> HandleAsync(AuditOfferCommand command, CancellationToken ct)
    {
        if (!await _ai.IsConfiguredAsync(ct))
            throw new InvalidOperationException("Configure an AI provider in Settings before running a policy audit.");

        var offer = await _db.Offers
            .Include(x => x.Product)
            .Include(x => x.Merchant)
            .FirstOrDefaultAsync(x => x.Id == command.OfferId && x.OrganizationId == _current.OrganizationId, ct)
            ?? throw new KeyNotFoundException("Offer not found.");

        var productName = offer.Product?.Name ?? offer.Name;
        var salesPage = await DownloadAsync(offer.AffiliateUrl, ct);
        var termsPage = offer.Merchant?.Website is { Length: > 0 } site
                        && !string.Equals(site, offer.AffiliateUrl, StringComparison.OrdinalIgnoreCase)
            ? await DownloadAsync(site, ct)
            : "";
        var terms = string.Join("\n\n", new[]
        {
            termsPage,
            offer.TrademarkNotes,
            offer.AdRestrictions,
            offer.LandingPageRestrictions,
            offer.PolicyNotes,
            offer.Merchant?.TrademarkNotes,
            offer.Merchant?.AdRestrictions,
            offer.Merchant?.PolicyNotes
        }.Where(x => !string.IsNullOrWhiteSpace(x)));
        var restrictions = offer.TrafficRestrictions?.Trim();

        if (string.IsNullOrWhiteSpace(salesPage) && string.IsNullOrWhiteSpace(terms) && string.IsNullOrWhiteSpace(restrictions))
            throw new InvalidOperationException("Add a hop / tracking URL, merchant website, or traffic restrictions so the auditor has copy to review.");

        var result = await _ai.AuditOfferSalesPageAsync(
            productName,
            salesPage,
            string.IsNullOrWhiteSpace(terms) ? null : terms,
            restrictions,
            ct);
        result = OfferTrafficRulesEnforcer.Apply(result, restrictions, productName);
        var json = JsonSerializer.Serialize(result, new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            WriteIndented = false
        });

        _db.OfferAudits.Add(new OfferAudit
        {
            OrganizationId = _current.OrganizationId,
            OfferId = offer.Id,
            RiskScore = result.RiskScore,
            OverallRisk = result.OverallRisk.ToString(),
            AllowsBrandBidding = result.AllowsBrandBidding,
            ResultJson = json
        });
        if (!result.AllowsBrandBidding)
            offer.BrandBiddingAllowed = false;
        offer.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return result;
    }

    public async Task<OfferPolicyAuditResult?> GetLatestAsync(Guid offerId, CancellationToken ct)
    {
        var row = await _db.OfferAudits
            .Where(x => x.OrganizationId == _current.OrganizationId && x.OfferId == offerId)
            .OrderByDescending(x => x.CreatedAt)
            .FirstOrDefaultAsync(ct);
        return row is null ? null : OfferPolicyAuditParser.Parse(row.ResultJson);
    }

    public async Task<int> AddNegativesAsync(AddAuditNegativesCommand command, CancellationToken ct)
    {
        var offer = await _db.Offers.FirstOrDefaultAsync(
            x => x.Id == command.OfferId && x.OrganizationId == _current.OrganizationId, ct)
            ?? throw new KeyNotFoundException("Offer not found.");

        var phrases = (command.Phrases ?? [])
            .Select(x => x.Trim())
            .Where(x => x.Length > 0)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
        if (phrases.Count == 0)
            return 0;

        var existing = await _db.Keywords
            .Where(x => x.OrganizationId == _current.OrganizationId && x.ProductId == offer.ProductId)
            .ToListAsync(ct);
        var added = 0;
        foreach (var phrase in phrases)
        {
            var keyword = existing.FirstOrDefault(x => x.Phrase.Equals(phrase, StringComparison.OrdinalIgnoreCase));
            if (keyword is null)
            {
                keyword = new Keyword
                {
                    OrganizationId = _current.OrganizationId,
                    ProductId = offer.ProductId,
                    Phrase = phrase,
                    Template = KeywordTemplateKind.Negative,
                    Intent = SearchIntent.Informational,
                    IntentScore = 0
                };
                _db.Keywords.Add(keyword);
                existing.Add(keyword);
                added++;
            }
            keyword.Template = KeywordTemplateKind.Negative;
            keyword.ComplianceStatus = ComplianceState.Prohibited;
            keyword.BlockedReason = "AI policy audit negative keyword";
            keyword.UpdatedAt = DateTimeOffset.UtcNow;
        }

        await _db.SaveChangesAsync(ct);
        return added;
    }

    private async Task<string> DownloadAsync(string? url, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(url) || !Uri.TryCreate(url, UriKind.Absolute, out var uri)
            || uri.Scheme is not ("http" or "https"))
            return "";
        try
        {
            var client = _http.CreateClient("webpage");
            using var response = await client.GetAsync(uri, ct);
            if (!response.IsSuccessStatusCode)
                return "";
            var html = await response.Content.ReadAsStringAsync(ct);
            return HtmlContentSanitizer.ToPlainText(html);
        }
        catch (Exception)
        {
            return "";
        }
    }
}
