using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;
using BottomFunnel.Application.Services;
using BottomFunnel.Application.Stores;
using BottomFunnel.Contracts.Auth;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Api.Auth;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace BottomFunnel.Api.Controllers;

[ApiController]
[Route("auth")]
public class AuthController : ControllerBase
{
    private readonly AuthService _auth;
    private readonly ICurrentUser _current;

    public AuthController(AuthService auth, ICurrentUser current)
    {
        _auth = auth;
        _current = current;
    }

    [HttpPost("login")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> Login(LoginRequest request, CancellationToken ct)
    {
        try
        {
            return await _auth.LoginAsync(request, ct);
        }
        catch (UnauthorizedAccessException)
        {
            return Unauthorized(new { message = "Invalid credentials." });
        }
    }

    [HttpGet("me")]
    [Authorize]
    public async Task<ActionResult<MeResponse>> Me(CancellationToken ct) =>
        await _auth.MeAsync(_current.User, ct);
}

[ApiController]
[Authorize]
[Route("merchants")]
public class MerchantsController : ControllerBase
{
    private readonly CatalogService _catalog;
    public MerchantsController(CatalogService catalog) => _catalog = catalog;

    [HttpGet] public Task<IReadOnlyList<MerchantDto>> List(CancellationToken ct) => _catalog.ListMerchantsAsync(ct);
    [HttpGet("{id:guid}")] public Task<MerchantDto> Get(Guid id, CancellationToken ct) => _catalog.GetMerchantAsync(id, ct);
    [HttpPost] public Task<MerchantDto> Create(UpsertMerchantRequest request, CancellationToken ct) => _catalog.CreateMerchantAsync(request, ct);
    [HttpPut("{id:guid}")] public Task<MerchantDto> Update(Guid id, UpsertMerchantRequest request, CancellationToken ct) => _catalog.UpdateMerchantAsync(id, request, ct);
    [HttpDelete("{id:guid}")] public async Task<IActionResult> Delete(Guid id, CancellationToken ct) { await _catalog.DeleteMerchantAsync(id, ct); return NoContent(); }

    [HttpPost("{id:guid}/restrictions")]
    public Task<ComplianceRestrictionDto> Restriction(Guid id, UpsertRestrictionRequest request, CancellationToken ct, [FromServices] ComplianceService compliance) =>
        compliance.UpsertMerchantRestrictionAsync(id, request, ct);
}

[ApiController]
[Authorize]
[Route("products")]
public class ProductsController : ControllerBase
{
    private readonly CatalogService _catalog;
    private readonly KeywordOpportunityService _keywords;
    public ProductsController(CatalogService catalog, KeywordOpportunityService keywords)
    {
        _catalog = catalog;
        _keywords = keywords;
    }

    [HttpGet] public Task<IReadOnlyList<ProductDto>> List(CancellationToken ct) => _catalog.ListProductsAsync(ct);
    [HttpGet("{id:guid}")] public Task<ProductDto> Get(Guid id, CancellationToken ct) => _catalog.GetProductAsync(id, ct);
    [HttpPost] public Task<ProductDto> Create(UpsertProductRequest request, CancellationToken ct) => _catalog.CreateProductAsync(request, ct);
    [HttpPut("{id:guid}")] public Task<ProductDto> Update(Guid id, UpsertProductRequest request, CancellationToken ct) => _catalog.UpdateProductAsync(id, request, ct);
    [HttpDelete("{id:guid}")] public async Task<IActionResult> Delete(Guid id, CancellationToken ct) { await _catalog.DeleteProductAsync(id, ct); return NoContent(); }
    [HttpPost("{id:guid}/keywords/generate")] public Task<IReadOnlyList<KeywordDto>> Generate(Guid id, CancellationToken ct) => _keywords.GenerateForProductAsync(id, ct);
    [HttpPost("{id:guid}/score")] public Task<IReadOnlyList<OpportunityDto>> Score(Guid id, CancellationToken ct) => _keywords.ScoreProductAsync(id, ct);
}

[ApiController]
[Authorize]
[Route("offers")]
[Route("api/offers")]
public class OffersController : ControllerBase
{
    private readonly CatalogService _catalog;
    public OffersController(CatalogService catalog) => _catalog = catalog;
    [HttpGet] public Task<IReadOnlyList<OfferDto>> List(CancellationToken ct) => _catalog.ListOffersAsync(ct);
    [HttpGet("{id:guid}")] public Task<OfferDto> Get(Guid id, CancellationToken ct) => _catalog.GetOfferAsync(id, ct);
    [HttpPost] public Task<OfferDto> Create(UpsertOfferRequest request, CancellationToken ct) => _catalog.CreateOfferAsync(request, ct);
    [HttpPost("unified")]
    public Task<CreateUnifiedOfferResponse> CreateUnified(CreateUnifiedOfferRequest request, CancellationToken ct, [FromServices] CreateUnifiedOfferCommandHandler handler) =>
        handler.HandleAsync(request, ct);
    [HttpPut("{id:guid}")] public Task<OfferDto> Update(Guid id, UpsertOfferRequest request, CancellationToken ct) => _catalog.UpdateOfferAsync(id, request, ct);
    [HttpDelete("{id:guid}")] public async Task<IActionResult> Delete(Guid id, CancellationToken ct) { await _catalog.DeleteOfferAsync(id, ct); return NoContent(); }

