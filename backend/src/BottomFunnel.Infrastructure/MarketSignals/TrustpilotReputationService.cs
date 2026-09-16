using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Scoring;

namespace BottomFunnel.Infrastructure.MarketSignals;

public sealed class TrustpilotReputationService : IReputationAuditService
{
    private readonly IHttpClientFactory _http;
    private readonly IAiService _ai;

    public TrustpilotReputationService(IHttpClientFactory http, IAiService ai)
    {
        _http = http;
        _ai = ai;
    }

    public async Task<OfferReputation> AuditTrustpilotAsync(string domainOrBrand, CancellationToken ct)
    {
        var query = (domainOrBrand ?? "").Trim();
        var slug = ToTrustpilotSlug(query);
        var url = string.IsNullOrWhiteSpace(slug) ? null : $"https://www.trustpilot.com/review/{slug}";
        var html = url is null ? "" : await DownloadAsync(url, ct);
        if (string.IsNullOrWhiteSpace(html) && !LooksLikeDomain(query) && query.Length > 1)
        {
            url = $"https://www.trustpilot.com/review/{query.Replace(" ", "").ToLowerInvariant()}.com";
            html = await DownloadAsync(url, ct);
        }

        var snapshot = TrustpilotHtmlParser.Parse(html, url);
        var complaints = await ExtractComplaintsAsync(query, snapshot.OneStarSnippets, ct);
        var rating = snapshot.Rating.HasValue
            ? Math.Round(Math.Clamp(snapshot.Rating.Value, 0, 5), 1, MidpointRounding.AwayFromZero)
            : (decimal?)null;

        return new OfferReputation
        {
            TrustpilotRating = rating,
            TotalReviewsCount = snapshot.ReviewCount,
            TrustpilotUrl = snapshot.ProfileUrl,
            CalculatedRefundRisk = RefundRiskCalculator.From(rating, snapshot.ReviewCount),
            RecurringComplaintsJson = JsonSerializer.Serialize(complaints.Complaints),
            SentimentSummary = complaints.Summary,
            LastAuditedAt = DateTimeOffset.UtcNow
        };
    }

    internal static string ToTrustpilotSlug(string domainOrBrand)
    {
        var value = domainOrBrand.Trim();
        if (Uri.TryCreate(value, UriKind.Absolute, out var uri) && uri.Host.Length > 0)
            return uri.Host.ToLowerInvariant();
        if (LooksLikeDomain(value))
            return value.ToLowerInvariant().Trim('/');
        return value.ToLowerInvariant().Replace(" ", "");
    }

    private static bool LooksLikeDomain(string value) =>
        value.Contains('.') && !value.Contains(' ') && !value.Contains('/');

    private async Task<string> DownloadAsync(string url, CancellationToken ct)
    {
        try
        {
            var client = _http.CreateClient("trustpilot");
            using var response = await client.GetAsync(url, ct);
            if (!response.IsSuccessStatusCode)
                return "";
            return await response.Content.ReadAsStringAsync(ct);
        }
        catch (Exception)
        {
            return "";
        }
    }

    private async Task<(List<string> Complaints, string? Summary)> ExtractComplaintsAsync(
        string brand,
        IReadOnlyList<string> snippets,
        CancellationToken ct)
    {
        if (snippets.Count == 0)
            return ([], null);

        if (!await _ai.IsConfiguredAsync(ct))
            return (snippets.Take(3).Select(Shorten).ToList(), "Trustpilot one-star themes captured without AI.");

        try
        {
            var numbered = string.Join("\n", snippets.Select((s, i) => $"{i + 1}. {s}"));
            var json = await _ai.CompleteAsync(
                """
                You extract recurring buyer friction from one-star Trustpilot reviews.
                Return JSON only: {"complaints":["...","...","..."],"summary":"one sentence"}.
                Complaints must be short operational phrases (delayed shipping, denied refund, unresponsive support).
                """,
                $"Brand: {brand}\n\nOne-star reviews:\n{numbered}",
                ct);
            return ParseComplaints(json);
        }
        catch (Exception)
        {
            return (snippets.Take(3).Select(Shorten).ToList(), "Could not classify Trustpilot complaints with AI.");
        }
    }

    internal static (List<string> Complaints, string? Summary) ParseComplaints(string raw)
    {
        var text = (raw ?? "").Trim();
        var start = text.IndexOf('{');
        var end = text.LastIndexOf('}');
        if (start < 0 || end <= start)
            return ([], null);
        try
        {
            using var doc = JsonDocument.Parse(text[start..(end + 1)]);
            var complaints = new List<string>();
            if (doc.RootElement.TryGetProperty("complaints", out var array) && array.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in array.EnumerateArray())
                {
                    var value = item.GetString()?.Trim();
                    if (!string.IsNullOrWhiteSpace(value) && complaints.Count < 5)
                        complaints.Add(value);
                }
            }
            var summary = doc.RootElement.TryGetProperty("summary", out var s) ? s.GetString() : null;
            return (complaints, summary);
        }
        catch (JsonException)
        {
            return ([], null);
        }
    }

    private static string Shorten(string text) =>
        text.Length <= 80 ? text : text[..77] + "…";
}
