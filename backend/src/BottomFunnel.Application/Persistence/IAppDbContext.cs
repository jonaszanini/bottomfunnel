using BottomFunnel.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Infrastructure;

namespace BottomFunnel.Application.Persistence;

public interface IAppDbContext
{
    DbSet<Organization> Organizations { get; }
    DbSet<OrganizationMember> OrganizationMembers { get; }
    DbSet<Merchant> Merchants { get; }
    DbSet<AffiliateNetwork> AffiliateNetworks { get; }
    DbSet<StoreConnection> StoreConnections { get; }
    DbSet<Product> Products { get; }
    DbSet<Offer> Offers { get; }
    DbSet<OfferAudit> OfferAudits { get; }
    DbSet<ComplianceRestriction> ComplianceRestrictions { get; }
    DbSet<Keyword> Keywords { get; }
    DbSet<KeywordMetric> KeywordMetrics { get; }
    DbSet<KeywordOpportunity> KeywordOpportunities { get; }
    DbSet<Campaign> Campaigns { get; }
    DbSet<CampaignKeyword> CampaignKeywords { get; }
    DbSet<Ad> Ads { get; }
    DbSet<LandingPage> LandingPages { get; }
    DbSet<Click> Clicks { get; }
    DbSet<Conversion> Conversions { get; }
    DbSet<Cost> Costs { get; }
    DbSet<CommissionLedger> CommissionLedgers { get; }
    DbSet<Experiment> Experiments { get; }
    DbSet<AiAnalysis> AiAnalyses { get; }
    DbSet<OrganizationAiSetting> OrganizationAiSettings { get; }
    DbSet<BackgroundJob> BackgroundJobs { get; }
    DbSet<VisitorSession> VisitorSessions { get; }
    DbSet<Visit> Visits { get; }
    DatabaseFacade Database { get; }

    Task<int> SaveChangesAsync(CancellationToken cancellationToken = default);
}
