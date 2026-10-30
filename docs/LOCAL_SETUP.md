# Windows local backend

Installed in `C:\wamp64\www\shopbonus\shopbonus_backend` on 2026-10-27.
The launcher uses Wamp PHP 8.4.15 with SOAP; the default system PHP lacks SOAP.

From the backend directory, start the HTTP server:

```powershell
.\start-local.ps1
```

API: http://localhost:8000/api/v1/health
Readiness: http://localhost:8000/api/v1/health/ready
API documentation: http://localhost:8000/docs

Run these in separate terminals when background processing is needed:

```powershell
.\start-local.ps1 queue:work --sleep=3 --tries=3 --timeout=60
.\start-local.ps1 schedule:work
```

Other Artisan commands can use the same launcher, for example:

```powershell
.\start-local.ps1 migrate:status
```

Local `.env` uses MySQL on `127.0.0.1:3306`, database `shopbonus`,
database-backed cache/queue/session storage, and the log mailer.
The MySQL connection explicitly uses InnoDB because this Wamp instance defaults
to MyISAM. No global MySQL settings were changed.
It is ignored by Git. Application keys and mail codes must stay private.
There is no Redis requirement for this configuration.

Live providers remain disabled or unconfigured. No demo user or administrator
is seeded. Registration requires published legal versions; installation does
not invent or publish legal documents. Reverb/live mail/provider verification
and connecting the frontend are separate work.

The installed development server binds only to the local machine. This is a
local development installation, not a production deployment.

## Verification on 2026-10-27

- Composer installed all 150 locked packages; platform requirements passed.
- All 46 migrations applied; all 122 tables use InnoDB.
- HTTP health/readiness/docs/public catalog returned 200; unauthenticated `/api/v1/me` returned 401.
- API and identity tests: 86 passed, 4,063 assertions (SQLite in memory).
- An empty database queue was checked with `queue:work --once`; exit 0.
- Launcher `migrate:status` succeeded. Full regression suite and live providers were not run.

The development server was started during installation. Queue workers and the
scheduler are not persistent services; start them in separate terminals as needed.
