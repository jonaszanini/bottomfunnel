using System.Text.Json;
using System.Text.RegularExpressions;

namespace BottomFunnel.Infrastructure.MarketSignals;

public sealed record TrustpilotSnapshot(
    decimal? Rating,
    int ReviewCount,
    IReadOnlyList<string> OneStarSnippets,
    string? ProfileUrl);

public static class TrustpilotHtmlParser
{
    private static readonly Regex LdJson = new(
        @"<script[^>]*type\s*=\s*[""']application/ld\+json[""'][^>]*>([\s\S]*?)</script>",
        RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex OneStarCard = new(
        @"data-service-review-rating\s*=\s*[""']1(?:\.0)?[""'][\s\S]{0,1200}?(?:data-service-review-text-typography[""'][^>]*>|itemprop\s*=\s*[""']reviewBody[""'][^>]*>)([\s\S]*?)</",
        RegexOptions.IgnoreCase | RegexOptions.Compiled);

    public static TrustpilotSnapshot Parse(string html, string? profileUrl = null)
    {
        decimal? rating = null;
        var count = 0;
        var snippets = new List<string>();

        foreach (Match match in LdJson.Matches(html ?? ""))
        {
            var json = match.Groups[1].Value.Trim();
            if (json.Length == 0)
                continue;
            try
            {
                using var doc = JsonDocument.Parse(json);
                Walk(doc.RootElement, ref rating, ref count, snippets);
            }
            catch (JsonException)
            {
                /* skip malformed ld+json */
            }
        }

        if (snippets.Count == 0)
        {
            foreach (Match match in OneStarCard.Matches(html ?? ""))
                AddSnippet(snippets, StripTags(match.Groups[1].Value));
        }

        return new TrustpilotSnapshot(rating, count, snippets.Take(10).ToList(), profileUrl);
    }

    private static void Walk(JsonElement node, ref decimal? rating, ref int count, List<string> snippets)
    {
        if (node.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in node.EnumerateArray())
                Walk(item, ref rating, ref count, snippets);
            return;
        }
        if (node.ValueKind != JsonValueKind.Object)
            return;

        var type = ReadType(node);
        if (string.Equals(type, "AggregateRating", StringComparison.OrdinalIgnoreCase)
            || node.TryGetProperty("aggregateRating", out _))
        {
            var ratingNode = string.Equals(type, "AggregateRating", StringComparison.OrdinalIgnoreCase)
                ? node
                : node.GetProperty("aggregateRating");
            rating ??= ReadDecimal(ratingNode, "ratingValue");
            if (count == 0)
                count = ReadInt(ratingNode, "reviewCount") ?? ReadInt(ratingNode, "ratingCount") ?? 0;
        }

        if (string.Equals(type, "Review", StringComparison.OrdinalIgnoreCase))
        {
            var stars = 5m;
            if (node.TryGetProperty("reviewRating", out var reviewRating))
                stars = ReadDecimal(reviewRating, "ratingValue") ?? stars;
            if (stars <= 1.5m)
            {
                var body = ReadString(node, "reviewBody") ?? ReadString(node, "description");
                AddSnippet(snippets, body);
            }
        }

        foreach (var property in node.EnumerateObject())
        {
            if (property.Value.ValueKind is JsonValueKind.Object or JsonValueKind.Array)
                Walk(property.Value, ref rating, ref count, snippets);
        }
    }

    private static string? ReadType(JsonElement node)
    {
        if (!node.TryGetProperty("@type", out var type))
            return null;
        if (type.ValueKind == JsonValueKind.String)
            return type.GetString();
        if (type.ValueKind == JsonValueKind.Array && type.GetArrayLength() > 0)
            return type[0].GetString();
        return null;
    }

    private static decimal? ReadDecimal(JsonElement node, string name)
    {
        if (!node.TryGetProperty(name, out var value))
            return null;
        if (value.ValueKind == JsonValueKind.Number)
            return value.GetDecimal();
        if (value.ValueKind == JsonValueKind.String && decimal.TryParse(value.GetString(), out var parsed))
            return parsed;
        return null;
    }

    private static int? ReadInt(JsonElement node, string name)
    {
        if (!node.TryGetProperty(name, out var value))
            return null;
        if (value.ValueKind == JsonValueKind.Number)
            return value.GetInt32();
        if (value.ValueKind == JsonValueKind.String && int.TryParse(value.GetString(), out var parsed))
            return parsed;
        return null;
    }

    private static string? ReadString(JsonElement node, string name) =>
        node.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString()
            : null;

    private static void AddSnippet(List<string> snippets, string? text)
    {
        var clean = (text ?? "").Trim();
        if (clean.Length < 8 || snippets.Count >= 10)
            return;
        if (snippets.Any(x => x.Equals(clean, StringComparison.OrdinalIgnoreCase)))
            return;
        snippets.Add(clean.Length > 600 ? clean[..600] : clean);
    }

    private static string StripTags(string html)
    {
        var text = Regex.Replace(html, "<[^>]+>", " ");
        return Regex.Replace(text, @"\s+", " ").Trim();
    }
}
