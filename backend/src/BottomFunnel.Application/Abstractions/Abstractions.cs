using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Offers.Models;

namespace BottomFunnel.Application.Abstractions;

public interface IAiService
{
    Task<bool> IsConfiguredAsync(CancellationToken cancellationToken = default);
    Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default);
    Task<OfferPolicyAuditResult> AuditOfferSalesPageAsync(
        string productName,
        string salesPageTextOrHtml,
        string? affiliateTermsText,
        string? trafficRestrictionsText = null,
        CancellationToken ct = default);
}

public interface IAiConfigurationService
{
    Task<string?> GetApiKeyAsync(string provider, CancellationToken ct = default);
    Task SaveApiKeyAsync(string provider, string rawApiKey, string defaultModel, CancellationToken ct = default);
    Task<bool> TestConnectionAsync(string provider, string rawApiKey, CancellationToken ct = default);
    Task<IReadOnlyList<AiProviderStatusDto>> ListAsync(CancellationToken ct = default);
}

public interface IObjectStorage
{
    Task<string> SaveAsync(string relativePath, Stream content, string contentType, CancellationToken cancellationToken = default);
    Task<Stream> OpenReadAsync(string relativePath, CancellationToken cancellationToken = default);
}

public interface IJobQueue
{
    Task EnqueueAsync(string jobType, string payloadJson, Guid? organizationId = null, CancellationToken cancellationToken = default);
}

public interface IGoogleAdsClient
{
    Task ImportSpendCsvAsync(Guid organizationId, Stream csv, CancellationToken cancellationToken = default);
}

public interface ICurrencyExchangeService
{
    Task<decimal> GetExchangeRateAsync(string fromCurrency, string toCurrency, CancellationToken ct = default);
}

public sealed record CurrentUser(string UserId, string Email, Guid OrganizationId, string Role);

public interface ICurrentUser
{
    CurrentUser User { get; }
    Guid OrganizationId { get; }
}
