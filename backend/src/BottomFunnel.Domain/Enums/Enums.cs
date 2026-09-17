namespace BottomFunnel.Domain.Enums;

public enum CommissionType
{
    OneTime = 0,
    Recurring = 1,
    Hybrid = 2
}

public enum OfferStatus
{
    Draft = 0,
    Active = 1,
    Paused = 2,
    Archived = 3
}

public enum SearchIntent
{
    Informational = 0,
    Navigational = 1,
    Commercial = 2,
    Transactional = 3
}

public enum OpportunityRecommendation
{
    Skip = 0,
    Investigate = 1,
    Test = 2
}

public enum CampaignStatus
{
    Draft = 0,
    Ready = 1,
    Live = 2,
    Paused = 3,
    Killed = 4
}

public enum CompetitionLevel
{
    Unknown = 0,
    Low = 1,
    Medium = 2,
    High = 3
}

public enum JobStatus
{
    Pending = 0,
    Processing = 1,
    Completed = 2,
    Failed = 3
}

public enum StoreConnectionStatus
{
    Disconnected = 0,
    Connected = 1,
    Error = 2
}

public enum ConversionEventType
{
    Sale = 0,
    Rebill = 1,
    Refund = 2,
    Chargeback = 3
}

public enum KeywordTemplateKind
{
    Product = 0,
    Review = 1,
    Price = 2,
    Coupon = 3,
    Discount = 4,
    Buy = 5,
    Official = 6,
    Alternative = 7,
    Versus = 8,
    Negative = 9
}

public enum OrganizationRole
{
    Owner = 0,
    Member = 1
}

[Flags]
public enum StoreCapabilities
{
    None = 0,
    CanListProducts = 1,
    CanBuildAffiliateUrl = 2,
    CanReceiveWebhooks = 4,
    CanListOrders = 8
}

public enum ComplianceState
{
    Unknown = 0,
    Researching = 1,
    Verified = 2,
    Restricted = 3,
    Prohibited = 4
}

public enum SearchTrendDirection
{
    Unknown = 0,
    Rising = 1,
    Stable = 2,
    Declining = 3
}

public enum RefundRiskLevel
{
    Low = 0,
    Moderate = 1,
    High = 2,
    Extreme = 3
}

public enum RestrictionKind
{
    Ppc = 0,
    Brand = 1,
    Coupon = 2,
    DirectLink = 3,
    TrademarkInAd = 4,
    LandingPage = 5,
    BrandPlusModifier = 6
}

public enum DataConfidence
{
    Low = 0,
    Medium = 1,
    High = 2
}

public enum LowSearchVolumeRisk
{
    Unknown = 0,
    Low = 1,
    Medium = 2,
    High = 3
}

public enum ConversionProvenance
{
    Observed = 0,
    Reported = 1,
    Attributed = 2
}

public enum CandidateStatus
{
    PendingReview = 0,
    Qualified = 1,
    Disqualified = 2,
    Promoted = 3
}
