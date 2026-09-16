using System.Text.Json;
using System.Text.Json.Serialization;
using BottomFunnel.Application.Offers.Models;

namespace BottomFunnel.Application.Offers;

public static class OfferPolicyAuditParser
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        Converters = { new JsonStringEnumConverter(JsonNamingPolicy.CamelCase, allowIntegerValues: true) }
    };

    public static OfferPolicyAuditResult Parse(string raw)
    {
        var json = Unwrap(raw);
        var dto = JsonSerializer.Deserialize<AuditJson>(json, JsonOptions)
                  ?? throw new InvalidOperationException("AI returned empty policy audit JSON.");

        var score = Math.Clamp(dto.RiskScore, 0, 100);
        var risk = ParseRisk(dto.OverallRisk, score);
        return new OfferPolicyAuditResult(
            risk,
            score,
            dto.AllowsBrandBidding,
            Distinct(dto.MandatoryNegativeKeywords),
            (dto.Violations ?? []).Select(v => new PolicyViolation(
                v.PolicyCategory ?? "Unspecified",
                v.TriggerPhrase ?? "",
                v.Explanation ?? "",
                v.Severity ?? risk.ToString())).ToList(),
            Distinct(dto.ApprovedAngles),
            Distinct(dto.DisallowedAngles),
            string.IsNullOrWhiteSpace(dto.ExecutiveSummary)
                ? "Policy audit completed. Review violations before buying traffic."
                : dto.ExecutiveSummary.Trim());
    }

    private static string Unwrap(string raw)
    {
        var text = (raw ?? "").Trim();
        if (text.StartsWith("```", StringComparison.Ordinal))
        {
            var start = text.IndexOf('\n');
            var end = text.LastIndexOf("```", StringComparison.Ordinal);
            if (start >= 0 && end > start)
                text = text[(start + 1)..end].Trim();
        }
        var objStart = text.IndexOf('{');
        var objEnd = text.LastIndexOf('}');
        if (objStart >= 0 && objEnd > objStart)
            text = text[objStart..(objEnd + 1)];
        return text;
    }

    private static PolicyRiskLevel ParseRisk(string? value, int score)
    {
        if (Enum.TryParse<PolicyRiskLevel>(value, ignoreCase: true, out var parsed))
            return parsed;
        return score switch
        {
            < 30 => PolicyRiskLevel.Low,
            < 60 => PolicyRiskLevel.Moderate,
            < 85 => PolicyRiskLevel.Severe,
            _ => PolicyRiskLevel.Critical
        };
    }

    private static List<string> Distinct(IEnumerable<string>? values) =>
        (values ?? [])
            .Select(x => x.Trim())
            .Where(x => x.Length > 0)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

    private sealed class AuditJson
    {
        public string? OverallRisk { get; set; }
        public int RiskScore { get; set; }
        public bool AllowsBrandBidding { get; set; }
        public List<string>? MandatoryNegativeKeywords { get; set; }
        public List<ViolationJson>? Violations { get; set; }
        public List<string>? ApprovedAngles { get; set; }
        public List<string>? DisallowedAngles { get; set; }
        public string? ExecutiveSummary { get; set; }
    }

    private sealed class ViolationJson
    {
        public string? PolicyCategory { get; set; }
        public string? TriggerPhrase { get; set; }
        public string? Explanation { get; set; }
        public string? Severity { get; set; }
    }
}
