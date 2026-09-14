namespace BottomFunnel.Application.Abstractions;

public static class AmbientOrganization
{
    private static readonly AsyncLocal<Guid?> CurrentValue = new();
    public static Guid? Current
    {
        get => CurrentValue.Value;
        set => CurrentValue.Value = value;
    }
}
