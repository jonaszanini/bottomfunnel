using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Application.Stores;

public sealed record CredentialField(
    string Key,
    string Label,
    bool Secret,
    bool Required,
    string? Placeholder = null);

public sealed record CanonicalProduct(
    string ExternalProductId,
    string Name,
    string? Description,
    string? Category,
    string? VendorName,
    string? VendorId,
    string Currency,
    decimal? Price,
    decimal? AverageOrderValue,
    decimal Commission,
    decimal? RecurringCommission,
    CommissionType CommissionType,
    string? AffiliateUrlTemplate,
    string? Country,
    OfferStatus Status,
    string RawPayloadJson);

public sealed record CanonicalOfferContext(
    string ProviderKey,
    string? ExternalProductId,
    string? ExternalVendorId,
    string? AffiliateUrl,
    string? AffiliateUrlTemplate);

public sealed record CanonicalConversion(
    string? ClickId,
    string? ExternalOrderId,
    ConversionEventType EventType,
    decimal Amount,
    decimal Commission,
    string Currency,
    DateTimeOffset OccurredAt,
    string RawPayloadJson);

public sealed record IncomingWebhook(
    string Method,
    IReadOnlyDictionary<string, string> Headers,
    IReadOnlyDictionary<string, string> Form,
    IReadOnlyDictionary<string, string> Query,
    string Body);

public interface IStoreConnector
{
    string ProviderKey { get; }
    string DisplayName { get; }
    IReadOnlyList<CredentialField> CredentialSchema { get; }
    StoreCapabilities Capabilities { get; }

    Task TestConnectionAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken);
    Task<IReadOnlyList<CanonicalProduct>> ListProductsAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken);
    string BuildTrackedAffiliateUrl(CanonicalOfferContext offer, string clickId, IReadOnlyDictionary<string, string> credentials);
    bool TryParseWebhook(IncomingWebhook request, IReadOnlyDictionary<string, string> credentials, out CanonicalConversion? conversion);
}

public interface IStoreConnectorRegistry
{
    IReadOnlyList<IStoreConnector> All { get; }
    IStoreConnector GetRequired(string providerKey);
    IStoreConnector? Find(string providerKey);
}

public interface ICredentialProtector
{
    string Protect(IReadOnlyDictionary<string, string> credentials);
    Dictionary<string, string> Unprotect(string protectedPayload);
}
