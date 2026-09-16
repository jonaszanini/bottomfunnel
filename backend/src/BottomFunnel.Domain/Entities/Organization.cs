using BottomFunnel.Domain.Common;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Entities;

public class Organization : Entity
{
    public string Name { get; set; } = string.Empty;
    public ICollection<OrganizationMember> Members { get; set; } = new List<OrganizationMember>();
}

public class OrganizationMember : Entity
{
    public Guid OrganizationId { get; set; }
    public string UserId { get; set; } = string.Empty;
    public OrganizationRole Role { get; set; } = OrganizationRole.Member;
    public Organization? Organization { get; set; }
}

public class Merchant : OrgEntity
{
    public string Name { get; set; } = string.Empty;
    public string? Website { get; set; }
    public string? Country { get; set; }
    public string? ExternalVendorId { get; set; }
    public string? ProviderKey { get; set; }
    public bool PpcAllowed { get; set; } = true;
    public bool BrandBiddingAllowed { get; set; }
    public bool BrandPlusModifierAllowed { get; set; }
    public bool CouponBiddingAllowed { get; set; }
    public bool DirectLinkingAllowed { get; set; }
    public string? TrademarkNotes { get; set; }
    public string? AdRestrictions { get; set; }
    public string? LandingPageRestrictions { get; set; }
    public string? PolicyNotes { get; set; }
    public ICollection<Offer> Offers { get; set; } = new List<Offer>();
    public ICollection<ComplianceRestriction> Restrictions { get; set; } = new List<ComplianceRestriction>();
}

public class AffiliateNetwork : OrgEntity
{
    public string Name { get; set; } = string.Empty;
    public string ProviderKey { get; set; } = "manual";
    public string? Website { get; set; }
}

public class StoreConnection : OrgEntity
{
    public string ProviderKey { get; set; } = string.Empty;
    public string DisplayName { get; set; } = string.Empty;
    public string EncryptedCredentials { get; set; } = string.Empty;
    public StoreConnectionStatus Status { get; set; } = StoreConnectionStatus.Disconnected;
    public DateTimeOffset? LastSyncedAt { get; set; }
    public string? LastError { get; set; }
}

public class Product : OrgEntity
{
    public string Name { get; set; } = string.Empty;
    public string? Description { get; set; }
    public string? Category { get; set; }
    public string? Country { get; set; }
    public string Currency { get; set; } = "USD";
    public decimal? AverageOrderValue { get; set; }
    public string? ProviderKey { get; set; }
    public string? ExternalProductId { get; set; }
    public ICollection<Offer> Offers { get; set; } = new List<Offer>();
    public ICollection<Keyword> Keywords { get; set; } = new List<Keyword>();
}

public class Offer : OrgEntity
{
    public Guid ProductId { get; set; }
    public Guid? MerchantId { get; set; }
    public Guid? AffiliateNetworkId { get; set; }
    public Guid? StoreConnectionId { get; set; }
    public string Name { get; set; } = string.Empty;
    public string? AffiliateUrl { get; set; }
    public string? AffiliateUrlTemplate { get; set; }
    public decimal Commission { get; set; }
    public decimal PayoutAmountCad { get; set; }
    public decimal ExchangeRateUsed { get; set; }
    public CommissionType CommissionType { get; set; } = CommissionType.OneTime;
    public decimal? RecurringCommission { get; set; }
    public decimal? AverageOrderValue { get; set; }
    public string Currency { get; set; } = "USD";
    public string? Country { get; set; }
    public string? Category { get; set; }
    public string? ApprovalRequirements { get; set; }
    public bool? PpcAllowed { get; set; }
    public bool? BrandBiddingAllowed { get; set; }
    public bool? BrandPlusModifierAllowed { get; set; }
    public bool? CouponBiddingAllowed { get; set; }
    public bool? DirectLinkingAllowed { get; set; }
    public string? TrademarkNotes { get; set; }
    public string? AdRestrictions { get; set; }
    public string? LandingPageRestrictions { get; set; }
    public string? PolicyNotes { get; set; }
    public OfferStatus Status { get; set; } = OfferStatus.Active;
    public string ProviderKey { get; set; } = "manual";
    public string? ExternalProductId { get; set; }
    public string? ExternalVendorId { get; set; }
    public string? RawPayloadJson { get; set; }
    public Product? Product { get; set; }
    public Merchant? Merchant { get; set; }
    public AffiliateNetwork? AffiliateNetwork { get; set; }
    public ICollection<ComplianceRestriction> Restrictions { get; set; } = new List<ComplianceRestriction>();
}

