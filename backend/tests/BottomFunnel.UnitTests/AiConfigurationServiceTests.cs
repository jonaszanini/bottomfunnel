using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Infrastructure.Ai;
using BottomFunnel.Infrastructure.Persistence;
using FluentAssertions;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;

namespace BottomFunnel.UnitTests;

public class AiConfigurationServiceTests
{
    [Fact]
    public async Task GetApiKey_falls_back_to_configuration_when_no_row()
    {
        var (service, _) = Create(new Dictionary<string, string?> { ["Ai:Anthropic:ApiKey"] = "sk-ant-from-config-9999" });

        var key = await service.GetApiKeyAsync("Anthropic");

        key.Should().Be("sk-ant-from-config-9999");
    }

    [Fact]
    public async Task GetApiKey_falls_back_to_legacy_Ai_ApiKey_for_openai()
    {
        var (service, _) = Create(new Dictionary<string, string?> { ["Ai:ApiKey"] = "sk-legacy-openai-1234" });

        var key = await service.GetApiKeyAsync("OpenAI");

        key.Should().Be("sk-legacy-openai-1234");
    }

    [Fact]
    public async Task Save_encrypts_key_and_list_returns_masked_value()
    {
        var (service, db) = Create();

        await service.SaveApiKeyAsync("OpenAI", "sk-live-secret-key-1234", "gpt-4o-mini");

        var row = await db.OrganizationAiSettings.SingleAsync();
        row.EncryptedApiKey.Should().NotBe("sk-live-secret-key-1234");
        row.IsActive.Should().BeTrue();
        (await service.GetApiKeyAsync("OpenAI")).Should().Be("sk-live-secret-key-1234");

        var list = await service.ListAsync();
        var openai = list.Single(x => x.Provider == "OpenAI");
        openai.Configured.Should().BeTrue();
        openai.MaskedApiKey.Should().Be("sk-...1234");
        openai.Source.Should().Be("database");
        openai.DefaultModel.Should().Be("gpt-4o-mini");
    }

    [Fact]
    public async Task Save_with_masked_key_keeps_existing_secret()
    {
        var (service, _) = Create();
        await service.SaveApiKeyAsync("Gemini", "AIzaSySecretKey9999", "gemini-2.0-flash");

        await service.SaveApiKeyAsync("Gemini", "AIz...9999", "gemini-1.5-pro");

        (await service.GetApiKeyAsync("Gemini")).Should().Be("AIzaSySecretKey9999");
        (await service.ListAsync()).Single(x => x.Provider == "Gemini").DefaultModel.Should().Be("gemini-1.5-pro");
    }

    [Fact]
    public void Mask_matches_settings_preview_format()
    {
        AiConfigurationService.Mask("sk-abcdefghij1234").Should().Be("sk-...1234");
        AiConfigurationService.LooksMasked("sk-...1234").Should().BeTrue();
    }

    private static (AiConfigurationService Service, AppDbContext Db) Create(Dictionary<string, string?>? values = null)
    {
        var orgId = Guid.NewGuid();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseInMemoryDatabase(Guid.NewGuid().ToString())
            .Options;
        var db = new AppDbContext(options);
        var config = new ConfigurationBuilder().AddInMemoryCollection(values ?? []).Build();
        var service = new AiConfigurationService(
            db,
            new FakeUser(orgId),
            config,
            new UnusedHttp(),
            new EphemeralDataProtectionProvider());
        return (service, db);
    }
}

file sealed class FakeUser : ICurrentUser
{
    public FakeUser(Guid organizationId) => User = new CurrentUser("u1", "admin@local", organizationId, "Owner");
    public CurrentUser User { get; }
    public Guid OrganizationId => User.OrganizationId;
}

file sealed class UnusedHttp : IHttpClientFactory
{
    public HttpClient CreateClient(string name) => new();
}
