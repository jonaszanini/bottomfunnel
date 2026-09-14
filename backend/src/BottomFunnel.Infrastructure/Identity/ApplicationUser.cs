using Microsoft.AspNetCore.Identity;

namespace BottomFunnel.Infrastructure.Identity;

public class ApplicationUser : IdentityUser
{
    public Guid? DefaultOrganizationId { get; set; }
}