    [HttpPost("{id:guid}/restrictions")]
    public Task<ComplianceRestrictionDto> Restriction(Guid id, UpsertRestrictionRequest request, CancellationToken ct, [FromServices] ComplianceService compliance) =>
        compliance.UpsertOfferRestrictionAsync(id, request, ct);

    [HttpPost("{id:guid}/audit")]
    public Task<OfferPolicyAuditResult> Audit(Guid id, CancellationToken ct, [FromServices] AuditOfferCommandHandler handler) =>
        handler.HandleAsync(new AuditOfferCommand(id), ct);

    [HttpGet("{id:guid}/audit")]
    public async Task<ActionResult<OfferPolicyAuditResult>> LatestAudit(Guid id, CancellationToken ct, [FromServices] AuditOfferCommandHandler handler)
    {
        var result = await handler.GetLatestAsync(id, ct);
        return result is null ? NoContent() : Ok(result);
    }

    [HttpPost("{id:guid}/audit/negatives")]
    public async Task<ActionResult<object>> AddAuditNegatives(
        Guid id,
        AddAuditNegativesRequest request,
        CancellationToken ct,
        [FromServices] AuditOfferCommandHandler handler)
    {
        var added = await handler.AddNegativesAsync(new AddAuditNegativesCommand(id, request.Phrases ?? []), ct);
        return Ok(new { added });
    }

