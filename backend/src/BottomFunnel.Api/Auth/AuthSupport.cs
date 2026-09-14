using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Contracts.Auth;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Infrastructure.Identity;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.IdentityModel.Tokens;

namespace BottomFunnel.Api.Auth;

public sealed class HttpCurrentUser : ICurrentUser
{
    private readonly IHttpContextAccessor _http;

    public HttpCurrentUser(IHttpContextAccessor http)
    {
        _http = http;
    }

    public CurrentUser User
    {
        get
        {
            if (AmbientOrganization.Current is { } ambient)
                return new CurrentUser("worker", "worker@local", ambient, "Owner");

            var principal = _http.HttpContext?.User ?? throw new InvalidOperationException("Not authenticated.");
            var userId = principal.FindFirstValue(ClaimTypes.NameIdentifier) ?? principal.FindFirstValue(JwtRegisteredClaimNames.Sub);
            var email = principal.FindFirstValue(ClaimTypes.Email) ?? principal.FindFirstValue(JwtRegisteredClaimNames.Email) ?? "";
            var org = principal.FindFirstValue("org") ?? throw new InvalidOperationException("Missing organization claim.");
            var role = principal.FindFirstValue(ClaimTypes.Role) ?? "Owner";
            return new CurrentUser(userId ?? "", email, Guid.Parse(org), role);
        }
    }

    public Guid OrganizationId => User.OrganizationId;
}

public sealed class TokenService
{
    private readonly IConfiguration _configuration;

    public TokenService(IConfiguration configuration)
    {
        _configuration = configuration;
    }

    public string Create(ApplicationUser user, Guid organizationId, string role)
    {
        var key = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(SigningKey(_configuration)));
        var claims = new[]
        {
            new Claim(JwtRegisteredClaimNames.Sub, user.Id),
            new Claim(ClaimTypes.NameIdentifier, user.Id),
            new Claim(JwtRegisteredClaimNames.Email, user.Email ?? ""),
            new Claim(ClaimTypes.Email, user.Email ?? ""),
            new Claim("org", organizationId.ToString()),
            new Claim(ClaimTypes.Role, role)
        };
        var token = new JwtSecurityToken(
            issuer: _configuration["Jwt:Issuer"] ?? "bottomfunnel",
            audience: _configuration["Jwt:Audience"] ?? "bottomfunnel",
            claims: claims,
            expires: DateTime.UtcNow.AddDays(7),
            signingCredentials: new SigningCredentials(key, SecurityAlgorithms.HmacSha256));
        return new JwtSecurityTokenHandler().WriteToken(token);
    }

    public static string SigningKey(IConfiguration configuration) =>
        configuration["Jwt:Key"] ?? "dev-only-change-me-please-use-32chars!!";
}

public sealed class AuthService
{
    private readonly UserManager<ApplicationUser> _users;
    private readonly IAppDbContext _db;
    private readonly TokenService _tokens;

    public AuthService(UserManager<ApplicationUser> users, IAppDbContext db, TokenService tokens)
    {
        _users = users;
        _db = db;
        _tokens = tokens;
    }

    public async Task<AuthResponse> LoginAsync(LoginRequest request, CancellationToken ct)
    {
        var user = await _users.FindByEmailAsync(request.Email)
                   ?? throw new UnauthorizedAccessException("Invalid credentials.");
        if (!await _users.CheckPasswordAsync(user, request.Password))
            throw new UnauthorizedAccessException("Invalid credentials.");

        var membership = await _db.OrganizationMembers
                             .Include(x => x.Organization)
                             .FirstOrDefaultAsync(x => x.UserId == user.Id, ct)
                         ?? throw new UnauthorizedAccessException("User has no organization.");

        var token = _tokens.Create(user, membership.OrganizationId, membership.Role.ToString());
        return new AuthResponse(token, user.Email ?? "", membership.OrganizationId, membership.Organization?.Name ?? "");
    }

    public async Task<MeResponse> MeAsync(CurrentUser current, CancellationToken ct)
    {
        var org = await _db.Organizations.FirstAsync(x => x.Id == current.OrganizationId, ct);
        return new MeResponse(current.UserId, current.Email, current.OrganizationId, org.Name, current.Role);
    }
}

public static class DataSeeder
{
    public static async Task SeedAsync(IServiceProvider services)
    {
        using var scope = services.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<Infrastructure.Persistence.AppDbContext>();
        await db.Database.MigrateAsync();

        var users = scope.ServiceProvider.GetRequiredService<UserManager<ApplicationUser>>();
        if (await users.FindByEmailAsync("admin@local") is not null)
            return;

        var org = new Organization { Name = "Internal" };
        db.Organizations.Add(org);

        var user = new ApplicationUser
        {
            UserName = "admin@local",
            Email = "admin@local",
            EmailConfirmed = true,
            DefaultOrganizationId = org.Id
        };
        var result = await users.CreateAsync(user, "ChangeMe123!");
        if (!result.Succeeded)
            throw new InvalidOperationException(string.Join("; ", result.Errors.Select(x => x.Description)));

        db.OrganizationMembers.Add(new OrganizationMember
        {
            OrganizationId = org.Id,
            UserId = user.Id,
            Role = OrganizationRole.Owner
        });
        await db.SaveChangesAsync();
    }
}