public class ComplianceRestriction : OrgEntity
{
    public Guid? MerchantId { get; set; }
    public Guid? OfferId { get; set; }
    public RestrictionKind Kind { get; set; }
    public ComplianceState State { get; set; } = ComplianceState.Unknown;
    public string? Source { get; set; }
    public string? Evidence { get; set; }
    public DateTimeOffset? CheckedAtUtc { get; set; }
    public DateTimeOffset? NextReviewAtUtc { get; set; }
    public DataConfidence Confidence { get; set; } = DataConfidence.Low;
    public Merchant? Merchant { get; set; }
    public Offer? Offer { get; set; }
}

public class Keyword : OrgEntity
{
    public Guid ProductId { get; set; }
    public string Phrase { get; set; } = string.Empty;
    public KeywordTemplateKind Template { get; set; } = KeywordTemplateKind.Product;
    public SearchIntent Intent { get; set; } = SearchIntent.Commercial;
    public int IntentScore { get; set; }
    public ComplianceState ComplianceStatus { get; set; } = ComplianceState.Unknown;
    public ComplianceState BrandStatus { get; set; } = ComplianceState.Unknown;
    public string? BlockedReason { get; set; }
    public Product? Product { get; set; }
    public KeywordMetric? Metric { get; set; }
    public ICollection<KeywordOpportunity> Opportunities { get; set; } = new List<KeywordOpportunity>();
}

public class KeywordMetric : OrgEntity
{
    public Guid KeywordId { get; set; }
    public decimal? EstimatedCpc { get; set; }
    public CompetitionLevel Competition { get; set; } = CompetitionLevel.Unknown;
    public long? SearchVolume { get; set; }
    public string? SearchVolumeSource { get; set; }
    public string? SearchVolumePeriod { get; set; }
    public DataConfidence SearchVolumeConfidence { get; set; } = DataConfidence.Low;
    public LowSearchVolumeRisk LowSearchVolumeRisk { get; set; } = LowSearchVolumeRisk.Unknown;
    public decimal? OtherCostsPerClick { get; set; }
    public Keyword? Keyword { get; set; }
}

public class KeywordOpportunity : OrgEntity
{
    public Guid KeywordId { get; set; }
    public Guid OfferId { get; set; }
    public Guid ProductId { get; set; }
    public int Score { get; set; }
    public int CommercialScore { get; set; }
    public int ViabilityScore { get; set; }
    public int ComplianceScore { get; set; }
    public int Confidence { get; set; }
    public int CommercialIntent { get; set; }
    public decimal ExpectedRevenuePerClick { get; set; }
    public decimal ExpectedProfitPerClick { get; set; }
    public decimal ExpectedProfitConservative { get; set; }
    public decimal ExpectedProfitBase { get; set; }
    public decimal ExpectedProfitOptimistic { get; set; }
    public decimal EstimatedConversionRate { get; set; }
    public decimal EstimatedCpc { get; set; }
    public decimal Commission { get; set; }
    public CompetitionLevel Competition { get; set; }
    public OpportunityRecommendation Recommendation { get; set; }
    public string? Notes { get; set; }
    public string? Why { get; set; }
    public Keyword? Keyword { get; set; }
    public Offer? Offer { get; set; }
    public Product? Product { get; set; }
}

public class Campaign : OrgEntity
{
    public Guid? OfferId { get; set; }
    public Guid? ProductId { get; set; }
    public Guid? OpportunityId { get; set; }
    public Guid? LandingPageId { get; set; }
    public string Name { get; set; } = string.Empty;
    public CampaignStatus Status { get; set; } = CampaignStatus.Draft;
    public string? Country { get; set; }
    public string TrackingCode { get; set; } = string.Empty;
    public Offer? Offer { get; set; }
    public Product? Product { get; set; }
    public LandingPage? LandingPage { get; set; }
    public ICollection<CampaignKeyword> Keywords { get; set; } = new List<CampaignKeyword>();
    public ICollection<Ad> Ads { get; set; } = new List<Ad>();
    public ICollection<Click> Clicks { get; set; } = new List<Click>();
}

public class CampaignKeyword : OrgEntity
{
    public Guid CampaignId { get; set; }
    public Guid KeywordId { get; set; }
    public Campaign? Campaign { get; set; }
    public Keyword? Keyword { get; set; }
}

public class Ad : OrgEntity
{
    public Guid CampaignId { get; set; }
    public string Headline { get; set; } = string.Empty;
    public string? Description { get; set; }
    public string? FinalUrl { get; set; }
    public bool IsActive { get; set; } = true;
    public Campaign? Campaign { get; set; }
}

