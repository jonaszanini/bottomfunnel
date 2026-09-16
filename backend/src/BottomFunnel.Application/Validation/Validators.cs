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

public sealed class CreateUnifiedOfferRequestValidator : AbstractValidator<CreateUnifiedOfferRequest>
{
    public CreateUnifiedOfferRequestValidator()
    {
        RuleFor(x => x.ProductName).NotEmpty().MaximumLength(200);
        RuleFor(x => x.MerchantName).NotEmpty().MaximumLength(200);
        RuleFor(x => x.PayoutAmount).GreaterThanOrEqualTo(0);
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

public sealed class SaveAiProviderRequestValidator : AbstractValidator<SaveAiProviderRequest>
{
    public SaveAiProviderRequestValidator()
    {
        RuleFor(x => x.Provider).NotEmpty();
        RuleFor(x => x.DefaultModel).NotEmpty().MaximumLength(120);
        RuleFor(x => x.ApiKey).MaximumLength(4000);
    }
}

public sealed class TestAiProviderRequestValidator : AbstractValidator<TestAiProviderRequest>
{
    public TestAiProviderRequestValidator()
    {
        RuleFor(x => x.Provider).NotEmpty();
    }
}
