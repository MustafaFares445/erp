# Configuration Reference

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: .env.example, config/*.php and current service configuration
---

## Source of Truth

- Environment key template: `.env.example`
- Laravel configuration: `config/`
- Secrets: deployment environment/secret store, never source control

This document groups important keys; it does not duplicate every Laravel default.

## Application

- `APP_NAME`
- `APP_ENV`
- `APP_KEY`
- `APP_DEBUG`
- `APP_URL`
- locale/fallback/faker locale
- maintenance driver

## Database

Primary:
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Warehouse concurrency acceptance uses a second configured database:
- `WAREHOUSE_CONCURRENCY_DB_HOST`
- `WAREHOUSE_CONCURRENCY_DB_PORT`
- `WAREHOUSE_CONCURRENCY_DB_DATABASE`
- `WAREHOUSE_CONCURRENCY_DB_USERNAME`
- `WAREHOUSE_CONCURRENCY_DB_PASSWORD`

## Queue / Cache / Session

- `QUEUE_CONNECTION` â€” configuration default is `database`.
- `CACHE_STORE`
- `SESSION_DRIVER` and session keys.
- Redis keys are available when Redis-backed drivers are selected.

## Filesystem

- `FILESYSTEM_DISK` â€” configuration default is `local`.
- AWS/S3 keys are available through Laravel filesystem configuration.

Use authorized/private media paths for sensitive business files.

## Mail

Standard Laravel mail keys are defined in `.env.example`.

Provider-specific keys may also be read from `config/services.php` (for example Postmark/Resend/SES) if that provider is selected.

## Routing / Maps

`OSRM_URL` configures the OSRM routing service and defaults in `config/services.php` to the public router endpoint.

Do not infer a different map/geocoding provider from removed historical docs.

## Employee Voice Transcription

- `OPENAI_API_KEY`
- `OPENAI_TRANSCRIBE_MODEL` (default `whisper-1`)
- `OPENAI_TRANSCRIBE_BASE_URL`
- `OPENAI_TRANSCRIBE_TIMEOUT`
- `EMPLOYEES_TRANSCRIBE_DRIVER` (current supported drivers include `openai` and `fake`)
- `EMPLOYEES_TRANSCRIBE_MAX_BYTES`
- `EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES`

Tests force the fake transcription driver.

## Stripe Service Configuration

`config/services.php` reads:

- `STRIPE_ENABLED`
- `STRIPE_SECRET_KEY`
- `STRIPE_PUBLISHABLE_KEY`
- `STRIPE_WEBHOOK_SECRET`

These configure provider service capability. They do **not** imply a public Stripe webhook/API route currently exists.

## Frontend

- `VITE_APP_NAME`

## Configuration Safety

- Never commit credentials.
- Do not treat client-supplied prices/payment totals as configuration or truth.
- Cache configuration in deployment only after server environment values are correct.
- Shared business limits/currencies that are admin-managed belong to the Settings domain, not environment variables.

See [Settings Domain](../domains/settings/README.md).
