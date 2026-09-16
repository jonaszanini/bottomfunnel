using BottomFunnel.Application.Abstractions;
using BottomFunnel.Infrastructure.Ai;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Stores;
using BottomFunnel.Infrastructure.Currency;
using BottomFunnel.Infrastructure.Identity;
using BottomFunnel.Infrastructure.Jobs;
using BottomFunnel.Infrastructure.MarketSignals;
using BottomFunnel.Infrastructure.Persistence;
using BottomFunnel.Infrastructure.Security;
using BottomFunnel.Infrastructure.Stores;
using BottomFunnel.Infrastructure.Stores.ClickBank;
using BottomFunnel.Infrastructure.Stores.Digistore24;
using BottomFunnel.Infrastructure.Stores.Manual;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;

namespace BottomFunnel.Infrastructure;

public static class DependencyInjection
{
    public static IServiceCollection AddInfrastructure(this IServiceCollection services, IConfiguration configuration)
    {
        var connectionString = configuration.GetConnectionString("Default")
                               ?? "Host=localhost;Port=5432;Database=bottomfunnel;Username=bottomfunnel;Password=bottomfunnel";

        services.AddDbContext<AppDbContext>(options => options.UseNpgsql(connectionString));
        services.AddScoped<IAppDbContext>(sp => sp.GetRequiredService<AppDbContext>());

        services.AddIdentityCore<ApplicationUser>(options =>
            {
                options.User.RequireUniqueEmail = true;
                options.Password.RequiredLength = 8;
                options.Password.RequireNonAlphanumeric = false;
            })
            .AddEntityFrameworkStores<AppDbContext>()
            .AddDefaultTokenProviders();

        services.AddDataProtection();
        services.AddMemoryCache();
        services.AddHttpClient("bankofcanada", client =>
        {
            client.BaseAddress = new Uri("https://www.bankofcanada.ca/");
            client.Timeout = TimeSpan.FromSeconds(5);
        });
        services.AddSingleton<ICurrencyExchangeService, BankOfCanadaCurrencyService>();
        services.AddHttpClient("clickbank", client =>
        {
            client.BaseAddress = new Uri("https://api.clickbank.com/rest/1.3/");
        });
        services.AddHttpClient("digistore24", client =>
        {
            client.BaseAddress = new Uri("https://www.digistore24.com/api/call/");
        });
        services.AddHttpClient("openai", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(90);
        });
        services.AddHttpClient("anthropic", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(90);
        });
        services.AddHttpClient("gemini", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(90);
        });
        services.AddHttpClient("webpage", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(25);
            client.DefaultRequestHeaders.UserAgent.ParseAdd("BottomFunnelPolicyAudit/1.0");
        });
        services.AddHttpClient("googleads", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(25);
        });
        services.AddHttpClient("dataforseo", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(25);
        });
        services.AddHttpClient("trustpilot", client =>
        {
            client.Timeout = TimeSpan.FromSeconds(25);
            client.DefaultRequestHeaders.UserAgent.ParseAdd("Mozilla/5.0 (compatible; BottomFunnel/1.0; +https://bottomfunnel.local)");
        });

        services.AddSingleton<IStoreConnector, ClickBankConnector>();
        services.AddSingleton<IStoreConnector, Digistore24Connector>();
        services.AddSingleton<IStoreConnector, ManualStoreConnector>();
        services.AddSingleton<IStoreConnectorRegistry, StoreConnectorRegistry>();
        services.AddScoped<ICredentialProtector, CredentialProtector>();
        services.AddScoped<IJobQueue, DbJobQueue>();
        services.AddSingleton<IGoogleAdsClient, StubGoogleAdsClient>();

        var storageRoot = configuration["Storage:Root"] ?? Path.Combine(AppContext.BaseDirectory, "storage");
        services.AddSingleton<IObjectStorage>(_ => new LocalFileStorage(storageRoot));

        services.AddScoped<AiConfigurationService>();
        services.AddScoped<IAiConfigurationService>(sp => sp.GetRequiredService<AiConfigurationService>());
        services.AddScoped<IAiService, OrganizationAiService>();
        services.AddScoped<ISearchVolumeService, SearchVolumeService>();
        services.AddScoped<IReputationAuditService, TrustpilotReputationService>();

        return services;
    }
}
