using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Dtos;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Application.Services;

public class ComplianceService
{
    private readonly IAppDbContext _db;
    private readonly ICurrentUser _current;

    public ComplianceService(IAppDbContext db, ICurrentUser current)
    {
        _db = db;
        _current = current;
    }

    private Guid OrgId => _current.OrganizationId;

    public async Task<ComplianceRestrictionDto> UpsertMerchantRestrictionAsync(Guid merchantId, UpsertRestrictionRequest request, CancellationToken ct)
    {
        var merchant = await _db.Merchants.FirstOrDefaultAsync(x => x.Id == merchantId && x.OrganizationId == OrgId, ct);
        if (merchant is null)
            throw new KeyNotFoundException("Merchant not found.");
        return await UpsertAsync(merchantId, null, request, ct);
    }

    public async Task<ComplianceRestrictionDto> UpsertOfferRestrictionAsync(Guid offerId, UpsertRestrictionRequest request, CancellationToken ct)
    {
        var offer = await _db.Offers.FirstOrDefaultAsync(x => x.Id == offerId && x.OrganizationId == OrgId, ct);
        if (offer is null)
            throw new KeyNotFoundException("Offer not found.");
        return await UpsertAsync(null, offerId, request, ct);
    }

    public async Task DeleteRestrictionAsync(Guid id, CancellationToken ct)
    {
        var entity = await _db.ComplianceRestrictions.FirstOrDefaultAsync(x => x.Id == id && x.OrganizationId == OrgId, ct)
                     ?? throw new KeyNotFoundException("Restriction not found.");
        _db.ComplianceRestrictions.Remove(entity);
        await _db.SaveChangesAsync(ct);
    }

    private async Task<ComplianceRestrictionDto> UpsertAsync(Guid? merchantId, Guid? offerId, UpsertRestrictionRequest request, CancellationToken ct)
    {
        var existing = await _db.ComplianceRestrictions.FirstOrDefaultAsync(x =>
            x.OrganizationId == OrgId
            && x.Kind == request.Kind
            && x.MerchantId == merchantId
            && x.OfferId == offerId, ct);

        if (existing is null)
        {
            existing = new ComplianceRestriction
            {
                OrganizationId = OrgId,
                MerchantId = merchantId,
                OfferId = offerId,
                Kind = request.Kind
            };
            _db.ComplianceRestrictions.Add(existing);
        }

        existing.State = request.State;
        existing.Source = request.Source;
        existing.Evidence = request.Evidence;
        existing.CheckedAtUtc = DateTimeOffset.UtcNow;
        existing.NextReviewAtUtc = request.NextReviewAtUtc;
        existing.Confidence = request.Confidence;
        existing.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
        return ComplianceMapper.ToDto(existing);
    }
}
