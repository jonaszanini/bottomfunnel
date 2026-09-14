using BottomFunnel.Application.Dtos;
using FluentValidation;

namespace BottomFunnel.Application.Validation;

public sealed class UpsertOfferRequestValidator : AbstractValidator<UpsertOfferRequest>
{
    public UpsertOfferRequestValidator()
    {
        RuleFor(x => x.Name).NotEmpty().MaximumLength(200);
        RuleFor(x => x.ProductId).NotEmpty();
        RuleFor(x => x.Commission).GreaterThanOrEqualTo(0);
        RuleFor(x => x.Currency).NotEmpty().Length(3);
    }
}

public sealed class UpsertProductRequestValidator : AbstractValidator<UpsertProductRequest>
{
    public UpsertProductRequestValidator()
    {
        RuleFor(x => x.Name).NotEmpty().MaximumLength(200);
    }
}

public sealed class SaveStoreConnectionRequestValidator : AbstractValidator<SaveStoreConnectionRequest>
{
    public SaveStoreConnectionRequestValidator()
    {
        RuleFor(x => x.ProviderKey).NotEmpty();
        RuleFor(x => x.Credentials).NotNull();
    }
}
