using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Domain.Scoring;
using Microsoft.Extensions.Configuration;

namespace BottomFunnel.Infrastructure.MarketSignals;

public sealed class SearchVolumeService : ISearchVolumeService
{
    private readonly IHttpClientFactory _http;
    private readonly IConfiguration _config;

    public SearchVolumeService(IHttpClientFactory http, IConfiguration config)
    {
        _http = http;
        _config = config;
    }

    public async Task<OfferSearchTrend> FetchNinetyDayTrendAsync(string keyword, CancellationToken ct)
    {
        var phrase = (keyword ?? "").Trim();
        var volumes = await TryGoogleAdsAsync(phrase, ct)
                      ?? await TryDataForSeoAsync(phrase, ct)
                      ?? [];
        return FromVolumes(volumes);
    }

    public static OfferSearchTrend FromVolumes(IReadOnlyList<int> monthlyVolumes)
    {
        var lastThree = monthlyVolumes.TakeLast(3).ToList();
        while (lastThree.Count < 3)
            lastThree.Insert(0, 0);
        var m3 = lastThree[0];
        var m2 = lastThree[1];
        var m1 = lastThree[2];
        var (delta, direction) = SearchTrendCalculator.FromMonths(m3, m2, m1);
        return new OfferSearchTrend
        {
            CurrentMonthlyVolume = m1,
            NinetyDayChangePercent = delta,
            Direction = lastThree.All(v => v == 0) ? SearchTrendDirection.Unknown : direction,
            VolumeHistoryJson = JsonSerializer.Serialize(lastThree),
            LastRefreshedAt = DateTimeOffset.UtcNow
        };
    }

    private async Task<IReadOnlyList<int>?> TryGoogleAdsAsync(string keyword, CancellationToken ct)
    {
        var token = _config["GoogleAds:AccessToken"];
        var customerId = _config["GoogleAds:CustomerId"]?.Replace("-", "");
        var developerToken = _config["GoogleAds:DeveloperToken"];
        if (string.IsNullOrWhiteSpace(token) || string.IsNullOrWhiteSpace(customerId) || string.IsNullOrWhiteSpace(developerToken)
            || string.IsNullOrWhiteSpace(keyword))
            return null;

        try
        {
            var client = _http.CreateClient("googleads");
            using var request = new HttpRequestMessage(
                HttpMethod.Post,
                $"https://googleads.googleapis.com/v18/customers/{customerId}:generateKeywordHistoricalMetrics");
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            request.Headers.TryAddWithoutValidation("developer-token", developerToken);
            request.Content = new StringContent(JsonSerializer.Serialize(new
            {
                keywords = new[] { keyword },
                keywordPlanNetwork = "GOOGLE_SEARCH"
            }), Encoding.UTF8, "application/json");
            using var response = await client.SendAsync(request, ct);
            if (!response.IsSuccessStatusCode)
                return null;
            using var doc = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
            return ReadGoogleMonthly(doc.RootElement);
        }
        catch (Exception)
        {
            return null;
        }
    }

    private async Task<IReadOnlyList<int>?> TryDataForSeoAsync(string keyword, CancellationToken ct)
    {
        var login = _config["DataForSeo:Login"];
        var password = _config["DataForSeo:Password"];
        if (string.IsNullOrWhiteSpace(login) || string.IsNullOrWhiteSpace(password) || string.IsNullOrWhiteSpace(keyword))
            return null;

        try
        {
            var client = _http.CreateClient("dataforseo");
            using var request = new HttpRequestMessage(HttpMethod.Post, "https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live");
            var bytes = Encoding.UTF8.GetBytes($"{login}:{password}");
            request.Headers.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(bytes));
            request.Content = new StringContent(JsonSerializer.Serialize(new[]
            {
                new { keywords = new[] { keyword }, location_code = 2840, language_code = "en" }
            }), Encoding.UTF8, "application/json");
            using var response = await client.SendAsync(request, ct);
            if (!response.IsSuccessStatusCode)
                return null;
            using var doc = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
            return ReadDataForSeoMonthly(doc.RootElement);
        }
        catch (Exception)
        {
            return null;
        }
    }

    internal static IReadOnlyList<int>? ReadGoogleMonthly(JsonElement root)
    {
        if (!root.TryGetProperty("results", out var results) || results.GetArrayLength() == 0)
            return null;
        var first = results[0];
        if (!first.TryGetProperty("keywordMetrics", out var metrics))
            return null;
        if (metrics.TryGetProperty("monthlySearchVolumes", out var months))
            return months.EnumerateArray()
                .Select(m => (
                    Year: ReadInt(m, "year") ?? 0,
                    Month: MonthNumber(m),
                    Volume: ReadInt(m, "monthlySearches") ?? 0))
                .OrderBy(x => x.Year).ThenBy(x => x.Month)
                .Select(x => x.Volume)
                .ToList();
        if (metrics.TryGetProperty("avgMonthlySearches", out var avg))
        {
            var value = avg.GetInt32();
            return [value, value, value];
        }
        return null;
    }

    internal static IReadOnlyList<int>? ReadDataForSeoMonthly(JsonElement root)
    {
        if (!root.TryGetProperty("tasks", out var tasks) || tasks.GetArrayLength() == 0)
            return null;
        var result = tasks[0].GetProperty("result");
        if (result.ValueKind != JsonValueKind.Array || result.GetArrayLength() == 0)
            return null;
        var item = result[0];
        if (item.TryGetProperty("monthly_searches", out var months) && months.ValueKind == JsonValueKind.Array)
            return months.EnumerateArray()
                .Select(m => (
                    Year: ReadInt(m, "year") ?? 0,
                    Month: ReadInt(m, "month") ?? MonthNumber(m),
                    Volume: ReadInt(m, "search_volume") ?? 0))
                .OrderBy(x => x.Year).ThenBy(x => x.Month)
                .Select(x => x.Volume)
                .ToList();
        if (item.TryGetProperty("search_volume", out var volume))
        {
            var value = volume.GetInt32();
            return [value, value, value];
        }
        return null;
    }

    private static int? ReadInt(JsonElement node, string name)
    {
        if (!node.TryGetProperty(name, out var value))
            return null;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var n))
            return n;
        if (value.ValueKind == JsonValueKind.String && int.TryParse(value.GetString(), out var parsed))
            return parsed;
        return null;
    }

    private static int MonthNumber(JsonElement node)
    {
        if (node.TryGetProperty("month", out var month))
        {
            if (month.ValueKind == JsonValueKind.Number && month.TryGetInt32(out var n))
                return n;
            var name = month.GetString()?.ToUpperInvariant();
            return name switch
            {
                "JANUARY" => 1,
                "FEBRUARY" => 2,
                "MARCH" => 3,
                "APRIL" => 4,
                "MAY" => 5,
                "JUNE" => 6,
                "JULY" => 7,
                "AUGUST" => 8,
                "SEPTEMBER" => 9,
                "OCTOBER" => 10,
                "NOVEMBER" => 11,
                "DECEMBER" => 12,
                _ => 0
            };
        }
        return 0;
    }
}
