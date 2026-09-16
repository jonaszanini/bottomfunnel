using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Infrastructure.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Infrastructure.Persistence;

public class AppDbContext : IdentityDbContext<ApplicationUser>, IAppDbContext
{
    public AppDbContext(DbContextOptions<AppDbContext> options) : base(options)
    {
    }

    public DbSet<Organization> Organizations => Set<Organization>();
    public DbSet<OrganizationMember> OrganizationMembers => Set<OrganizationMember>();
    public DbSet<Merchant> Merchants => Set<Merchant>();
    public DbSet<AffiliateNetwork> AffiliateNetworks => Set<AffiliateNetwork>();
    public DbSet<StoreConnection> StoreConnections => Set<StoreConnection>();
    public DbSet<Product> Products => Set<Product>();
    public DbSet<Offer> Offers => Set<Offer>();
    public DbSet<ComplianceRestriction> ComplianceRestrictions => Set<ComplianceRestriction>();
    public DbSet<Keyword> Keywords => Set<Keyword>();
    public DbSet<KeywordMetric> KeywordMetrics => Set<KeywordMetric>();
    public DbSet<KeywordOpportunity> KeywordOpportunities => Set<KeywordOpportunity>();
    public DbSet<Campaign> Campaigns => Set<Campaign>();
    public DbSet<CampaignKeyword> CampaignKeywords => Set<CampaignKeyword>();
    public DbSet<Ad> Ads => Set<Ad>();
    public DbSet<LandingPage> LandingPages => Set<LandingPage>();
    public DbSet<Click> Clicks => Set<Click>();
    public DbSet<Conversion> Conversions => Set<Conversion>();
    public DbSet<Cost> Costs => Set<Cost>();
    public DbSet<CommissionLedger> CommissionLedgers => Set<CommissionLedger>();
    public DbSet<Experiment> Experiments => Set<Experiment>();
    public DbSet<AiAnalysis> AiAnalyses => Set<AiAnalysis>();
    public DbSet<BackgroundJob> BackgroundJobs => Set<BackgroundJob>();
    public DbSet<VisitorSession> VisitorSessions => Set<VisitorSession>();
    public DbSet<Visit> Visits => Set<Visit>();

    protected override void OnModelCreating(ModelBuilder builder)
    {
        base.OnModelCreating(builder);

        foreach (var property in builder.Model.GetEntityTypes()
                     .SelectMany(type => type.GetProperties())
                     .Where(property => property.ClrType == typeof(decimal) || property.ClrType == typeof(decimal?)))
        {
            property.SetPrecision(18);
            property.SetScale(4);
        }

        builder.Entity<Organization>().Property(x => x.Name).HasMaxLength(200).IsRequired();
        builder.Entity<OrganizationMember>().HasIndex(x => new { x.OrganizationId, x.UserId }).IsUnique();
        builder.Entity<OrganizationMember>()
            .HasOne(x => x.Organization)
            .WithMany(x => x.Members)
            .HasForeignKey(x => x.OrganizationId);

        builder.Entity<Merchant>().HasIndex(x => new { x.OrganizationId, x.Name });
        builder.Entity<Product>().HasIndex(x => new { x.OrganizationId, x.ProviderKey, x.ExternalProductId });
        builder.Entity<Offer>().HasIndex(x => new { x.OrganizationId, x.ProviderKey, x.ExternalProductId });
        builder.Entity<Offer>()
            .HasOne(x => x.Product)
            .WithMany(x => x.Offers)
            .HasForeignKey(x => x.ProductId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.Entity<Offer>()
            .HasOne(x => x.Merchant)
            .WithMany(x => x.Offers)
            .HasForeignKey(x => x.MerchantId)
            .OnDelete(DeleteBehavior.SetNull);

        builder.Entity<Keyword>().HasIndex(x => new { x.OrganizationId, x.ProductId, x.Phrase }).IsUnique();
        builder.Entity<Keyword>()
            .HasOne(x => x.Product)
            .WithMany(x => x.Keywords)
            .HasForeignKey(x => x.ProductId);
        builder.Entity<Keyword>()
            .HasOne(x => x.Metric)
            .WithOne(x => x.Keyword)
            .HasForeignKey<KeywordMetric>(x => x.KeywordId);

        builder.Entity<KeywordOpportunity>().HasIndex(x => new { x.OrganizationId, x.KeywordId, x.OfferId }).IsUnique();

        builder.Entity<ComplianceRestriction>()
            .HasOne(x => x.Merchant)
            .WithMany(x => x.Restrictions)
            .HasForeignKey(x => x.MerchantId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.Entity<ComplianceRestriction>()
            .HasOne(x => x.Offer)
            .WithMany(x => x.Restrictions)
            .HasForeignKey(x => x.OfferId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.Entity<ComplianceRestriction>().HasIndex(x => new { x.OrganizationId, x.MerchantId, x.Kind });
        builder.Entity<ComplianceRestriction>().HasIndex(x => new { x.OrganizationId, x.OfferId, x.Kind });

        builder.Entity<VisitorSession>().HasIndex(x => new { x.OrganizationId, x.SessionKey }).IsUnique();
        builder.Entity<Visit>().HasIndex(x => new { x.OrganizationId, x.LandingPageId, x.OccurredAt });
        builder.Entity<Visit>().HasIndex(x => x.Gclid);
        builder.Entity<Visit>()
            .HasOne(x => x.Session)
            .WithMany(x => x.Visits)
            .HasForeignKey(x => x.SessionId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Entity<Click>().HasIndex(x => x.Gclid);
        builder.Entity<Click>().HasIndex(x => x.SessionId);
        builder.Entity<Conversion>().HasIndex(x => x.ExternalOrderId);
        builder.Entity<Conversion>().HasIndex(x => x.Gclid);

        builder.Entity<Campaign>().HasIndex(x => x.TrackingCode).IsUnique();
        builder.Entity<CampaignKeyword>().HasIndex(x => new { x.CampaignId, x.KeywordId }).IsUnique();
        builder.Entity<LandingPage>().HasIndex(x => new { x.OrganizationId, x.Slug }).IsUnique();

        builder.Entity<Click>().HasIndex(x => x.TrackingCode);
        builder.Entity<StoreConnection>().HasIndex(x => new { x.OrganizationId, x.ProviderKey }).IsUnique();
        builder.Entity<BackgroundJob>().HasIndex(x => new { x.Status, x.CreatedAt });
    }
}
