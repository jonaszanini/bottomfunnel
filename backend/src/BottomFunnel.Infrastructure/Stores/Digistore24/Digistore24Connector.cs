using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Enums;
using Microsoft.Extensions.Logging;

namespace BottomFunnel.Infrastructure.Stores.Digistore24;

public sealed class Digistore24Connector : IStoreConnector
{
    public const string ApiRoot = "https://www.digistore24.com/api/call";
    public const string PingUrl = "https://www.digistore24.com/api/call/ping";
    public const string MarketplaceUrl = "https://www.digistore24.com/api/call/listMarketplaceEntries";
    public const string BrowserUserAgent =
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36";
    public const string FirewallMessage =
        "Digistore24 firewall blocked the request (403 Forbidden). Verify endpoint formatting and User-Agent headers.";

    private readonly IHttpClientFactory _httpClientFactory;
    private readonly ILogger<Digistore24Connector> _logger;

    public Digistore24Connector(IHttpClientFactory httpClientFactory, ILogger<Digistore24Connector> logger)
    {
        _httpClientFactory = httpClientFactory;
        _logger = logger;
    }

    public string ProviderKey => "digistore24";
    public string DisplayName => "Digistore24";
    public StoreCapabilities Capabilities =>
        StoreCapabilities.CanListProducts | StoreCapabilities.CanBuildAffiliateUrl | StoreCapabilities.CanReceiveWebhooks | StoreCapabilities.CanListOrders;

    public IReadOnlyList<CredentialField> CredentialSchema { get; } =
    [
        new("affiliateId", "Affiliate ID", false, true, "used to auto-generate hop links"),
        new("apiKey", "API key", true, true, "used to authenticate marketplace discovery"),
        new("ipnPassphrase", "IPN / Webhook Passphrase", true, false, "used to verify incoming sale postbacks")
    ];

