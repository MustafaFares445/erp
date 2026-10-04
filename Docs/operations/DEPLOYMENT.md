# Deployment

---
status: canonical
owner: operations
last_verified: 2026-10-04
verified_against: .github/workflows/deploy.yml and scripts/deploy.sh
---

## Current Automated Dev Deployment

Pushes to `dev` and manual workflow dispatch can run `.github/workflows/deploy.yml`.

Deployment is gated by:

1. the full `composer test` quality gate;
2. dedicated MySQL warehouse release/concurrency acceptance.

Only after both succeed does deployment run.

## AWS Path

Current workflow deploys to an EC2 instance through AWS Systems Manager Run Command.

GitHub obtains AWS credentials via OIDC.

Workflow inputs/secrets include:
- repository/environment variable `AWS_REGION`;
- repository/environment variable `APP_DIR`;
- secret `EC2_INSTANCE_ID`;
- secret `AWS_DEPLOY_ROLE_ARN`.

The remote command:
1. enters `APP_DIR`;
2. fetches `origin dev`;
3. hard-resets the server working tree to the exact tested GitHub SHA;
4. runs `bash scripts/deploy.sh`.

## Server Deploy Script

`scripts/deploy.sh` currently:

1. enters Laravel maintenance mode;
2. installs production Composer dependencies with `--no-dev`;
3. runs `php artisan migrate --force`;
4. ensures the storage symlink;
5. caches config/routes/views/events/Filament components;
6. runs `php artisan queue:restart`;
7. always attempts to bring the app back up via shell trap.

The server `.env` is intentionally left untouched.

## Frontend Assets

The current server deploy script does **not** run `npm install` or `npm run build`.

Do not document an automatic server-side asset build unless the workflow/script changes. Any deployment that depends on newly built frontend assets must ensure the required build artifact is already present or add an explicit build step.

## Support Service-Management Rollout

The Support service-management expansion deploys additive schema/permission/template changes before staged capabilities are activated.

Recommended order:

1. deploy migrations/seeded configuration with staged Support switches off;
2. keep Case Workspace and SLA v2 on;
3. configure teams/skills/routing data, then enable Smart Routing;
4. review automation rules, then enable Support Automation;
5. enable Field Service when technician dispatch operations are ready;
6. review customer-visible knowledge content before enabling Knowledge Base;
7. enable the Customer Support API only with a compatible customer application;
8. enable CSAT once the customer channel can present the post-close feedback flow.

Migration notes:

- `2026_10_04_010000_expand_support_sla_v2` creates the schema; the data backfill (default 24/7 calendar, per-policy milestones, ticket milestones) is a separate, re-runnable migration `2026_10_04_010100_backfill_support_sla_v2`, so a failed backfill can be retried without manual cleanup.
- `2026_10_04_080000_add_support_csat_and_lifecycle_metrics` backfills `closed_at` and the last-message timestamps from existing tickets/messages.
- After migrating, run `php artisan db:seed --class=<Seeder>` for each of `SupportPermissionSeeder`, `SupportServiceLevelSeeder`, `SlaPolicySeeder`, `SupportQueueSeeder` and `NotificationTemplateSeeder`. All are idempotent, and `SlaPolicySeeder` adopts the backfilled legacy policies instead of duplicating them.
- `down()` of the SLA v2 and routing migrations is lossy by design: only one policy per priority survives, and automatic assignments are attributed to the earliest user so the legacy NOT NULL contract holds. Take a database backup before rolling back.
- Rebuild frontend assets (`npm run build`) so the Support styles ship with the release.

Turning a feature switch off is a behavioral rollback. It does not remove Support data already written by that feature.

See [Configuration Reference](../reference/CONFIGURATION.md) and [ADR 0015](../adr/0015-support-service-management-expansion.md).

## Rollback

The workflow deploys an exact Git SHA, but there is no separate automated rollback workflow documented in the repository.

Rollback procedure must account for database migrations; a Git reset alone is not sufficient when a release contains irreversible schema/data changes.

## Production

The checked-in workflow is named **Deploy dev** and targets the `dev` environment.

Do not assume the same workflow is the production deployment contract unless a production workflow/environment is added.