    [HttpPost("{id:guid}/market-signals")]
    public async Task<OfferDto> RefreshMarketSignals(Guid id, CancellationToken ct, [FromServices] OfferMarketSignalsService signals)
    {
        await signals.RefreshAsync(id, ct);
        return await _catalog.GetOfferAsync(id, ct);
    }
}

[ApiController]
[Authorize]
[Route("keywords")]
public class KeywordsController : ControllerBase
{
    private readonly KeywordOpportunityService _keywords;
    public KeywordsController(KeywordOpportunityService keywords) => _keywords = keywords;
    [HttpGet] public Task<IReadOnlyList<KeywordDto>> List([FromQuery] Guid? productId, CancellationToken ct) => _keywords.ListKeywordsAsync(productId, ct);
    [HttpPut("{id:guid}/metrics")] public async Task<IActionResult> Metrics(Guid id, UpsertKeywordMetricsRequest request, CancellationToken ct) { await _keywords.UpdateMetricsAsync(id, request, ct); return NoContent(); }
}

[ApiController]
[Authorize]
[Route("opportunities")]
public class OpportunitiesController : ControllerBase
{
    private readonly KeywordOpportunityService _keywords;
    public OpportunitiesController(KeywordOpportunityService keywords) => _keywords = keywords;
    [HttpGet] public Task<IReadOnlyList<OpportunityDto>> List(CancellationToken ct) => _keywords.ListOpportunitiesAsync(ct);
}

[ApiController]
[Authorize]
[Route("campaigns")]
public class CampaignsController : ControllerBase
{
    private readonly CampaignService _campaigns;
    public CampaignsController(CampaignService campaigns) => _campaigns = campaigns;
    [HttpGet] public Task<IReadOnlyList<CampaignDto>> List(CancellationToken ct) => _campaigns.ListAsync(ct);
    [HttpGet("{id:guid}")] public Task<CampaignDto> Get(Guid id, CancellationToken ct) => _campaigns.GetAsync(id, ct);
    [HttpPost] public Task<CampaignDto> Create(UpsertCampaignRequest request, CancellationToken ct) => _campaigns.CreateAsync(request, ct);
    [HttpPut("{id:guid}")] public Task<CampaignDto> Update(Guid id, UpsertCampaignRequest request, CancellationToken ct) => _campaigns.UpdateAsync(id, request, ct);
    [HttpDelete("{id:guid}")] public async Task<IActionResult> Delete(Guid id, CancellationToken ct) { await _campaigns.DeleteAsync(id, ct); return NoContent(); }
    [HttpPost("{id:guid}/ads")] public Task<AdDto> AddAd(Guid id, UpsertAdRequest request, CancellationToken ct) => _campaigns.AddAdAsync(id, request, ct);
    [HttpDelete("{id:guid}/ads/{adId:guid}")] public async Task<IActionResult> DeleteAd(Guid id, Guid adId, CancellationToken ct) { await _campaigns.DeleteAdAsync(id, adId, ct); return NoContent(); }
}

[ApiController]
[Authorize]
[Route("landing-pages")]
public class LandingPagesController : ControllerBase
{
    private readonly LandingPageService _pages;
    public LandingPagesController(LandingPageService pages) => _pages = pages;
    [HttpGet] public Task<IReadOnlyList<LandingPageDto>> List(CancellationToken ct) => _pages.ListAsync(ct);
    [HttpGet("{id:guid}")] public Task<LandingPageDto> Get(Guid id, CancellationToken ct) => _pages.GetAsync(id, ct);
    [HttpPost] public Task<LandingPageDto> Create(UpsertLandingPageRequest request, CancellationToken ct) => _pages.CreateAsync(request, ct);
    [HttpPut("{id:guid}")] public Task<LandingPageDto> Update(Guid id, UpsertLandingPageRequest request, CancellationToken ct) => _pages.UpdateAsync(id, request, ct);
    [HttpDelete("{id:guid}")] public async Task<IActionResult> Delete(Guid id, CancellationToken ct) { await _pages.DeleteAsync(id, ct); return NoContent(); }
}

[ApiController]
[Route("public/landing-pages")]
public class PublicLandingPagesController : ControllerBase
{
    private readonly LandingPageService _pages;
    public PublicLandingPagesController(LandingPageService pages) => _pages = pages;

    [HttpGet("{slug}")]
    [AllowAnonymous]
    public async Task<ActionResult<LandingPageDto>> Get(string slug, CancellationToken ct)
    {
        var page = await _pages.GetBySlugAsync(slug, ct);
        return page is null ? NotFound() : page;
    }

    [HttpPost("visits")]
    [AllowAnonymous]
    public Task<CaptureVisitResponse> Visit(CaptureVisitRequest request, CancellationToken ct, [FromServices] TrackingAnalyticsService tracking) =>
        tracking.CaptureVisitAsync(request, Request.Headers.UserAgent.ToString(), Request.Headers.Referer.ToString(), ct);
}

[ApiController]
[Authorize]
[Route("restrictions")]
public class RestrictionsController : ControllerBase
{
    private readonly ComplianceService _compliance;
    public RestrictionsController(ComplianceService compliance) => _compliance = compliance;

    [HttpDelete("{id:guid}")]
    public async Task<IActionResult> Delete(Guid id, CancellationToken ct)
    {
        await _compliance.DeleteRestrictionAsync(id, ct);
        return NoContent();
    }
}

[ApiController]
[Authorize]
[Route("store-connections")]
public class StoreConnectionsController : ControllerBase
{
    private readonly StoreConnectionService _stores;
    public StoreConnectionsController(StoreConnectionService stores) => _stores = stores;
    [HttpGet("providers")] public IReadOnlyList<StoreProviderDto> Providers() => _stores.ListProviders();
    [HttpGet] public Task<IReadOnlyList<StoreConnectionDto>> List(CancellationToken ct) => _stores.ListAsync(ct);
    [HttpPost] public Task<StoreConnectionDto> Save(SaveStoreConnectionRequest request, CancellationToken ct) => _stores.SaveAsync(request, ct);
    [HttpPost("{id:guid}/test")] public async Task<IActionResult> Test(Guid id, CancellationToken ct) { await _stores.TestAsync(id, ct); return NoContent(); }
    [HttpPost("{id:guid}/sync")] public async Task<IActionResult> Sync(Guid id, CancellationToken ct) { await _stores.SyncAsync(id, ct); return NoContent(); }
}

[ApiController]
[Authorize]
[Route("tracking")]
public class TrackingAdminController : ControllerBase
{
    private readonly TrackingAnalyticsService _tracking;
    private readonly ICurrentUser _current;
    public TrackingAdminController(TrackingAnalyticsService tracking, ICurrentUser current)
    {
        _tracking = tracking;
        _current = current;
    }

