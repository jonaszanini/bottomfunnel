using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Application.Dtos;

public sealed record IdNameDto(Guid Id, string Name);

public sealed record PolicyFlagsDto(
    bool PpcAllowed,
    bool BrandBiddingAllowed,
    bool BrandPlusModifierAllowed,
    bool CouponBiddingAllowed,
    bool DirectLinkingAllowed);

public sealed record ComplianceRestrictionDto(
    Guid Id,
    Guid? MerchantId,
    Guid? OfferId,
    RestrictionKind Kind,
    ComplianceState State,
    string? Source,
    string? Evidence,
    DateTimeOffset? CheckedAtUtc,
    DateTimeOffset? NextReviewAtUtc,
    DataConfidence Confidence);

public sealed record UpsertRestrictionRequest(
    RestrictionKind Kind,
    ComplianceState State,
    string? Source,
    string? Evidence,
    DateTimeOffset? NextReviewAtUtc,
    DataConfidence Confidence);

public sealed record MerchantDto(
    Guid Id,
    string Name,
    string? Website,
    string? Country,
    string? ProviderKey,
    bool PpcAllowed,
    bool BrandBiddingAllowed,
    bool BrandPlusModifierAllowed,
    bool CouponBiddingAllowed,
    bool DirectLinkingAllowed,
    string? TrademarkNotes,
    string? AdRestrictions,
    string? LandingPageRestrictions,
    string? PolicyNotes,
    IReadOnlyList<ComplianceRestrictionDto> Restrictions);

public sealed record UpsertMerchantRequest(
    string Name,
    string? Website,
    string? Country,
    bool PpcAllowed = true,
    bool BrandBiddingAllowed = false,
    bool BrandPlusModifierAllowed = false,
    bool CouponBiddingAllowed = false,
    bool DirectLinkingAllowed = false,
    string? TrademarkNotes = null,
    string? AdRestrictions = null,
    string? LandingPageRestrictions = null,
    string? PolicyNotes = null);

public sealed record ProductDto(
    Guid Id,
    string Name,
    string? Description,
    string? Category,
    string? Country,
    string Currency,
    decimal? AverageOrderValue,
    string? ProviderKey,
    string? ExternalProductId);

public sealed record UpsertProductRequest(
    string Name,
    string? Description,
    string? Category,
    string? Country,
    string Currency,
    decimal? AverageOrderValue);

public sealed record OfferDto(
    Guid Id,
    Guid ProductId,
    string ProductName,
    Guid? MerchantId,
    string? MerchantName,
    string Name,
    string? AffiliateUrl,
    decimal Commission,
    decimal PayoutAmountCad,
    decimal ExchangeRateUsed,
    CommissionType CommissionType,
    decimal? RecurringCommission,
    decimal? AverageOrderValue,
    string Currency,
    string? Country,
    string? Category,
    OfferStatus Status,
    string ProviderKey,
    string? ExternalProductId,
    bool? PpcAllowed,
    bool? BrandBiddingAllowed,
    bool? BrandPlusModifierAllowed,
    bool? CouponBiddingAllowed,
    bool? DirectLinkingAllowed,
    string? TrademarkNotes,
    string? AdRestrictions,
    string? LandingPageRestrictions,
    string? PolicyNotes,
    string? TrafficRestrictions,
    PolicyFlagsDto ResolvedPolicy,
    ComplianceState OverallCompliance,
    IReadOnlyList<ComplianceRestrictionDto> Restrictions,
    OfferAuditSummaryDto? LatestAudit);

public sealed record OfferAuditSummaryDto(
    string OverallRisk,
    int RiskScore,
    DateTimeOffset AuditedAt);

public sealed record UpsertOfferRequest(
    Guid ProductId,
    Guid? MerchantId,
    Guid? AffiliateNetworkId,
    string Name,
    string? AffiliateUrl,
    decimal Commission,
    CommissionType CommissionType,
    decimal? RecurringCommission,
    decimal? AverageOrderValue,
    string Currency,
    string? Country,
    string? Category,
    string? ApprovalRequirements,
    OfferStatus Status,
    bool? PpcAllowed = null,
    bool? BrandBiddingAllowed = null,
    bool? BrandPlusModifierAllowed = null,
    bool? CouponBiddingAllowed = null,
    bool? DirectLinkingAllowed = null,
    string? TrademarkNotes = null,
    string? AdRestrictions = null,
    string? LandingPageRestrictions = null,
    string? PolicyNotes = null,
    string? TrafficRestrictions = null);