public class LandingPage : OrgEntity
{
    public Guid? OfferId { get; set; }
    public Guid? ProductId { get; set; }
    public string Name { get; set; } = string.Empty;
    public string Slug { get; set; } = string.Empty;
    public string Headline { get; set; } = string.Empty;
    public string? Subheadline { get; set; }
    public string? BenefitsJson { get; set; }
    public string? ComparisonJson { get; set; }
    public string CtaText { get; set; } = "Get it now";
    public string? FaqJson { get; set; }
    public string? Body { get; set; }
    public string? Disclosure { get; set; }
    public string? WhoItsFor { get; set; }
    public string? WhoItsNotFor { get; set; }
    public string? ProsJson { get; set; }
    public string? ConsJson { get; set; }
    public string? Alternatives { get; set; }
    public bool IsPublished { get; set; }
    public Offer? Offer { get; set; }
    public Product? Product { get; set; }
}

public class Click : OrgEntity
{
    public Guid CampaignId { get; set; }
    public Guid? AdId { get; set; }
    public Guid? KeywordId { get; set; }
    public Guid? OfferId { get; set; }
    public Guid? LandingPageId { get; set; }
    public Guid? SessionId { get; set; }
    public Guid? VisitId { get; set; }
    public string TrackingCode { get; set; } = string.Empty;
    public string DestinationUrl { get; set; } = string.Empty;
    public string? UserAgent { get; set; }
    public string? IpHash { get; set; }
    public string? Referrer { get; set; }
    public string? Gclid { get; set; }
    public string? SubId { get; set; }
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
    public Campaign? Campaign { get; set; }
    public ICollection<Conversion> Conversions { get; set; } = new List<Conversion>();
}

public class Conversion : OrgEntity
{
    public Guid? ClickId { get; set; }
    public Guid? CampaignId { get; set; }
    public Guid? OfferId { get; set; }
    public Guid? KeywordId { get; set; }
    public Guid? LandingPageId { get; set; }
    public Guid? SessionId { get; set; }
    public string ProviderKey { get; set; } = "manual";
    public string? ExternalOrderId { get; set; }
    public string? Gclid { get; set; }
    public string? SubId { get; set; }
    public ConversionEventType EventType { get; set; } = ConversionEventType.Sale;
    public ConversionProvenance Provenance { get; set; } = ConversionProvenance.Observed;
    public int AttributionConfidence { get; set; }
    public decimal Amount { get; set; }
    public decimal Commission { get; set; }
    public string Currency { get; set; } = "USD";
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
    public string? RawPayloadJson { get; set; }
    public Click? Click { get; set; }
}

public class Cost : OrgEntity
{
    public Guid? CampaignId { get; set; }
    public Guid? KeywordId { get; set; }
    public Guid? OfferId { get; set; }
    public decimal Amount { get; set; }
    public string Currency { get; set; } = "USD";
    public string Source { get; set; } = "manual";
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
    public string? Notes { get; set; }
}

public class CommissionLedger : OrgEntity
{
    public Guid? ConversionId { get; set; }
    public Guid? OfferId { get; set; }
    public decimal Amount { get; set; }
    public string Currency { get; set; } = "USD";
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
}

public class Experiment : OrgEntity
{
    public Guid CampaignId { get; set; }
    public string Name { get; set; } = string.Empty;
    public string? Hypothesis { get; set; }
    public string Status { get; set; } = "running";
    public Campaign? Campaign { get; set; }
}

public class AiAnalysis : OrgEntity
{
    public string SubjectType { get; set; } = string.Empty;
    public Guid SubjectId { get; set; }
    public string Kind { get; set; } = string.Empty;
    public string Prompt { get; set; } = string.Empty;
    public string Result { get; set; } = string.Empty;
}

public class BackgroundJob : Entity
{
    public Guid? OrganizationId { get; set; }
    public string JobType { get; set; } = string.Empty;
    public string PayloadJson { get; set; } = "{}";
    public JobStatus Status { get; set; } = JobStatus.Pending;
    public int Attempts { get; set; }
    public string? LastError { get; set; }
    public DateTimeOffset? ProcessedAt { get; set; }
}

public class VisitorSession : OrgEntity
{
    public string SessionKey { get; set; } = string.Empty;
    public DateTimeOffset LastSeenAt { get; set; } = DateTimeOffset.UtcNow;
    public ICollection<Visit> Visits { get; set; } = new List<Visit>();
}

public class Visit : OrgEntity
{
    public Guid SessionId { get; set; }
    public Guid? LandingPageId { get; set; }
    public Guid? CampaignId { get; set; }
    public Guid? KeywordId { get; set; }
    public string? Gclid { get; set; }
    public string? UserAgent { get; set; }
    public string? Device { get; set; }
    public string? Referrer { get; set; }
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
    public VisitorSession? Session { get; set; }
}
