using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Infrastructure.Stores.Digistore24;

public sealed class Digistore24Connector : IStoreConnector
{
    private readonly IHttpClientFactory _httpClientFactory;

    public Digistore24Connector(IHttpClientFactory httpClientFactory)
    {
        _httpClientFactory = httpClientFactory;
    }

    public string ProviderKey => "digistore24";
    public string DisplayName => "Digistore24";
    public StoreCapabilities Capabilities =>
        StoreCapabilities.CanListProducts | StoreCapabilities.CanBuildAffiliateUrl | StoreCapabilities.CanReceiveWebhooks | StoreCapabilities.CanListOrders;

    public IReadOnlyList<CredentialField> CredentialSchema { get; } =
    [
        new("apiKey", "API key", true, true),
        new("ipnPassphrase", "IPN passphrase", true, false),
        new("affiliateId", "Affiliate id", false, false)
    ];

    public async Task TestConnectionAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var client = CreateClient(credentials);
        using var response = await client.GetAsync("ping", cancellationToken);
        var json = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Digistore24 ping failed: {json}");
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(json) ? "{}" : json);
        if (doc.RootElement.TryGetProperty("result", out var result) &&
            result.GetString()?.Equals("error", StringComparison.OrdinalIgnoreCase) == true)
            throw new InvalidOperationException(doc.RootElement.GetProperty("message").GetString() ?? "Digistore24 error");
    }

    public async Task<IReadOnlyList<CanonicalProduct>> ListProductsAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var client = CreateClient(credentials);
        using var response = await client.GetAsync("listProducts", cancellationToken);
        var json = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Digistore24 listProducts failed: {json}");
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
        if (data.ValueKind == JsonValueKind.Array)
            items.AddRange(data.EnumerateArray());
        else if (data.ValueKind == JsonValueKind.Object)
        {
            if (data.TryGetProperty("products", out var products))
            {
                if (products.ValueKind == JsonValueKind.Array)
                    items.AddRange(products.EnumerateArray());
                else if (products.ValueKind == JsonValueKind.Object)
                    items.AddRange(products.EnumerateObject().Select(p => p.Value));
            }
            else
            {
                foreach (var prop in data.EnumerateObject())
                {
                    if (prop.Value.ValueKind == JsonValueKind.Object)
                        items.Add(prop.Value);
                }
            }
        }

        return items.Select(MapProduct).Where(x => !string.IsNullOrWhiteSpace(x.ExternalProductId)).ToList();
    }

    private static CanonicalProduct MapProduct(JsonElement item)
    {
        var id = ReadString(item, "product_id", "id", "productId") ?? "";
        var name = ReadString(item, "name", "product_name", "title") ?? id;
        var description = ReadString(item, "description", "note");
        decimal? price = ReadDecimal(item, "price", "amount", "sales_price");
        decimal commission = ReadDecimal(item, "affiliate_commission", "commission", "commission_value") ?? 0;
        var currency = ReadString(item, "currency") ?? "EUR";
        var vendor = ReadString(item, "vendor_name", "merchant", "vendor");
        var recurring = string.Equals(ReadString(item, "billing_type", "payment_type"), "subscription", StringComparison.OrdinalIgnoreCase);
        var url = ReadString(item, "order_form_url", "buy_url", "url");

        return new CanonicalProduct(
            id,
            name,
            description,
            ReadString(item, "category"),
            vendor,
            ReadString(item, "vendor_id"),
            currency,
            price,
            price,
            commission,
            ReadDecimal(item, "rebill_commission"),
            recurring ? CommissionType.Recurring : CommissionType.OneTime,
            url,
            ReadString(item, "country"),
            OfferStatus.Active,
            item.GetRawText());
    }

    private HttpClient CreateClient(IReadOnlyDictionary<string, string> credentials)
    {
        var client = _httpClientFactory.CreateClient("digistore24");
        client.DefaultRequestHeaders.Remove("X-DS-API-KEY");
        client.DefaultRequestHeaders.TryAddWithoutValidation("X-DS-API-KEY", credentials["apiKey"]);
        client.DefaultRequestHeaders.Remove("Accept");
        client.DefaultRequestHeaders.TryAddWithoutValidation("Accept", "application/json");
        return client;
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
