namespace BottomFunnel.Contracts.Auth;

public sealed record LoginRequest(string Email, string Password);
public sealed record AuthResponse(string Token, string Email, Guid OrganizationId, string OrganizationName);
public sealed record MeResponse(string UserId, string Email, Guid OrganizationId, string OrganizationName, string Role);
