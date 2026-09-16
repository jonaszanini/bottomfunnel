export type PolicyFlags = {
  ppcAllowed: boolean;
  brandBiddingAllowed: boolean;
  brandPlusModifierAllowed: boolean;
  couponBiddingAllowed: boolean;
  directLinkingAllowed: boolean;
};

export type ComplianceRestriction = {
  id: string;
  merchantId?: string;
  offerId?: string;
  kind: string;
  state: string;
  source?: string;
  evidence?: string;
  checkedAtUtc?: string;
  nextReviewAtUtc?: string;
  confidence: string;
};

export type Merchant = {
  id: string; name: string; website?: string; country?: string; providerKey?: string;
  ppcAllowed: boolean; brandBiddingAllowed: boolean; brandPlusModifierAllowed: boolean;
  couponBiddingAllowed: boolean; directLinkingAllowed: boolean;
  trademarkNotes?: string; adRestrictions?: string; landingPageRestrictions?: string; policyNotes?: string;
  restrictions: ComplianceRestriction[];
};

export type Product = {
  id: string; name: string; description?: string; category?: string; country?: string;
  currency: string; averageOrderValue?: number; providerKey?: string; externalProductId?: string;
};

export type Offer = {
  id: string; productId: string; productName: string; merchantId?: string; merchantName?: string;
  name: string; affiliateUrl?: string; commission: number; payoutAmountCad: number; exchangeRateUsed: number;
  commissionType: string;
  recurringCommission?: number; averageOrderValue?: number; currency: string; country?: string;
  category?: string; status: string; providerKey: string; externalProductId?: string;
  ppcAllowed?: boolean | null; brandBiddingAllowed?: boolean | null; brandPlusModifierAllowed?: boolean | null;
  couponBiddingAllowed?: boolean | null; directLinkingAllowed?: boolean | null;
  trademarkNotes?: string; adRestrictions?: string; landingPageRestrictions?: string; policyNotes?: string;
  trafficRestrictions?: string | null;
  resolvedPolicy: PolicyFlags; overallCompliance: string; restrictions: ComplianceRestriction[];
  latestAudit?: OfferAuditSummary | null;
};

export type OfferAuditSummary = {
  overallRisk: string;
  riskScore: number;
  auditedAt: string;
};

export type PolicyViolation = {
  policyCategory: string;
  triggerPhrase: string;
  explanation: string;
  severity: string;
};

export type OfferPolicyAuditResult = {
  overallRisk: "Low" | "Moderate" | "Severe" | "Critical";
  riskScore: number;
  allowsBrandBidding: boolean;
  mandatoryNegativeKeywords: string[];
  violations: PolicyViolation[];
  approvedAngles: string[];
  disallowedAngles: string[];
  executiveSummary: string;
};

export type Keyword = {
  id: string; productId: string; phrase: string; template: string; intent: string;
  intentScore: number; estimatedCpc?: number; competition: string;
  searchVolume?: number; lowSearchVolumeRisk: string;
  complianceStatus: string; brandStatus: string; blockedReason?: string;
};

export type Opportunity = {
  id: string; productId: string; productName: string; keywordId: string; keyword: string;
  offerId: string; offerName: string; score: number;
  commercialScore: number; viabilityScore: number; complianceScore: number; confidence: number;
  commercialIntent: number; estimatedCpc: number;
  commission: number; estimatedConversionRate: number; expectedRevenuePerClick: number;
  expectedProfitPerClick: number;
  expectedProfitConservative: number; expectedProfitBase: number; expectedProfitOptimistic: number;
  competition: string; recommendation: string; why?: string; blockedReason?: string;
};

export type Campaign = {
  id: string; name: string; status: string; offerId?: string; productId?: string;
  landingPageId?: string; landingPageSlug?: string; trackingCode: string;
  googleFinalUrl?: string; ctaHopUrl?: string; country?: string; keywordIds: string[];
  ads: { id: string; campaignId: string; headline: string; description?: string; finalUrl?: string; isActive: boolean }[];
};

export type LandingPage = {
  id: string; name: string; slug: string; headline: string; subheadline?: string;
  benefits: string[]; comparison?: string; ctaText: string;
  faqs: { question: string; answer: string }[]; body?: string;
  disclosure?: string; whoItsFor?: string; whoItsNotFor?: string;
  pros: string[]; cons: string[]; alternatives?: string;
  isPublished: boolean; offerId?: string; productId?: string;
  trackingCode?: string; googleFinalUrl?: string; ctaUrl?: string;
};

export type Dashboard = {
  revenue: number; adSpend: number; profit: number; roiPercent: number;
  landingVisits: number; affiliateClicks: number;
  reportedCommission: number; attributableCommission: number;
  attributionConfidencePercent: number; observedRoas: number; reportedRoas: number;
  topOpportunities: Opportunity[];
  topCampaigns: {
    campaignId: string; name: string; spend: number; revenue: number; profit: number;
    observedRevenue: number; reportedRevenue: number; observedRoas: number; reportedRoas: number;
    attributionConfidence: number; insufficientConfidence: boolean;
  }[];
};

export type StoreProvider = {
  providerKey: string; displayName: string; capabilities: number;
  credentialSchema: { key: string; label: string; secret: boolean; required: boolean; placeholder?: string }[];
};

export type StoreConnection = {
  id: string; providerKey: string; displayName: string; status: string;
  lastSyncedAt?: string; lastError?: string;
};
