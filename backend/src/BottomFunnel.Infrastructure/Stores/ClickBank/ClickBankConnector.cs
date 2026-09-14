using System.Text.Json;
using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Infrastructure.Stores.ClickBank;

public sealed class ClickBankConnector : IStoreConnector
{
    private readonly IHttpClientFactory _httpClientFactory;

    public ClickBankConnector(IHttpClientFactory httpClientFactory)
    {
        _httpClientFactory = httpClientFactory;
    }

    public string ProviderKey => "clickbank";
    public string DisplayName => "ClickBank";
    public StoreCapabilities Capabilities =>
        StoreCapabilities.CanListProducts | StoreCapabilities.CanBuildAffiliateUrl | StoreCapabilities.CanReceiveWebhooks | StoreCapabilities.CanListOrders;

    public IReadOnlyList<CredentialField> CredentialSchema { get; } =
    [
        new("apiKey", "API key", true, true, "API-..."),
        new("siteNickname", "Site nickname", false, true, "YOURSITE"),
        new("affiliateNickname", "Affiliate nickname", false, true, "youraff"),
        new("insSecret", "INS secret", true, false)
    ];

    public async Task TestConnectionAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var products = await ListProductsAsync(credentials, cancellationToken);
        _ = products;
    }

    public async Task<IReadOnlyList<CanonicalProduct>> ListProductsAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken)
    {
        var site = credentials["siteNickname"];
        var client = CreateClient(credentials);
        using var response = await client.GetAsync($"products/list?site={Uri.EscapeDataString(site)}", cancellationToken);
        var json = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"ClickBank list failed ({(int)response.StatusCode}): {json}");

        return MapProducts(json);
    }

    public string BuildTrackedAffiliateUrl(CanonicalOfferContext offer, string clickId, IReadOnlyDictionary<string, string> credentials)
    {
        if (!string.IsNullOrWhiteSpace(offer.AffiliateUrl))
            return AppendTid(offer.AffiliateUrl, clickId);

        credentials.TryGetValue("affiliateNickname", out var affiliate);
        var vendor = offer.ExternalVendorId ?? credentials.GetValueOrDefault("siteNickname") ?? "vendor";
        affiliate ??= "affiliate";
        var url = $"https://{vendor}.{affiliate}.hop.clickbank.net/";
        if (!string.IsNullOrWhiteSpace(offer.ExternalProductId))
            url += $"?item={Uri.EscapeDataString(offer.ExternalProductId)}&tid={Uri.EscapeDataString(clickId)}";
        else
            url += $"?tid={Uri.EscapeDataString(clickId)}";
        return url;
    }

    public bool TryParseWebhook(IncomingWebhook request, IReadOnlyDictionary<string, string> credentials, out CanonicalConversion? conversion)
    {
        conversion = null;
        var form = request.Form;
        if (form.Count == 0 && !string.IsNullOrWhiteSpace(request.Body))
            form = ParseForm(request.Body);

        if (credentials.TryGetValue("insSecret", out var secret) && !string.IsNullOrWhiteSpace(secret))
        {
            form.TryGetValue("secretkey", out var provided);
            form.TryGetValue("secretKey", out var provided2);
            if (!string.Equals(provided ?? provided2, secret, StringComparison.Ordinal))
                return false;
        }

        form.TryGetValue("ctransaction", out var type);
        form.TryGetValue("ccustcc", out var currency);
        form.TryGetValue("corderamount", out var amountRaw);
        form.TryGetValue("caccountamount", out var commissionRaw);
        form.TryGetValue("ctid", out var tid);
        form.TryGetValue("caffitid", out var affTid);
        form.TryGetValue("cbreceipt", out var receipt);

        var eventType = (type ?? "").ToUpperInvariant() switch
        {
            "RFND" or "TEST_RFND" => ConversionEventType.Refund,
            "CGBK" or "TEST_CGBK" => ConversionEventType.Chargeback,
            "BILL" or "TEST_BILL" => ConversionEventType.Rebill,
            _ => ConversionEventType.Sale
        };

        decimal.TryParse(amountRaw, System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture, out var amount);
        decimal.TryParse(commissionRaw, System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture, out var commission);

        conversion = new CanonicalConversion(
            tid ?? affTid,
            receipt,
            eventType,
            amount,
            commission == 0 ? amount : commission,
            string.IsNullOrWhiteSpace(currency) ? "USD" : currency,
            DateTimeOffset.UtcNow,
            request.Body);
        return true;
    }

    internal static IReadOnlyList<CanonicalProduct> MapProducts(string json)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(json) ? "{}" : json);
        var root = doc.RootElement;
        var list = new List<JsonElement>();
        if (root.ValueKind == JsonValueKind.Array)
            list.AddRange(root.EnumerateArray());
        else if (root.TryGetProperty("products", out var products))
        {
            if (products.ValueKind == JsonValueKind.Array)
                list.AddRange(products.EnumerateArray());
            else if (products.TryGetProperty("product", out var productNode))
            {
                if (productNode.ValueKind == JsonValueKind.Array)
                    list.AddRange(productNode.EnumerateArray());
                else
                    list.Add(productNode);
            }
        }
        else if (root.TryGetProperty("product", out var single))
        {
            if (single.ValueKind == JsonValueKind.Array)
                list.AddRange(single.EnumerateArray());
            else
                list.Add(single);
        }

        return list.Select(MapProduct).ToList();
    }

    private static CanonicalProduct MapProduct(JsonElement item)
    {
        var sku = item.TryGetProperty("sku", out var skuEl) ? skuEl.GetString() : item.TryGetProperty("@sku", out var skuAttr) ? skuAttr.GetString() : null;
        sku ??= item.TryGetProperty("id", out var idEl) ? idEl.ToString() : Guid.NewGuid().ToString("N");
        var title = item.TryGetProperty("title", out var titleEl) ? titleEl.GetString() ?? sku : sku;
        var description = item.TryGetProperty("description", out var descEl) ? descEl.GetString() : null;
        var site = item.TryGetProperty("site", out var siteEl) ? siteEl.GetString() : null;
        var statusText = item.TryGetProperty("status", out var statusEl) ? statusEl.GetString() : "ACTIVE";
        var status = string.Equals(statusText, "INACTIVE", StringComparison.OrdinalIgnoreCase) ? OfferStatus.Paused : OfferStatus.Active;

        decimal purchaseCommission = 0;
        decimal? rebillCommission = null;
        if (item.TryGetProperty("commission", out var commission))
        {
            if (commission.TryGetProperty("purchase", out var purchase) && purchase.TryGetDecimal(out var p))
                purchaseCommission = p;
            if (commission.TryGetProperty("rebill", out var rebill) && rebill.TryGetDecimal(out var r))
                rebillCommission = r;
        }

        decimal? price = null;
        var currency = "USD";
        var recurring = item.TryGetProperty("digitalRecurring", out var dr) && dr.ValueKind is JsonValueKind.True;
        if (item.TryGetProperty("pricings", out var pricings))
        {
            JsonElement pricing = default;
            if (pricings.TryGetProperty("pricing", out var pricingNode))
                pricing = pricingNode.ValueKind == JsonValueKind.Array ? pricingNode.EnumerateArray().FirstOrDefault() : pricingNode;
            if (pricing.ValueKind is JsonValueKind.Object)
            {
                if (pricing.TryGetProperty("@currency", out var cur) || pricing.TryGetProperty("currency", out cur))
                    currency = cur.GetString() ?? currency;
                if (pricing.TryGetProperty("standard", out var standard) && standard.TryGetProperty("price", out var priceNode))
                    price = ReadPrice(priceNode);
                if (pricing.TryGetProperty("recurring", out var rec) && rec.TryGetProperty("price", out var recPrice))
                {
                    recurring = true;
                    price ??= ReadPrice(recPrice);
                }
            }
        }

        var category = item.TryGetProperty("categories", out var cats) && cats.TryGetProperty("category", out var cat)
            ? cat.ValueKind == JsonValueKind.Array ? cat[0].GetString() : cat.GetString()
            : null;

        return new CanonicalProduct(
            sku!,
            title,
            description,
            category,
            site,
            site,
            currency,
            price,
            price,
            purchaseCommission,
            rebillCommission,
            recurring || rebillCommission.HasValue ? CommissionType.Recurring : CommissionType.OneTime,
            null,
            null,
            status,
            item.GetRawText());
    }

    private static decimal? ReadPrice(JsonElement priceNode)
    {
        if (priceNode.TryGetProperty("usd", out var usd) && usd.TryGetDecimal(out var usdVal))
            return usdVal;
        if (priceNode.TryGetProperty("native_price", out var native) && native.TryGetDecimal(out var nativeVal))
            return nativeVal;
        return null;
    }

    private HttpClient CreateClient(IReadOnlyDictionary<string, string> credentials)
    {
        var client = _httpClientFactory.CreateClient("clickbank");
        var key = credentials["apiKey"].StartsWith("API-", StringComparison.OrdinalIgnoreCase)
            ? credentials["apiKey"]
            : $"API-{credentials["apiKey"]}";
        client.DefaultRequestHeaders.Remove("Authorization");
        client.DefaultRequestHeaders.TryAddWithoutValidation("Authorization", key);
        client.DefaultRequestHeaders.Remove("Accept");
        client.DefaultRequestHeaders.TryAddWithoutValidation("Accept", "application/json");
        return client;
    }

    private static string AppendTid(string url, string clickId) =>
        url.Contains('?') ? $"{url}&tid={Uri.EscapeDataString(clickId)}" : $"{url}?tid={Uri.EscapeDataString(clickId)}";

    private static Dictionary<string, string> ParseForm(string body)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var pair in body.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            var key = Uri.UnescapeDataString(parts[0].Replace("+", " "));
            var value = parts.Length > 1 ? Uri.UnescapeDataString(parts[1].Replace("+", " ")) : "";
            result[key] = value;
        }
        return result;
    }
}
