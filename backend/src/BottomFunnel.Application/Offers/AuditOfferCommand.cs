namespace BottomFunnel.Application.Offers;

public sealed record AuditOfferCommand(Guid OfferId);

public sealed record AddAuditNegativesCommand(Guid OfferId, IReadOnlyList<string> Phrases);

public sealed record AddAuditNegativesRequest(IReadOnlyList<string> Phrases);
