using BottomFunnel.Application.Stores;

namespace BottomFunnel.Infrastructure.Stores;

public sealed class StoreConnectorRegistry : IStoreConnectorRegistry
{
    private readonly Dictionary<string, IStoreConnector> _connectors;

    public StoreConnectorRegistry(IEnumerable<IStoreConnector> connectors)
    {
        _connectors = connectors.ToDictionary(x => x.ProviderKey, StringComparer.OrdinalIgnoreCase);
        All = _connectors.Values.ToList();
    }

    public IReadOnlyList<IStoreConnector> All { get; }

    public IStoreConnector? Find(string providerKey) =>
        _connectors.TryGetValue(providerKey, out var connector) ? connector : null;

    public IStoreConnector GetRequired(string providerKey) =>
        Find(providerKey) ?? throw new KeyNotFoundException($"Unknown store provider '{providerKey}'.");
}