    public async Task TestConnectionAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var json = await SendAsync(credentials, PingUrl, cancellationToken);
        EnsureApiResult(json);
    }

    public async Task<IReadOnlyList<CanonicalProduct>> ListProductsAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var json = await SendAsync(credentials, MarketplaceUrl, cancellationToken);
        EnsureApiResult(json);
        return MapProducts(json);
    }

    public string BuildTrackedAffiliateUrl(CanonicalOfferContext offer, string clickId, IReadOnlyDictionary<string, string> credentials)
    {
        if (!string.IsNullOrWhiteSpace(offer.AffiliateUrl))
            return AppendSid(offer.AffiliateUrl, clickId);
        if (!string.IsNullOrWhiteSpace(offer.AffiliateUrlTemplate))
            return offer.AffiliateUrlTemplate.Replace("{clickId}", clickId, StringComparison.OrdinalIgnoreCase);

        credentials.TryGetValue("affiliateId", out var affiliateId);
        var productId = offer.ExternalProductId ?? "0";
        var url = $"https://www.digistore24.com/redir/{productId}/{affiliateId ?? "affiliate"}/";
        return $"{url}?sid={Uri.EscapeDataString(clickId)}";
    }

    public bool TryParseWebhook(IncomingWebhook request, IReadOnlyDictionary<string, string> credentials, out CanonicalConversion? conversion)
    {
        conversion = null;
        var form = request.Form.Count > 0 ? request.Form : ParseForm(request.Body);
        if (credentials.TryGetValue("ipnPassphrase", out var passphrase) && !string.IsNullOrWhiteSpace(passphrase))
        {
            form.TryGetValue("sha_sign", out var sha);
            form.TryGetValue("sha_passphrase", out var providedPass);
            if (!string.IsNullOrWhiteSpace(providedPass) && !string.Equals(providedPass, passphrase, StringComparison.Ordinal))
                return false;
            if (!string.IsNullOrWhiteSpace(sha) && !VerifySha(form, passphrase, sha))
                return false;
        }

        form.TryGetValue("event", out var eventName);
        form.TryGetValue("order_id", out var orderId);
        form.TryGetValue("transaction_id", out var txn);
        form.TryGetValue("amount", out var amountRaw);
        form.TryGetValue("amount_brutto", out var amountGross);
        form.TryGetValue("affiliate_commission", out var commissionRaw);
        form.TryGetValue("currency", out var currency);
        form.TryGetValue("sid", out var sid);
        form.TryGetValue("custom", out var custom);

        var eventType = (eventName ?? "").ToLowerInvariant() switch
        {
            "refund" or "payment_missed" => ConversionEventType.Refund,
            "chargeback" => ConversionEventType.Chargeback,
            "rebilling" or "payment" when form.GetValueOrDefault("is_rebill") == "1" => ConversionEventType.Rebill,
            _ => ConversionEventType.Sale
        };

        decimal.TryParse(amountRaw ?? amountGross, System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture, out var amount);
        decimal.TryParse(commissionRaw, System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture, out var commission);

        conversion = new CanonicalConversion(
            sid ?? custom,
            orderId ?? txn,
            eventType,
            amount,
            commission,
            string.IsNullOrWhiteSpace(currency) ? "EUR" : currency,
            DateTimeOffset.UtcNow,
            request.Body);
        return true;
    }

    internal static IReadOnlyList<CanonicalProduct> MapProducts(string json)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(json) ? "{}" : json);
        var root = doc.RootElement;
        JsonElement data = root;
        if (root.TryGetProperty("data", out var dataEl))
            data = dataEl;

        var items = new List<JsonElement>();
        CollectEntries(data, items);

        return items.Select(MapProduct).Where(x => !string.IsNullOrWhiteSpace(x.ExternalProductId)).ToList();
    }

    private static CanonicalProduct MapProduct(JsonElement item)
    {
        var id = ReadString(item, "product_id", "main_product_id", "id", "productId") ?? "";
        var name = ReadString(item, "headline", "name", "product_name", "title") ?? id;
        var description = ReadString(item, "description", "note");
        decimal? price = ReadDecimal(item, "price", "amount", "sales_price");
        decimal commission = ReadDecimal(item, "stats_affiliate_profit_sale", "affiliate_share", "affiliate_commission", "commission", "commission_value") ?? 0;
        if (commission == 0)
            commission = ReadDecimal(item, "stats_stars") ?? 0;
        var currency = ReadString(item, "currency") ?? "EUR";
        var vendor = ReadString(item, "vendor_name", "merchant_name", "merchant", "vendor");
        var recurring = string.Equals(ReadString(item, "billing_type", "payment_type"), "subscription", StringComparison.OrdinalIgnoreCase);
        var url = ReadString(item, "salespage_url", "sales_page_url", "support_url", "order_form_url", "buy_url", "url");
        if (commission == 0 && item.TryGetProperty("stats", out var stats) && stats.ValueKind == JsonValueKind.Object)
            commission = ReadDecimal(stats, "affiliate_commission", "earnings_per_sale", "earnings_per_order", "conversion_rate") ?? 0;

        return new CanonicalProduct(
            id,
            name,
            description,
            ReadString(item, "product_category", "category"),
            vendor,
            ReadString(item, "vendor_id"),
            currency,
            price,
            price,
            commission,
            ReadDecimal(item, "rebill_commission"),
            recurring ? CommissionType.Recurring : CommissionType.OneTime,
            url,
            ReadString(item, "country", "language"),
            OfferStatus.Active,
            item.GetRawText());
    }

    private static void CollectEntries(JsonElement node, List<JsonElement> items)
    {
        if (node.ValueKind == JsonValueKind.Array)
        {
            foreach (var el in node.EnumerateArray())
                CollectEntries(el, items);
            return;
        }

        if (node.ValueKind != JsonValueKind.Object)
            return;

        if (node.TryGetProperty("entries", out var entries) && entries.ValueKind == JsonValueKind.Array)
        {
            foreach (var el in entries.EnumerateArray())
            {
                if (el.ValueKind == JsonValueKind.Object)
                    items.Add(el);
            }
            return;
        }

        var productId = ReadString(node, "product_id", "productId");
        var name = ReadString(node, "name", "product_name", "title", "headline");
        if (productId is not null || (ReadString(node, "id") is not null && name is not null))
        {
            items.Add(node);
            return;
        }

        foreach (var prop in node.EnumerateObject())
        {
            if (prop.Value.ValueKind is JsonValueKind.Array or JsonValueKind.Object
                && prop.Name is not ("stats" or "vendor" or "merchant"))
                CollectEntries(prop.Value, items);
        }
    }

    internal static string Endpoint(string function) =>
        $"{ApiRoot.TrimEnd('/')}/{function.Trim().TrimStart('/')}";

    private async Task<string> SendAsync(
        IReadOnlyDictionary<string, string> credentials,
        string url,
        CancellationToken cancellationToken,
        IReadOnlyDictionary<string, string>? parameters = null)
    {
        if (!credentials.TryGetValue("apiKey", out var apiKey) || string.IsNullOrWhiteSpace(apiKey))
            throw new InvalidOperationException("Digistore24 API key is required.");

        var client = _httpClientFactory.CreateClient("digistore24");
        var hasParameters = parameters is { Count: > 0 };
        using var request = new HttpRequestMessage(hasParameters ? HttpMethod.Post : HttpMethod.Get, url);
        request.Headers.TryAddWithoutValidation("X-DS-API-KEY", apiKey.Trim());
        request.Headers.TryAddWithoutValidation("Accept", "application/json");
        request.Headers.TryAddWithoutValidation("User-Agent", BrowserUserAgent);
        if (hasParameters)
            request.Content = new FormUrlEncodedContent(parameters!);

        using var response = await client.SendAsync(request, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw ApiFailure(response.StatusCode, body);
        return body;
    }

    private InvalidOperationException ApiFailure(System.Net.HttpStatusCode status, string body)
    {
        var trimmed = body.TrimStart();
        if (trimmed.StartsWith('<'))
        {
            _logger.LogWarning("Digistore24 returned HTML {Status}: {Body}", (int)status, body);
            return new InvalidOperationException(FirewallMessage);
        }

        var message = TryReadErrorMessage(body);
        if (message is not null)
            return new InvalidOperationException(message);
        return new InvalidOperationException($"Digistore24 request failed ({(int)status}).");
    }

    private static void EnsureApiResult(string json)
    {
        var message = TryReadErrorMessage(json);
        if (message is not null)
            throw new InvalidOperationException(message);
    }

    internal static string? TryReadErrorMessage(string json)
    {
        var trimmed = (json ?? "").TrimStart();
        if (trimmed.Length == 0 || trimmed.StartsWith('<') || trimmed[0] is not ('{' or '['))
            return null;
        try
        {
            using var doc = JsonDocument.Parse(trimmed);
            if (doc.RootElement.ValueKind != JsonValueKind.Object)
                return null;
            if (!doc.RootElement.TryGetProperty("result", out var result)
                || !string.Equals(result.GetString(), "error", StringComparison.OrdinalIgnoreCase))
                return null;
            return doc.RootElement.TryGetProperty("message", out var message)
                ? message.GetString() ?? "Digistore24 error"
                : "Digistore24 error";
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private static string AppendSid(string url, string clickId) =>
        url.Contains('?') ? $"{url}&sid={Uri.EscapeDataString(clickId)}" : $"{url}?sid={Uri.EscapeDataString(clickId)}";

    private static string? ReadString(JsonElement item, params string[] names)
    {
        foreach (var name in names)
        {
            if (item.TryGetProperty(name, out var el) && el.ValueKind is JsonValueKind.String)
                return el.GetString();
            if (item.TryGetProperty(name, out el) && el.ValueKind is JsonValueKind.Number)
                return el.ToString();
        }
        return null;
    }

    private static decimal? ReadDecimal(JsonElement item, params string[] names)
    {
        foreach (var name in names)
        {
            if (!item.TryGetProperty(name, out var el))
                continue;
            if (el.TryGetDecimal(out var value))
                return value;
            if (el.ValueKind == JsonValueKind.String && decimal.TryParse(el.GetString(), System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture, out var parsed))
                return parsed;
        }
        return null;
    }

    private static bool VerifySha(IReadOnlyDictionary<string, string> form, string passphrase, string sha)
    {
        var payload = string.Join("", form.Where(x => !x.Key.Equals("sha_sign", StringComparison.OrdinalIgnoreCase))
            .OrderBy(x => x.Key, StringComparer.Ordinal)
            .Select(x => x.Value)) + passphrase;
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(payload)));
        return hash.Equals(sha, StringComparison.OrdinalIgnoreCase);
    }

    private static Dictionary<string, string> ParseForm(string body)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        if (string.IsNullOrWhiteSpace(body))
            return result;
        if (body.TrimStart().StartsWith('{'))
        {
            using var doc = JsonDocument.Parse(body);
            foreach (var prop in doc.RootElement.EnumerateObject())
                result[prop.Name] = prop.Value.ToString();
            return result;
        }
        foreach (var pair in body.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            result[Uri.UnescapeDataString(parts[0].Replace("+", " "))] =
                parts.Length > 1 ? Uri.UnescapeDataString(parts[1].Replace("+", " ")) : "";
        }
        return result;
    }
}
