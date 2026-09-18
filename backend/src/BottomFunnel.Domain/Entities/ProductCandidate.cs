using BottomFunnel.Domain.Enums;

namespace BottomFunnel.Domain.Entities;

public class ProductCandidate
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string ProviderKey { get; set; } = "clickbank";
    public string ExternalId { get; set; } = string.Empty;
    public string? Vendor { get; set; }
    public string Title { get; set; } = string.Empty;
    public string Description { get; set; } = string.Empty;
    public decimal Gravity { get; set; }
    public string? Category { get; set; }
    public string? AffiliatePageUrl { get; set; }
    public CandidateStatus Status { get; set; } = CandidateStatus.PendingReview;
    public string? DisqualificationReason { get; set; }
    public DateTimeOffset DiscoveredAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? UpdatedAt { get; set; }
}
