using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Compliance;

public sealed record OfferPolicy(
    bool PpcAllowed,
    bool BrandBiddingAllowed,
    bool BrandPlusModifierAllowed,
    bool CouponBiddingAllowed,
    bool DirectLinkingAllowed);

public sealed record KeywordComplianceResult(
    bool Blocked,
    string? BlockedReason,
    ComplianceState ComplianceStatus,
    ComplianceState BrandStatus);

public static class OfferPolicyResolver
{
    public static OfferPolicy Resolve(Offer offer, Merchant? merchant)
    {
        bool Flag(bool? offerValue, bool merchantValue) => offerValue ?? merchantValue;
        var m = merchant;
        return new OfferPolicy(
            Flag(offer.PpcAllowed, m?.PpcAllowed ?? true),
            Flag(offer.BrandBiddingAllowed, m?.BrandBiddingAllowed ?? false),
            Flag(offer.BrandPlusModifierAllowed, m?.BrandPlusModifierAllowed ?? false),
            Flag(offer.CouponBiddingAllowed, m?.CouponBiddingAllowed ?? false),
            Flag(offer.DirectLinkingAllowed, m?.DirectLinkingAllowed ?? false));
    }

    public static ComplianceState WorstState(IEnumerable<ComplianceRestriction> restrictions)
    {
        var list = restrictions.ToList();
        if (list.Count == 0)
            return ComplianceState.Unknown;
        if (list.Any(x => x.State == ComplianceState.Prohibited))
            return ComplianceState.Prohibited;
        if (list.Any(x => x.State == ComplianceState.Restricted))
            return ComplianceState.Restricted;
        if (list.Any(x => x.State == ComplianceState.Researching))
            return ComplianceState.Researching;
        if (list.All(x => x.State == ComplianceState.Verified))
            return ComplianceState.Verified;
        return ComplianceState.Unknown;
    }

    public static ComplianceState KindState(IEnumerable<ComplianceRestriction> restrictions, RestrictionKind kind)
    {
        var match = restrictions.Where(x => x.Kind == kind).OrderByDescending(x => x.CheckedAtUtc).FirstOrDefault();
        return match?.State ?? ComplianceState.Unknown;
    }
}

public static class KeywordComplianceGate
{
    public static bool IsBrandTemplate(KeywordTemplateKind template) => template is
        KeywordTemplateKind.Product or KeywordTemplateKind.Review or KeywordTemplateKind.Price
        or KeywordTemplateKind.Buy or KeywordTemplateKind.Official;

    public static bool IsCouponTemplate(KeywordTemplateKind template) => template is
        KeywordTemplateKind.Coupon or KeywordTemplateKind.Discount;

    public static KeywordComplianceResult Evaluate(KeywordTemplateKind template, OfferPolicy policy, ComplianceState overall)
    {
        if (!policy.PpcAllowed)
            return Block("PPC is not permitted for this offer.", overall, ComplianceState.Prohibited);

        if (IsCouponTemplate(template) && !policy.CouponBiddingAllowed)
            return Block("Coupon / discount bidding is prohibited by affiliate terms.", overall, ComplianceState.Prohibited);

        if (template == KeywordTemplateKind.Official)
        {
            if (!policy.BrandPlusModifierAllowed && !policy.BrandBiddingAllowed)
                return Block("Official / navigational brand terms are prohibited.", overall, ComplianceState.Prohibited);
        }
        else if (IsBrandTemplate(template) && !policy.BrandBiddingAllowed)
            return Block("Brand bidding is prohibited by affiliate terms.", overall, ComplianceState.Prohibited);

        var brandStatus = IsBrandTemplate(template)
            ? (policy.BrandBiddingAllowed ? ComplianceState.Verified : ComplianceState.Prohibited)
            : ComplianceState.Verified;

        var status = overall == ComplianceState.Unknown ? ComplianceState.Unknown : overall;
        if (overall == ComplianceState.Prohibited)
            return Block("Offer-level traffic is prohibited.", overall, brandStatus);

        return new KeywordComplianceResult(false, null, status, brandStatus);
    }

    private static KeywordComplianceResult Block(string reason, ComplianceState overall, ComplianceState brand) =>
        new(true, reason, ComplianceState.Prohibited, brand);
}

public static class LandingPageCompleteness
{
    public static bool CanPublish(LandingPage page, out string? reason)
    {
        if (string.IsNullOrWhiteSpace(page.Disclosure))
        {
            reason = "Affiliate disclosure is required before publish.";
            return false;
        }

        if (string.IsNullOrWhiteSpace(page.Body))
        {
            reason = "Original evaluation content is required.";
            return false;
        }

        var hasUtility = HasText(page.WhoItsFor)
                         || HasText(page.WhoItsNotFor)
                         || HasText(page.ComparisonJson)
                         || HasText(page.Alternatives)
                         || HasJsonList(page.ProsJson)
                         || HasJsonList(page.ConsJson)
                         || HasJsonList(page.BenefitsJson)
                         || HasJsonList(page.FaqJson);

        if (!hasUtility)
        {
            reason = "Add at least one non-CTA section (pros/cons, who it's for, comparison, alternatives, benefits, or FAQ).";
            return false;
        }

        reason = null;
        return true;
    }

    private static bool HasText(string? value) => !string.IsNullOrWhiteSpace(value);

    private static bool HasJsonList(string? json) =>
        !string.IsNullOrWhiteSpace(json) && json.Trim() is not "[]" and not "null";
}
