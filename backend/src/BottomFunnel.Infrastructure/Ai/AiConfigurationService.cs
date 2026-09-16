using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;

namespace BottomFunnel.Infrastructure.Ai;

public sealed class AiConfigurationService : IAiConfigurationService
{
    internal static readonly string[] Providers = ["OpenAI", "Anthropic", "Gemini"];

    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;
    private readonly IConfiguration _configuration;
    private readonly IHttpClientFactory _http;
    private readonly IDataProtector _protector;

    public AiConfigurationService(
        IAppDbContext db,
        ICurrentUser current,
        IConfiguration configuration,
        IHttpClientFactory http,
        IDataProtectionProvider dataProtection)
    {
        _db = db;
        _current = current;
        _configuration = configuration;
        _http = http;
        _protector = dataProtection.CreateProtector("BottomFunnel.AiSettings.v1");
    }

    public async Task<string?> GetApiKeyAsync(string provider, CancellationToken ct = default)
    {
        var canonical = NormalizeProvider(provider);
        var row = await FindAsync(canonical, ct);
        if (row is not null && !string.IsNullOrWhiteSpace(row.EncryptedApiKey))
            return Unprotect(row.EncryptedApiKey);
        return ConfigApiKey(canonical);
    }

    public async Task SaveApiKeyAsync(string provider, string rawApiKey, string defaultModel, CancellationToken ct = default)
    {
        var canonical = NormalizeProvider(provider);
        var model = string.IsNullOrWhiteSpace(defaultModel) ? AiProviderClient.DefaultModel(canonical) : defaultModel.Trim();
        var row = await FindAsync(canonical, ct);
        var keepExisting = LooksMasked(rawApiKey) || string.IsNullOrWhiteSpace(rawApiKey);

        if (keepExisting)
        {
            if (row is null)
                throw new InvalidOperationException("Enter an API key before saving.");
            row.DefaultModel = model;
            row.IsActive = true;
            row.UpdatedAt = DateTimeOffset.UtcNow;
        }
        else
        {
            if (row is null)
            {
                row = new OrganizationAiSetting
                {
                    OrganizationId = _current.OrganizationId,
                    Provider = canonical,
                    EncryptedApiKey = _protector.Protect(rawApiKey.Trim()),
                    DefaultModel = model,
                    IsActive = true
                };
                _db.OrganizationAiSettings.Add(row);
            }
            else
            {
                row.EncryptedApiKey = _protector.Protect(rawApiKey.Trim());
                row.DefaultModel = model;
                row.IsActive = true;
                row.UpdatedAt = DateTimeOffset.UtcNow;
            }
        }

        var others = await _db.OrganizationAiSettings
            .Where(x => x.OrganizationId == _current.OrganizationId && x.Provider != canonical && x.IsActive)
            .ToListAsync(ct);
        foreach (var other in others)
        {
            other.IsActive = false;
            other.UpdatedAt = DateTimeOffset.UtcNow;
        }

        await _db.SaveChangesAsync(ct);
    }

    public async Task<bool> TestConnectionAsync(string provider, string rawApiKey, CancellationToken ct = default)
    {
        var canonical = NormalizeProvider(provider);
        var key = LooksMasked(rawApiKey) || string.IsNullOrWhiteSpace(rawApiKey)
            ? await GetApiKeyAsync(canonical, ct)
            : rawApiKey.Trim();
        if (string.IsNullOrWhiteSpace(key))
            throw new InvalidOperationException("Enter an API key to test.");

        var model = (await FindAsync(canonical, ct))?.DefaultModel ?? ConfigModel(canonical) ?? AiProviderClient.DefaultModel(canonical);
        await AiProviderClient.PingAsync(_http, canonical, key, model, ct);
        return true;
    }

