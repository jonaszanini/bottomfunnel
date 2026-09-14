using BottomFunnel.Domain.Compliance;
using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Keywords;

public sealed record KeywordCandidate(
    string Phrase,
    KeywordTemplateKind Template,
    SearchIntent Intent,
    int IntentScore,
    bool Blocked = false,
    string? BlockedReason = null,
    ComplianceState ComplianceStatus = ComplianceState.Unknown,
    ComplianceState BrandStatus = ComplianceState.Unknown);

public static class KeywordTemplateCatalog
{
    public static IReadOnlyList<KeywordCandidate> Generate(string productName, OfferPolicy? policy = null, ComplianceState overall = ComplianceState.Unknown)
    {
        var name = productName.Trim();
        var raw = new List<KeywordCandidate>
        {
            new($"{name}", KeywordTemplateKind.Product, SearchIntent.Commercial, 60),
            new($"{name} reviews", KeywordTemplateKind.Review, SearchIntent.Commercial, 75),
            new($"{name} price", KeywordTemplateKind.Price, SearchIntent.Transactional, 90),
            new($"{name} coupon", KeywordTemplateKind.Coupon, SearchIntent.Transactional, 90),
            new($"{name} discount", KeywordTemplateKind.Discount, SearchIntent.Transactional, 90),
            new($"buy {name}", KeywordTemplateKind.Buy, SearchIntent.Transactional, 100),
            new($"{name} official website", KeywordTemplateKind.Official, SearchIntent.Navigational, 85),
            new($"{name} alternative", KeywordTemplateKind.Alternative, SearchIntent.Commercial, 70),
            new($"{name} vs", KeywordTemplateKind.Versus, SearchIntent.Commercial, 70)
        };

        if (policy is null)
            return raw;

        return raw.Select(c =>
        {
            var gate = KeywordComplianceGate.Evaluate(c.Template, policy, overall);
            return c with
            {
                Blocked = gate.Blocked,
                BlockedReason = gate.BlockedReason,
                ComplianceStatus = gate.ComplianceStatus,
                BrandStatus = gate.BrandStatus
            };
        }).ToList();
    }
}

public static class KeywordIntentClassifier
{
    public static (SearchIntent Intent, int Score) Classify(string phrase)
    {
        var text = phrase.Trim().ToLowerInvariant();

        if (ContainsAny(text, "buy", "purchase", "order now", "add to cart"))
            return (SearchIntent.Transactional, 100);
        if (ContainsAny(text, "coupon", "promo code", "discount", "deal", "cheap"))
            return (SearchIntent.Transactional, 90);
        if (ContainsAny(text, "price", "cost", "how much"))
            return (SearchIntent.Transactional, 90);
        if (ContainsAny(text, "official", "website", "login"))
            return (SearchIntent.Navigational, 85);
        if (ContainsAny(text, "review", "reviews", "reddit"))
            return (SearchIntent.Commercial, 75);
        if (ContainsAny(text, "alternative", " vs", "versus", "compare"))
            return (SearchIntent.Commercial, 70);
        if (ContainsAny(text, "what is", "how to", "ingredients", "side effects"))
            return (SearchIntent.Informational, 30);

        return (SearchIntent.Commercial, 60);
    }

    private static bool ContainsAny(string text, params string[] tokens) =>
        tokens.Any(token => text.Contains(token, StringComparison.OrdinalIgnoreCase));
}
