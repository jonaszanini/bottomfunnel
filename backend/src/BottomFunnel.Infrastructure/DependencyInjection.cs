using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Stores;
using BottomFunnel.Infrastructure.Identity;
using BottomFunnel.Infrastructure.Jobs;
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
        services.AddHttpClient("clickbank", client =>
        {
            client.BaseAddress = new Uri("https://api.clickbank.com/rest/1.3/");
        });
        services.AddHttpClient("digistore24", client =>
        {
            client.BaseAddress = new Uri("https://www.digistore24.com/api/call/");
        });
        services.AddHttpClient("openai");

        services.AddSingleton<IStoreConnector, ClickBankConnector>();
        services.AddSingleton<IStoreConnector, Digistore24Connector>();
        services.AddSingleton<IStoreConnector, ManualStoreConnector>();
        services.AddSingleton<IStoreConnectorRegistry, StoreConnectorRegistry>();
        services.AddScoped<ICredentialProtector, CredentialProtector>();
        services.AddScoped<IJobQueue, DbJobQueue>();
        services.AddSingleton<IGoogleAdsClient, StubGoogleAdsClient>();

        var storageRoot = configuration["Storage:Root"] ?? Path.Combine(AppContext.BaseDirectory, "storage");
        services.AddSingleton<IObjectStorage>(_ => new LocalFileStorage(storageRoot));

        services.AddSingleton<IAiService>(sp =>
        {
            var factory = sp.GetRequiredService<IHttpClientFactory>();
            var apiKey = configuration["Ai:ApiKey"];
            var model = configuration["Ai:Model"] ?? "gpt-4o-mini";
            var baseUrl = configuration["Ai:BaseUrl"] ?? "https://api.openai.com/v1";
            return new OpenAiCompatibleService(factory, apiKey, model, baseUrl);
        });

        return services;
    }
}