    public async Task<IReadOnlyList<AiProviderStatusDto>> ListAsync(CancellationToken ct = default)
    {
        var rows = await _db.OrganizationAiSettings
            .Where(x => x.OrganizationId == _current.OrganizationId)
            .ToListAsync(ct);
        var byProvider = rows.ToDictionary(x => x.Provider, StringComparer.OrdinalIgnoreCase);

        return Providers.Select(provider =>
        {
            byProvider.TryGetValue(provider, out var row);
            string? key = null;
            string? source = null;
            if (row is not null && !string.IsNullOrWhiteSpace(row.EncryptedApiKey))
            {
                key = Unprotect(row.EncryptedApiKey);
                source = "database";
            }
            else
            {
                key = ConfigApiKey(provider);
                if (key is not null)
                    source = "config";
            }

            var model = row?.DefaultModel
                        ?? ConfigModel(provider)
                        ?? AiProviderClient.DefaultModel(provider);
            return new AiProviderStatusDto(
                provider,
                Mask(key),
                model,
                row?.IsActive == true,
                key is not null,
                source);
        }).ToList();
    }

    internal async Task<(string Provider, string ApiKey, string Model)?> GetActiveAsync(CancellationToken ct)
    {
        var rows = await _db.OrganizationAiSettings
            .Where(x => x.OrganizationId == _current.OrganizationId)
            .OrderByDescending(x => x.IsActive)
            .ThenByDescending(x => x.UpdatedAt)
            .ToListAsync(ct);
        foreach (var row in rows)
        {
            if (string.IsNullOrWhiteSpace(row.EncryptedApiKey))
                continue;
            return (row.Provider, Unprotect(row.EncryptedApiKey), row.DefaultModel);
        }

        foreach (var provider in Providers)
        {
            var key = ConfigApiKey(provider);
            if (key is not null)
                return (provider, key, ConfigModel(provider) ?? AiProviderClient.DefaultModel(provider));
        }

        return null;
    }

    internal static string NormalizeProvider(string provider)
    {
        var value = (provider ?? "").Trim();
        if (value.Equals("openai", StringComparison.OrdinalIgnoreCase))
            return "OpenAI";
        if (value.Equals("anthropic", StringComparison.OrdinalIgnoreCase) || value.Equals("claude", StringComparison.OrdinalIgnoreCase))
            return "Anthropic";
        if (value.Equals("gemini", StringComparison.OrdinalIgnoreCase) ||
            value.Equals("google", StringComparison.OrdinalIgnoreCase) ||
            value.Equals("google gemini", StringComparison.OrdinalIgnoreCase))
            return "Gemini";
        throw new InvalidOperationException("Provider must be OpenAI, Anthropic, or Gemini.");
    }

    internal static bool LooksMasked(string? value) =>
        !string.IsNullOrWhiteSpace(value) && value.Contains("...", StringComparison.Ordinal);

    internal static string? Mask(string? key)
    {
        if (string.IsNullOrWhiteSpace(key))
            return null;
        if (key.Length <= 8)
            return $"{key[0]}…{key[^1]}";
        return $"{key[..3]}...{key[^4..]}";
    }

    private Task<OrganizationAiSetting?> FindAsync(string provider, CancellationToken ct) =>
        _db.OrganizationAiSettings.FirstOrDefaultAsync(
            x => x.OrganizationId == _current.OrganizationId && x.Provider == provider, ct);

    private string? ConfigApiKey(string provider)
    {
        var nested = NullIfEmpty(_configuration[$"Ai:{provider}:ApiKey"]);
        if (nested is not null)
            return nested;
        if (provider == "OpenAI")
            return NullIfEmpty(_configuration["Ai:ApiKey"]);
        return null;
    }

    private string? ConfigModel(string provider) =>
        NullIfEmpty(_configuration[$"Ai:{provider}:Model"])
        ?? (provider == "OpenAI" ? NullIfEmpty(_configuration["Ai:Model"]) : null);

    private string Unprotect(string payload)
    {
        try
        {
            return _protector.Unprotect(payload);
        }
        catch (Exception)
        {
            throw new InvalidOperationException("Could not decrypt the stored API key. Save it again.");
        }
    }

    private static string? NullIfEmpty(string? value) =>
        string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}
