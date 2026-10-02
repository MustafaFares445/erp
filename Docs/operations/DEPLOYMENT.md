# Deployment

---
status: canonical
owner: operations
last_verified: 2026-10-02
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

## Rollback

The workflow deploys an exact Git SHA, but there is no separate automated rollback workflow documented in the repository.

Rollback procedure must account for database migrations; a Git reset alone is not sufficient when a release contains irreversible schema/data changes.

## Production

The checked-in workflow is named **Deploy dev** and targets the `dev` environment.

Do not assume the same workflow is the production deployment contract unless a production workflow/environment is added.