    [HttpGet("clicks")] public Task<IReadOnlyList<ClickDto>> Clicks(CancellationToken ct) => _tracking.ListClicksAsync(_current.OrganizationId, ct);
    [HttpGet("conversions")] public Task<IReadOnlyList<ConversionDto>> Conversions(CancellationToken ct) => _tracking.ListConversionsAsync(_current.OrganizationId, ct);
    [HttpPost("conversions")] public async Task<IActionResult> AddConversion(CreateConversionRequest request, CancellationToken ct) { await _tracking.AddManualConversionAsync(_current.OrganizationId, request, ct); return NoContent(); }
    [HttpPost("costs")] public async Task<IActionResult> AddCost(CreateCostRequest request, CancellationToken ct) { await _tracking.AddCostAsync(_current.OrganizationId, request, ct); return NoContent(); }
}

[ApiController]
[Authorize]
[Route("analytics")]
public class AnalyticsController : ControllerBase
{
    private readonly TrackingAnalyticsService _tracking;
    private readonly ICurrentUser _current;
    public AnalyticsController(TrackingAnalyticsService tracking, ICurrentUser current)
    {
        _tracking = tracking;
        _current = current;
    }

    [HttpGet("dashboard")]
    public Task<DashboardDto> Dashboard(CancellationToken ct) => _tracking.GetDashboardAsync(_current.OrganizationId, ct);
}

[ApiController]
[Authorize]
[Route("ai")]
public class AiController : ControllerBase
{
    private readonly AiOrchestrator _ai;
    public AiController(AiOrchestrator ai) => _ai = ai;
    [HttpGet("status")] public async Task<object> Status(CancellationToken ct) => new { configured = await _ai.IsConfiguredAsync(ct) };
    [HttpPost("analyze")] public Task<AiAnalyzeResponse> Analyze(AiAnalyzeRequest request, CancellationToken ct) => _ai.AnalyzeAsync(request, ct);
}

[ApiController]
[Route("t")]
public class PublicTrackingController : ControllerBase
{
    private readonly TrackingAnalyticsService _tracking;
    public PublicTrackingController(TrackingAnalyticsService tracking) => _tracking = tracking;

    [HttpGet("{code}")]
    [AllowAnonymous]
    public async Task<IActionResult> Go(
        string code,
        [FromQuery] Guid? keywordId,
        [FromQuery] Guid? adId,
        [FromQuery] Guid? sessionId,
        [FromQuery] Guid? visitId,
        [FromQuery] string? gclid,
        CancellationToken ct)
    {
        var destination = await _tracking.TrackAndRedirectAsync(
            code,
            Request.Headers.UserAgent.ToString(),
            HttpContext.Connection.RemoteIpAddress?.ToString(),
            Request.Headers.Referer.ToString(),
            keywordId,
            adId,
            sessionId,
            visitId,
            gclid,
            ct);
        return Redirect(destination);
    }
}

[ApiController]
[Route("postback")]
public class PostbackController : ControllerBase
{
    private readonly TrackingAnalyticsService _tracking;
    public PostbackController(TrackingAnalyticsService tracking) => _tracking = tracking;

    [HttpGet]
    [HttpPost]
    [AllowAnonymous]
    public async Task<IActionResult> Receive([FromQuery] Guid? clickId, [FromQuery] string? tid, [FromQuery] decimal commission = 0, [FromQuery] decimal? amount = null, [FromQuery] string currency = "USD", CancellationToken ct = default)
    {
        await _tracking.RecordPostbackAsync(clickId, tid, commission, amount, currency, "generic", ct);
        return Ok(new { ok = true });
    }
}

[ApiController]
[Route("webhooks")]
public class WebhooksController : ControllerBase
{
    private readonly StoreConnectionService _stores;
    private readonly TrackingAnalyticsService _tracking;
    private readonly IStoreConnectorRegistry _registry;

    public WebhooksController(StoreConnectionService stores, TrackingAnalyticsService tracking, IStoreConnectorRegistry registry)
    {
        _stores = stores;
        _tracking = tracking;
        _registry = registry;
    }

