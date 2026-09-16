using BottomFunnel.Application.Services;
using FluentValidation;
using Microsoft.Extensions.DependencyInjection;

namespace BottomFunnel.Application;

public static class DependencyInjection
{
    public static IServiceCollection AddApplication(this IServiceCollection services)
    {
        services.AddScoped<CatalogService>();
        services.AddScoped<ComplianceService>();
        services.AddScoped<KeywordOpportunityService>();
        services.AddScoped<CampaignService>();
        services.AddScoped<LandingPageService>();
        services.AddScoped<StoreConnectionService>();
        services.AddScoped<TrackingAnalyticsService>();
        services.AddScoped<AiOrchestrator>();
        services.AddScoped<CreateUnifiedOfferCommandHandler>();
        services.AddValidatorsFromAssemblyContaining<Validation.UpsertOfferRequestValidator>();
        return services;
    }
}
