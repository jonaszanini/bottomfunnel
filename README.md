# Bottom Funnel

Internal platform for finding, scoring, launching, and tracking bottom-of-funnel affiliate campaigns.

## Stack

- `frontend/` — Next.js + React + TypeScript + Tailwind
- `backend/` — .NET 10 ASP.NET Core modular monolith
- PostgreSQL via Docker Compose (database only)

## Prerequisites

- .NET 10 SDK
- Node.js 20+
- Docker Desktop (for PostgreSQL)

## Run locally

```bash
docker compose up -d
cd backend
dotnet ef database update --project src/BottomFunnel.Infrastructure --startup-project src/BottomFunnel.Api
dotnet run --project src/BottomFunnel.Api
```

In another terminal:

```bash
cd frontend
npm install
npm run dev
```

- App: http://localhost:3000
- API: http://localhost:5080
- Seed login: `admin@local` / `ChangeMe123!`

The API applies migrations on startup, so the `dotnet ef` step is optional after the first run if PostgreSQL is already up.

Reset the database:

```bash
docker compose down -v
docker compose up -d
```

## ClickBank and Digistore24

Connect accounts under **Networks** (Affiliate ID, API key, and IPN / webhook passphrase). Credentials are encrypted at rest. Sync the Digistore24 marketplace from **Offers → Discovery Inbox**; that writes third-party candidates into staging, not live offers. Hop/affiliate URLs include the click id (`tid` / `sid`).

Webhooks:

- `POST http://localhost:5080/webhooks/clickbank`
- `POST http://localhost:5080/webhooks/digistore24`
- Generic postback: `GET/POST http://localhost:5080/postback?clickId={guid}&commission=40`

Tracking redirect: `GET http://localhost:5080/t/{trackingCode}`

## Adding another store

1. Implement `IStoreConnector` in `backend/src/BottomFunnel.Infrastructure/Stores/{Provider}/`
2. Register it with `services.AddSingleton<IStoreConnector, FooConnector>()`
3. The Networks form renders `CredentialSchema` automatically

## AI

Set `Ai:ApiKey` in `backend/src/BottomFunnel.Api/appsettings.Development.json` or the `Ai__ApiKey` environment variable. Without a key, analysis falls back to a deterministic summary.
