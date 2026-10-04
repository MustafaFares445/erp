# Configuration Reference

---
status: canonical
owner: engineering
last_verified: 2026-10-04
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

These configure provider service capability. Customer Support can create a Stripe Checkout session for a diagnostic ticket fee when both Stripe and the customer Support API are enabled. A public Stripe webhook endpoint is still not exposed by the current route table; provider reconciliation remains service-driven.

## Support Service-Management Rollout

`config/support.php` reads the following environment switches:

- `SUPPORT_WORKSPACE_V2_ENABLED` — Case Workspace UI; defaults to `true`.
- `SUPPORT_SLA_V2_ENABLED` — milestone/calendar SLA behavior; defaults to `true`.
- `SUPPORT_SMART_ROUTING_ENABLED` — teams/skills/queues/routing execution and configuration surfaces; defaults to `false`.
- `SUPPORT_AUTOMATION_ENABLED` — Support automation rules and stale-event execution; defaults to `false`.
- `SUPPORT_FIELD_SERVICE_ENABLED` — service appointment/dispatch surface; defaults to `true` because on-site maintenance is part of the core project workflow.
- `SUPPORT_KNOWLEDGE_BASE_ENABLED` — Knowledge Base UI and customer knowledge endpoints; defaults to `false`.
- `SUPPORT_CUSTOMER_API_ENABLED` — authenticated customer Support endpoints; defaults to `false`.
- `SUPPORT_EQUIPMENT_INSTALLATION_ENABLED` — installation/commissioning panel and creation; defaults to `true`.
- `SUPPORT_CALIBRATION_ENABLED` — calibration panel, creation, dashboard queue, calibration schedules and calibration due-raising; defaults to `true`. Existing data is retained when disabled.
- `SUPPORT_LOANER_EQUIPMENT_ENABLED` — loaner (temporary replacement) panel, queue and overdue notifications; defaults to `false`. Existing data is retained when disabled.
- `SUPPORT_EXTERNAL_REPAIR_ENABLED` — supplier repair (RMA) panel, queue and notifications; defaults to `false`. Existing data is retained when disabled.
- `SUPPORT_CSAT_ENABLED` — post-close customer satisfaction submission/request; defaults to `false`.

Additional Customer Support API settings:

- `SANCTUM_TOKEN_EXPIRATION_MINUTES` — lifetime of customer API tokens; defaults to 43200 (30 days).
- `SUPPORT_CUSTOMER_API_REDIRECT_HOSTS` — comma-separated hosts (subdomains included) allowed as diagnostic-payment success/cancel redirect targets, in addition to the application host. Leave empty to allow only the application host.

Disabled staged features are blocked at the resource/HTTP boundary, not only hidden from navigation. `SUPPORT_SLA_V2_ENABLED` also gates the SLA calendar, service-level and entitlement configuration resources; the Knowledge Base flag gates the Knowledge Base workspace (articles and categories).

## Frontend

- `VITE_APP_NAME`

## Configuration Safety

- Never commit credentials.
- Do not treat client-supplied prices/payment totals as configuration or truth.
- Cache configuration in deployment only after server environment values are correct.
- Shared business limits/currencies that are admin-managed belong to the Settings domain, not environment variables.

See [Settings Domain](../domains/settings/README.md).
