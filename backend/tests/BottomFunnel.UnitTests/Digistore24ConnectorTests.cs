using System.Net;
using BottomFunnel.Infrastructure.Stores.Digistore24;
using FluentAssertions;
using Microsoft.Extensions.Logging.Abstractions;

namespace BottomFunnel.UnitTests;

public class Digistore24ConnectorTests
{
    [Fact]
    public void Ping_and_marketplace_urls_are_absolute_call_endpoints()
    {
        Digistore24Connector.Endpoint("ping").Should().Be("https://www.digistore24.com/api/call/ping");
        Digistore24Connector.Endpoint("/listMarketplaceEntries").Should().Be("https://www.digistore24.com/api/call/listMarketplaceEntries");
        Digistore24Connector.PingUrl.Should().Be(Digistore24Connector.Endpoint("ping"));
        Digistore24Connector.MarketplaceUrl.Should().Be(Digistore24Connector.Endpoint("listMarketplaceEntries"));
    }

    [Fact]
    public void Json_error_result_returns_the_digistore_message()
    {
        Digistore24Connector.TryReadErrorMessage("""{"result":"error","message":"Invalid API key"}""")
            .Should().Be("Invalid API key");
        Digistore24Connector.TryReadErrorMessage("""{"result":"success"}""").Should().BeNull();
    }

    [Fact]
    public async Task Html_403_is_a_firewall_message_and_ping_sends_required_headers()
    {
        string? method = null;
        string? url = null;
        string? apiKey = null;
        string? accept = null;
        string? userAgent = null;
        var factory = new RecordingHttpClientFactory((_, request, _) =>
        {
            method = request.Method.Method;
            url = request.RequestUri?.ToString();
            apiKey = string.Join(",", request.Headers.GetValues("X-DS-API-KEY"));
            accept = request.Headers.TryGetValues("Accept", out var acceptValues)
                ? string.Join(",", acceptValues)
                : "";
            userAgent = request.Headers.TryGetValues("User-Agent", out var agents)
                ? string.Join(",", agents)
                : "";
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.Forbidden)
            {
                Content = new StringContent("<!doctype html><title>403 Forbidden</title>")
            });
        });
        var connector = new Digistore24Connector(factory, NullLogger<Digistore24Connector>.Instance);

        var act = () => connector.TestConnectionAsync(
            new Dictionary<string, string> { ["apiKey"] = "  secret-key  " },
            CancellationToken.None);

        await act.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage(Digistore24Connector.FirewallMessage);
        method.Should().Be("GET");
        url.Should().Be(Digistore24Connector.PingUrl);
        apiKey.Should().Be("secret-key");
        accept.Should().Contain("application/json");
        userAgent.Should().Contain("Chrome/122.0.0.0");
    }

    [Fact]
    public async Task Marketplace_sync_calls_listMarketplaceEntries()
    {
        string? url = null;
        var factory = new RecordingHttpClientFactory((_, request, _) =>
        {
            url = request.RequestUri?.ToString();
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent("""{"result":"success","data":[]}""")
            });
        });
        var connector = new Digistore24Connector(factory, NullLogger<Digistore24Connector>.Instance);

        var products = await connector.ListProductsAsync(
            new Dictionary<string, string> { ["apiKey"] = "key" },
            CancellationToken.None);

        url.Should().Be(Digistore24Connector.MarketplaceUrl);
        products.Should().BeEmpty();
    }

    [Fact]
    public void Marketplace_entries_map_vendor_salespage_and_commission()
    {
        const string json = """
        {
          "result": "success",
          "data": {
            "items": [
              {
                "product_id": "99881",
                "product_name": "Wellness Bundle",
                "description": "Third-party marketplace offer",
                "merchant_name": "acmevendor",
                "salespage_url": "https://example.com/sales",
                "affiliate_commission": 47.5,
                "currency": "EUR",
                "category": "Health"
              }
            ],
            "page_no": 1
          }
        }
        """;

        var products = Digistore24Connector.MapProducts(json);
        products.Should().ContainSingle();
        products[0].ExternalProductId.Should().Be("99881");
        products[0].Name.Should().Be("Wellness Bundle");
        products[0].VendorName.Should().Be("acmevendor");
        products[0].AffiliateUrlTemplate.Should().Be("https://example.com/sales");
        products[0].Commission.Should().Be(47.5m);
    }

    [Fact]
    public void Documented_marketplace_entries_use_id_headline_and_affiliate_profit()
    {
        const string json = """
        {
          "result": "success",
          "data": {
            "entries": [
              {
                "id": 41,
                "headline": "Sleep Reset",
                "description": "Third-party listing",
                "main_product_id": 9001,
                "product_category": "Health",
                "stats_stars": 4.2,
                "stats_affiliate_profit_sale": 18.5,
                "currency": "EUR"
              }
            ],
            "count": 1
          }
        }
        """;

        var products = Digistore24Connector.MapProducts(json);
        products.Should().ContainSingle();
        products[0].ExternalProductId.Should().Be("9001");
        products[0].Name.Should().Be("Sleep Reset");
        products[0].Category.Should().Be("Health");
        products[0].Commission.Should().Be(18.5m);
    }

    private sealed class RecordingHttpClientFactory : IHttpClientFactory
    {
        private readonly Func<HttpClient, HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _handler;

        public RecordingHttpClientFactory(Func<HttpClient, HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler) =>
            _handler = handler;

        public HttpClient CreateClient(string name) => new(new RecordingHandler(_handler), disposeHandler: true);

        private sealed class RecordingHandler : HttpMessageHandler
        {
            private readonly Func<HttpClient, HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _handler;

            public RecordingHandler(Func<HttpClient, HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler) =>
                _handler = handler;

            protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
                _handler(null!, request, cancellationToken);
        }
    }
}
