namespace BottomFunnel.Application.Abstractions;

public interface IAiService
{
    bool IsConfigured { get; }
    Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default);
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
