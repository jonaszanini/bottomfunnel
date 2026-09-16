using System.Globalization;
using System.Text.Json;
using BottomFunnel.Application.Abstractions;
using Microsoft.Extensions.Caching.Memory;

namespace BottomFunnel.Infrastructure.Currency;

public sealed class BankOfCanadaCurrencyService : ICurrencyExchangeService
{
    public const decimal FallbackUsdCad = 1.3800m;
    public const decimal FallbackEurCad = 1.5000m;
    private static readonly TimeSpan CacheDuration = TimeSpan.FromHours(24);

    private readonly IHttpClientFactory _httpClientFactory;
    private readonly IMemoryCache _cache;

    public BankOfCanadaCurrencyService(IHttpClientFactory httpClientFactory, IMemoryCache cache)
    {
        _httpClientFactory = httpClientFactory;
        _cache = cache;
    }

    public async Task<decimal> GetExchangeRateAsync(string fromCurrency, string toCurrency, CancellationToken ct = default)
    {
        var from = Normalize(fromCurrency);
        var to = Normalize(toCurrency);
        if (from == to)
            return 1m;

        var cacheKey = $"fx:{from}:{to}";
        if (_cache.TryGetValue(cacheKey, out decimal cached))
            return cached;

        var rate = await FetchRateAsync(from, to, ct);
        _cache.Set(cacheKey, rate, CacheDuration);
        return rate;
    }

    private async Task<decimal> FetchRateAsync(string from, string to, CancellationToken ct)
    {
        try
        {
            if (to == "CAD")
                return await FetchCadSeriesAsync($"FX{from}CAD", ct);
            if (from == "CAD")
            {
                var cadPerUnit = await FetchCadSeriesAsync($"FX{to}CAD", ct);
                return cadPerUnit == 0 ? Fallback(from, to) : Round(1m / cadPerUnit);
            }

            var fromCad = await FetchCadSeriesAsync($"FX{from}CAD", ct);
            var toCad = await FetchCadSeriesAsync($"FX{to}CAD", ct);
            if (toCad == 0)
                return Fallback(from, to);
            return Round(fromCad / toCad);
        }
        catch
        {
            return Fallback(from, to);
        }
    }

    private async Task<decimal> FetchCadSeriesAsync(string series, CancellationToken ct)
    {
        var client = _httpClientFactory.CreateClient("bankofcanada");
        using var response = await client.GetAsync($"valet/observations/{series}/json?recent=1", ct);
        var json = await response.Content.ReadAsStringAsync(ct);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Bank of Canada {series} failed ({(int)response.StatusCode}).");
        return ParseLatest(json, series);
    }

    internal static decimal ParseLatest(string json, string series)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(json) ? "{}" : json);
        if (!doc.RootElement.TryGetProperty("observations", out var observations) || observations.GetArrayLength() == 0)
            throw new InvalidOperationException("Bank of Canada returned no observations.");

        var last = observations[observations.GetArrayLength() - 1];
        if (!last.TryGetProperty(series, out var node) || !node.TryGetProperty("v", out var valueNode))
            throw new InvalidOperationException($"Bank of Canada observation missing {series}.");

        var raw = valueNode.GetString();
        if (!decimal.TryParse(raw, NumberStyles.Number, CultureInfo.InvariantCulture, out var rate) || rate <= 0)
            throw new InvalidOperationException($"Bank of Canada rate was not numeric: {raw}");
        return Round(rate);
    }

    private static decimal Fallback(string from, string to)
    {
        if (from == "USD" && to == "CAD")
            return FallbackUsdCad;
        if (from == "CAD" && to == "USD")
            return Round(1m / FallbackUsdCad);
        if (from == "EUR" && to == "CAD")
            return FallbackEurCad;
        if (from == "CAD" && to == "EUR")
            return Round(1m / FallbackEurCad);
        if (from == "EUR" && to == "USD")
            return Round(FallbackEurCad / FallbackUsdCad);
        if (from == "USD" && to == "EUR")
            return Round(FallbackUsdCad / FallbackEurCad);
        return from == "USD" || to == "CAD" ? FallbackUsdCad : 1m;
    }

    private static string Normalize(string currency)
    {
        if (string.IsNullOrWhiteSpace(currency) || currency.Trim().Length != 3)
            throw new InvalidOperationException("Currency must be a 3-letter code.");
        return currency.Trim().ToUpperInvariant();
    }

    private static decimal Round(decimal value) => Math.Round(value, 4, MidpointRounding.AwayFromZero);
}
