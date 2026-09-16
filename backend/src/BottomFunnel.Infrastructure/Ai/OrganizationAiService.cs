using BottomFunnel.Application.Abstractions;

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
}
