using BottomFunnel.Application.Stores;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Infrastructure.Stores.Manual;

public sealed class ManualStoreConnector : IStoreConnector
{
    public string ProviderKey => "manual";
    public string DisplayName => "Manual / pasted URL";
    public StoreCapabilities Capabilities => StoreCapabilities.CanBuildAffiliateUrl;

    public IReadOnlyList<CredentialField> CredentialSchema { get; } = [];

    public Task TestConnectionAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken) =>
        Task.CompletedTask;

    public Task<IReadOnlyList<CanonicalProduct>> ListProductsAsync(IReadOnlyDictionary<string, string> credentials, CancellationToken cancellationToken) =>
        Task.FromResult<IReadOnlyList<CanonicalProduct>>([]);

    public string BuildTrackedAffiliateUrl(CanonicalOfferContext offer, string clickId, IReadOnlyDictionary<string, string> credentials)
    {
        var url = offer.AffiliateUrl ?? offer.AffiliateUrlTemplate ?? throw new InvalidOperationException("No affiliate URL.");
        url = url.Replace("{clickId}", clickId, StringComparison.OrdinalIgnoreCase);
        if (url.Contains(clickId, StringComparison.Ordinal))
            return url;
        return url.Contains('?') ? $"{url}&tid={Uri.EscapeDataString(clickId)}" : $"{url}?tid={Uri.EscapeDataString(clickId)}";
    }

    public bool TryParseWebhook(IncomingWebhook request, IReadOnlyDictionary<string, string> credentials, out CanonicalConversion? conversion)
    {
        conversion = null;
        return false;
    }
}
