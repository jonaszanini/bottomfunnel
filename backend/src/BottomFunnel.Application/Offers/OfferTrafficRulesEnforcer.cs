using System.Text.RegularExpressions;
using BottomFunnel.Application.Offers.Models;

namespace BottomFunnel.Application.Offers;

public static class OfferTrafficRulesEnforcer
{
    public const string BrandPpcBannedSummary =
        "Brand-name PPC is banned by the seller. Do not bid on the product or trademark terms, and add the extracted negatives before any campaign goes live.";

    private static readonly Regex Quoted = new("\"([^\"]{2,80})\"|'([^']{2,80})'", RegexOptions.Compiled);
    private static readonly Regex NegativeSection = new(
        @"negative\s+keywords?\s*[:\-]\s*(.+)",
        RegexOptions.IgnoreCase | RegexOptions.Compiled | RegexOptions.Singleline);
    private static readonly Regex BrandBidMention = new(
        @"\b(?:brand[-\s]?bid(?:ding)?|trademark[-\s]?bid(?:ding)?|bid(?:ding)?\s+on\s+(?:the\s+)?(?:brand|product|trademark|company)(?:\s+name)?|ppc\s+on\s+(?:the\s+)?brand|brand[-\s]?name\s+(?:ppc|keywords?|terms?))\b",
        RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex Forbid = new(
        @"\b(?:do\s+not|don't|must\s+not|may\s+not|cannot|can't|not\s+allowed|prohibited|forbidden|banned|never\s+bid|no\s+bid(?:ding)?)\b",
        RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly HashSet<string> Stop = new(StringComparer.OrdinalIgnoreCase)
    {
        "the", "and", "or", "a", "an", "to", "of", "for", "on", "in", "you", "your", "must", "add",
        "these", "this", "that", "with", "from", "not", "no", "yes", "all", "any", "such", "including"
    };

    public static bool BansBrandBidding(string? trafficRestrictions)
    {
        if (string.IsNullOrWhiteSpace(trafficRestrictions))
            return false;
        return BrandBidMention.IsMatch(trafficRestrictions) && Forbid.IsMatch(trafficRestrictions);
    }

    public static OfferPolicyAuditResult Apply(OfferPolicyAuditResult result, string? trafficRestrictions, string? productName)
    {
        if (string.IsNullOrWhiteSpace(trafficRestrictions))
            return result;

        var negatives = Merge(result.MandatoryNegativeKeywords, ExtractNegatives(trafficRestrictions, productName));
        var allowsBrand = result.AllowsBrandBidding;
        var summary = result.ExecutiveSummary;
        var disallowed = result.DisallowedAngles ?? [];

        if (BansBrandBidding(trafficRestrictions))
        {
            allowsBrand = false;
            if (!string.IsNullOrWhiteSpace(productName) &&
                negatives.All(x => !x.Equals(productName.Trim(), StringComparison.OrdinalIgnoreCase)))
                negatives.Insert(0, productName.Trim());
            if (disallowed.All(x => !x.Contains("brand-name PPC", StringComparison.OrdinalIgnoreCase)))
                disallowed.Add("Brand-name PPC / trademark bidding");
            if (string.IsNullOrWhiteSpace(summary) ||
                !summary.Contains("brand-name PPC is banned", StringComparison.OrdinalIgnoreCase))
                summary = string.IsNullOrWhiteSpace(summary)
                    ? BrandPpcBannedSummary
                    : $"{BrandPpcBannedSummary} {summary.Trim()}";
        }

        return result with
        {
            AllowsBrandBidding = allowsBrand,
            MandatoryNegativeKeywords = negatives,
            DisallowedAngles = disallowed,
            ExecutiveSummary = summary
        };
    }

    public static List<string> ExtractNegatives(string trafficRestrictions, string? productName)
    {
        var found = new List<string>();
        var section = NegativeSection.Match(trafficRestrictions);
        if (section.Success)
        {
            var chunk = section.Groups[1].Value;
            chunk = Regex.Split(chunk, @"\n\s*rule\s+\d+", RegexOptions.IgnoreCase)[0];
            var cut = chunk.IndexOf("\n\n", StringComparison.Ordinal);
            if (cut >= 0)
                chunk = chunk[..cut];
            foreach (var part in Regex.Split(chunk, @"[,;•\n]|(?:\band\b)", RegexOptions.IgnoreCase))
                Add(found, part);
        }

        foreach (Match match in Quoted.Matches(trafficRestrictions))
        {
            var value = match.Groups[1].Success ? match.Groups[1].Value : match.Groups[2].Value;
            Add(found, value);
        }

        return found
            .Select(x => x.Trim())
            .Where(x => x.Length is >= 2 and <= 80 && !Stop.Contains(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static List<string> Merge(IEnumerable<string>? existing, IEnumerable<string> extra)
    {
        var list = new List<string>();
        foreach (var item in (existing ?? []).Concat(extra))
            Add(list, item);
        return list;
    }

    private static void Add(List<string> list, string? value)
    {
        var text = (value ?? "").Trim().Trim('"', '\'', '.', '—', '-', ':');
        if (text.Length is < 2 or > 80 || Stop.Contains(text))
            return;
        if (list.Any(x => x.Equals(text, StringComparison.OrdinalIgnoreCase)))
            return;
        list.Add(text);
    }
}
