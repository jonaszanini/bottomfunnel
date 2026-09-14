using System.Text.Json;
using BottomFunnel.Application.Stores;
using Microsoft.AspNetCore.DataProtection;

namespace BottomFunnel.Infrastructure.Security;

public sealed class CredentialProtector : ICredentialProtector
{
    private readonly IDataProtector _protector;

    public CredentialProtector(IDataProtectionProvider provider)
    {
        _protector = provider.CreateProtector("BottomFunnel.StoreCredentials.v1");
    }

    public string Protect(IReadOnlyDictionary<string, string> credentials)
    {
        var json = JsonSerializer.Serialize(credentials);
        return _protector.Protect(json);
    }

    public Dictionary<string, string> Unprotect(string protectedPayload)
    {
        var json = _protector.Unprotect(protectedPayload);
        return JsonSerializer.Deserialize<Dictionary<string, string>>(json)
               ?? new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    }
}