public sealed record CreateUnifiedOfferRequest(
    string ProductName,
    string? Category,
    string MerchantName,
    bool BrandBiddingAllowed = false,
    bool DirectLinkingAllowed = false,
    bool PpcAllowed = true,
    decimal PayoutAmount = 0,
    string Currency = "USD",
    decimal? ExchangeRateOverride = null,
    CommissionType CommissionType = CommissionType.OneTime,
    string? AffiliateUrl = null,
    string? BaseAffiliateUrl = null,
    string? AffiliatePageUrl = null,
    string? ProviderKey = null,
    string? TrafficRestrictions = null);

public sealed record CreateUnifiedOfferResponse(
    Guid OfferId,
    Guid ProductId,
    Guid MerchantId,
    decimal PayoutAmount,
    decimal PayoutAmountCad,
    decimal ExchangeRateUsed,
    string Currency);

public sealed record CurrencyRateDto(string From, string To, decimal Rate);

public sealed record KeywordDto(
    Guid Id,
    Guid ProductId,
    string Phrase,
    KeywordTemplateKind Template,
    SearchIntent Intent,
    int IntentScore,
    decimal? EstimatedCpc,
    CompetitionLevel Competition,
    long? SearchVolume,
    LowSearchVolumeRisk LowSearchVolumeRisk,
    ComplianceState ComplianceStatus,
    ComplianceState BrandStatus,
    string? BlockedReason);

public sealed record UpsertKeywordMetricsRequest(
    decimal? EstimatedCpc,
    CompetitionLevel Competition,
    long? SearchVolume,
    decimal? OtherCostsPerClick,
    string? SearchVolumeSource = null,
    string? SearchVolumePeriod = null,
    DataConfidence? SearchVolumeConfidence = null);

public sealed record OpportunityDto(
    Guid Id,
    Guid ProductId,
    string ProductName,
    Guid KeywordId,
    string Keyword,
    Guid OfferId,
    string OfferName,
    int Score,
    int CommercialScore,
    int ViabilityScore,
    int ComplianceScore,
    int Confidence,
    int CommercialIntent,
    decimal EstimatedCpc,
    decimal Commission,
    decimal EstimatedConversionRate,
    decimal ExpectedRevenuePerClick,
    decimal ExpectedProfitPerClick,
    decimal ExpectedProfitConservative,
    decimal ExpectedProfitBase,
    decimal ExpectedProfitOptimistic,
    CompetitionLevel Competition,
    OpportunityRecommendation Recommendation,
    string? Why,
    string? BlockedReason);

public sealed record CampaignDto(
    Guid Id,
    string Name,
    CampaignStatus Status,
    Guid? OfferId,
    Guid? ProductId,
    Guid? LandingPageId,
    string? LandingPageSlug,
    string TrackingCode,
    string? GoogleFinalUrl,
    string? CtaHopUrl,
    string? Country,
    IReadOnlyList<Guid> KeywordIds,
    IReadOnlyList<AdDto> Ads);

public sealed record UpsertCampaignRequest(
    string Name,
    Guid? OfferId,
    Guid? ProductId,
    Guid? OpportunityId,
    Guid? LandingPageId,
    string? Country,
    CampaignStatus Status,
    IReadOnlyList<Guid>? KeywordIds);

public sealed record AdDto(Guid Id, Guid CampaignId, string Headline, string? Description, string? FinalUrl, bool IsActive);
public sealed record UpsertAdRequest(string Headline, string? Description, string? FinalUrl, bool IsActive);

public sealed record LandingPageDto(
    Guid Id,
    string Name,
    string Slug,
    string Headline,
    string? Subheadline,
    IReadOnlyList<string> Benefits,
    string? Comparison,
    string CtaText,
    IReadOnlyList<FaqItemDto> Faqs,
    string? Body,
    string? Disclosure,
    string? WhoItsFor,
    string? WhoItsNotFor,
    IReadOnlyList<string> Pros,
    IReadOnlyList<string> Cons,
    string? Alternatives,
    bool IsPublished,
    Guid? OfferId,
    Guid? ProductId,
    string? TrackingCode,
    string? GoogleFinalUrl,
    string? CtaUrl);