    [HttpPost("{providerKey}")]
    [AllowAnonymous]
    public async Task<IActionResult> Receive(string providerKey, CancellationToken ct)
    {
        var connector = _registry.Find(providerKey);
        if (connector is null)
            return NotFound();

        var form = Request.HasFormContentType
            ? Request.Form.ToDictionary(x => x.Key, x => x.Value.ToString())
            : new Dictionary<string, string>();
        Request.EnableBuffering();
        using var reader = new StreamReader(Request.Body, leaveOpen: true);
        var body = await reader.ReadToEndAsync(ct);
        Request.Body.Position = 0;

        var webhook = new IncomingWebhook(
            Request.Method,
            Request.Headers.ToDictionary(x => x.Key, x => x.Value.ToString()),
            form,
            Request.Query.ToDictionary(x => x.Key, x => x.Value.ToString()),
            body);

        var loaded = await _stores.LoadForWebhookAsync(providerKey, ct);
        var creds = loaded?.Credentials ?? [];
        if (!connector.TryParseWebhook(webhook, creds, out var conversion) || conversion is null)
            return BadRequest();

        var orgId = loaded?.Connection.OrganizationId;
        if (orgId is null)
            return Ok(new { ignored = true });

        await _tracking.ApplyCanonicalConversionAsync(conversion, orgId.Value, ct);
        return Ok(new { ok = true });
    }
}

[ApiController]
[Authorize]
[Route("api/currency")]
public class CurrencyController : ControllerBase
{
    private readonly ICurrencyExchangeService _fx;
    public CurrencyController(ICurrencyExchangeService fx) => _fx = fx;

    [HttpGet("rate")]
    public async Task<ActionResult<CurrencyRateDto>> Rate([FromQuery] string from = "USD", [FromQuery] string to = "CAD", CancellationToken ct = default)
    {
        var rate = await _fx.GetExchangeRateAsync(from, to, ct);
        return new CurrencyRateDto(from.ToUpperInvariant(), to.ToUpperInvariant(), rate);
    }
}

[ApiController]
[Authorize]
[Route("api/settings/ai")]
public class AiSettingsController : ControllerBase
{
    private readonly IAiConfigurationService _ai;
    public AiSettingsController(IAiConfigurationService ai) => _ai = ai;

    [HttpGet]
    public Task<IReadOnlyList<AiProviderStatusDto>> List(CancellationToken ct) => _ai.ListAsync(ct);

    [HttpPost]
    public async Task<IReadOnlyList<AiProviderStatusDto>> Save(SaveAiProviderRequest request, CancellationToken ct)
    {
        await _ai.SaveApiKeyAsync(request.Provider, request.ApiKey, request.DefaultModel, ct);
        return await _ai.ListAsync(ct);
    }

    [HttpPost("test")]
    public async Task<AiConnectionTestDto> Test(TestAiProviderRequest request, CancellationToken ct)
    {
        await _ai.TestConnectionAsync(request.Provider, request.ApiKey, ct);
        return new AiConnectionTestDto(true, "Connection succeeded.");
    }
}

[ApiController]
[Authorize]
[Route("candidates")]
[Route("api/candidates")]
public class CandidatesController : ControllerBase
{
    private readonly ProductCandidateService _candidates;
    private readonly IClickBankFeedIngestionService _feed;

    public CandidatesController(ProductCandidateService candidates, IClickBankFeedIngestionService feed)
    {
        _candidates = candidates;
        _feed = feed;
    }

    [HttpGet]
    public Task<PagedProductCandidatesDto> List(
        [FromQuery] CandidateStatus? status,
        [FromQuery] string? search,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken ct = default) =>
        _candidates.ListAsync(new GetProductCandidatesQuery(status, search, page, pageSize), ct);

    [HttpPost("{id:guid}/audit")]
    public Task<AuditCandidateResponse> Audit(Guid id, CancellationToken ct) =>
        _candidates.AuditAsync(id, ct);

    [HttpPost("{id:guid}/promote")]
    public Task<CreateUnifiedOfferResponse> Promote(Guid id, PromoteCandidateToOfferRequest request, CancellationToken ct) =>
        _candidates.PromoteAsync(new PromoteCandidateToOfferCommand(id, request.PayoutAmount, request.Currency), request, ct);

    [HttpPost("sync")]
    public Task<ClickBankFeedSyncResult> Sync(CancellationToken ct) =>
        _feed.SyncAsync(ct);
}
