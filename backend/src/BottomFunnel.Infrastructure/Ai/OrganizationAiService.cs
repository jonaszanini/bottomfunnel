using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Offers;
using BottomFunnel.Application.Offers.Models;

namespace BottomFunnel.Infrastructure.Ai;

public sealed class OrganizationAiService : IAiService
{
    private readonly AiConfigurationService _settings;
    private readonly IHttpClientFactory _http;

    public OrganizationAiService(AiConfigurationService settings, IHttpClientFactory http)
    {
        _settings = settings;
        _http = http;
    }

    public async Task<bool> IsConfiguredAsync(CancellationToken cancellationToken = default) =>
        await _settings.GetActiveAsync(cancellationToken) is not null;

    public async Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default)
    {
        var active = await _settings.GetActiveAsync(cancellationToken)
                     ?? throw new InvalidOperationException("AI is not configured.");
        return await AiProviderClient.CompleteAsync(
            _http,
            active.Provider,
            active.ApiKey,
            active.Model,
            systemPrompt,
            userPrompt,
            cancellationToken);
    }

    public async Task<OfferPolicyAuditResult> AuditOfferSalesPageAsync(
        string productName,
        string salesPageTextOrHtml,
        string? affiliateTermsText,
        string? trafficRestrictionsText = null,
        CancellationToken ct = default)
    {
        var active = await _settings.GetActiveAsync(ct)
                     ?? throw new InvalidOperationException("AI is not configured.");
        var page = HtmlContentSanitizer.ToPlainText(salesPageTextOrHtml);
        var terms = HtmlContentSanitizer.ToPlainText(affiliateTermsText ?? "");
        var rules = trafficRestrictionsText?.Trim();
        var user = OfferPolicyAuditPrompt.BuildUserMessage(productName, page, terms, rules);
        var json = await AiProviderClient.CompleteJsonAsync(
            _http,
            active.Provider,
            active.ApiKey,
            active.Model,
            OfferPolicyAuditPrompt.System,
            user,
            ct);
        return OfferPolicyAuditParser.Parse(json);
    }
}