public sealed record FaqItemDto(string Question, string Answer);
public sealed record UpsertLandingPageRequest(
    string Name,
    string Slug,
    string Headline,
    string? Subheadline,
    IReadOnlyList<string>? Benefits,
    string? Comparison,
    string CtaText,
    IReadOnlyList<FaqItemDto>? Faqs,
    string? Body,
    string? Disclosure,
    string? WhoItsFor,
    string? WhoItsNotFor,
    IReadOnlyList<string>? Pros,
    IReadOnlyList<string>? Cons,
    string? Alternatives,
    bool IsPublished,
    Guid? OfferId,
    Guid? ProductId);

public sealed record ClickDto(
    Guid Id,
    Guid CampaignId,
    Guid? KeywordId,
    Guid? AdId,
    string TrackingCode,
    string DestinationUrl,
    DateTimeOffset OccurredAt,
    string? Gclid,
    ConversionProvenance? Provenance);

public sealed record ConversionDto(
    Guid Id,
    Guid? ClickId,
    Guid? CampaignId,
    string ProviderKey,
    ConversionEventType EventType,
    ConversionProvenance Provenance,
    int AttributionConfidence,
    decimal Amount,
    decimal Commission,
    string Currency,
    DateTimeOffset OccurredAt);

public sealed record CreateConversionRequest(
    Guid? ClickId,
    Guid? CampaignId,
    Guid? OfferId,
    decimal Amount,
    decimal Commission,
    string Currency,
    ConversionEventType EventType);

public sealed record CreateCostRequest(Guid? CampaignId, Guid? KeywordId, Guid? OfferId, decimal Amount, string Currency, string? Notes);

public sealed record CaptureVisitRequest(
    string Slug,
    string SessionKey,
    string? Gclid,
    Guid? CampaignId,
    Guid? KeywordId,
    string? UserAgent,
    string? Referrer,
    string? Device);

public sealed record CaptureVisitResponse(
    Guid VisitId,
    Guid SessionId,
    string? TrackingCode,
    string? CtaUrl);

public sealed record DashboardDto(
    decimal Revenue,
    decimal AdSpend,
    decimal Profit,
    decimal RoiPercent,
    long LandingVisits,
    long AffiliateClicks,
    decimal ReportedCommission,
    decimal AttributableCommission,
    int AttributionConfidencePercent,
    decimal ObservedRoas,
    decimal ReportedRoas,
    IReadOnlyList<OpportunityDto> TopOpportunities,
    IReadOnlyList<CampaignProfitDto> TopCampaigns);

public sealed record CampaignProfitDto(
    Guid CampaignId,
    string Name,
    decimal Spend,
    decimal Revenue,
    decimal Profit,
    decimal ObservedRevenue,
    decimal ReportedRevenue,
    decimal ObservedRoas,
    decimal ReportedRoas,
    int AttributionConfidence,
    bool InsufficientConfidence);

public sealed record StoreProviderDto(
    string ProviderKey,
    string DisplayName,
    StoreCapabilities Capabilities,
    IReadOnlyList<CredentialFieldDto> CredentialSchema);

public sealed record CredentialFieldDto(string Key, string Label, bool Secret, bool Required, string? Placeholder);

public sealed record StoreConnectionDto(
    Guid Id,
    string ProviderKey,
    string DisplayName,
    StoreConnectionStatus Status,
    DateTimeOffset? LastSyncedAt,
    string? LastError);

public sealed record SaveStoreConnectionRequest(string ProviderKey, string DisplayName, Dictionary<string, string> Credentials);

public sealed record AiAnalyzeRequest(string SubjectType, Guid SubjectId, string Kind);
public sealed record AiAnalyzeResponse(string Result, bool UsedAi);

public sealed record AiProviderStatusDto(
    string Provider,
    string? MaskedApiKey,
    string DefaultModel,
    bool IsActive,
    bool Configured,
    string? Source);

public sealed record SaveAiProviderRequest(
    string Provider,
    string ApiKey,
    string DefaultModel,
    bool IsActive = true);

public sealed record TestAiProviderRequest(string Provider, string ApiKey, string? DefaultModel = null);
public sealed record AiConnectionTestDto(bool Success, string Message);
