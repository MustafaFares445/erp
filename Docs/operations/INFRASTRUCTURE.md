# Infrastructure

---
status: canonical
owner: operations
last_verified: 2026-10-02
verified_against: deployment workflow, deploy script, Laravel configuration and scheduler
---

## Verified Current Deployment Shape

The repository contains an automated **dev** deployment to an AWS EC2 instance through AWS SSM.

The exact host OS/web-server/process-manager configuration is not defined in this repository and must not be invented in documentation.

## Runtime Requirements

The deployed environment must support:

- PHP 8.4 compatible runtime;
- Composer;
- relational database supported by the configured Laravel connection;
- writable Laravel storage/cache paths;
- a persistent queue worker/process;
- Laravel scheduler invocation;
- configured filesystem/media storage;
- configured mail/provider services used by the environment;
- HTTPS at the public edge where externally accessible.

## Queue

Application default queue connection is `database`.

The dev deploy script restarts workers with `php artisan queue:restart`; therefore the host must already have a process manager or service continuously running queue workers.

## Scheduler

The repository defines recurring tasks in `routes/console.php`.

The host must execute Laravel's scheduler (normally `php artisan schedule:run` every minute). Host cron/systemd configuration is not present in this repo.

## Database

Normal automated tests use SQLite; production-like acceptance uses MySQL 8.4 in CI.

The deployed database engine/host is environment configuration. Do not infer PostgreSQL support or production database selection from removed historical docs.

Transactions, foreign keys and row locking are important to Inventory/Accounting workflows.

## Storage

Laravel filesystem default is configurable and defaults to `local`.

S3 configuration is available through standard Laravel filesystem settings.

Sensitive media requires authorization regardless of physical storage provider.

## External Services

Verified configured integrations include:
- OSRM routing URL;
- OpenAI voice transcription;
- Stripe service configuration;
- Laravel mail/service providers;
- optional Redis-backed Laravel facilities when selected.

Customer/Employee push-provider infrastructure is not established by the current repository audit.

## AWS Deployment Access

GitHub deployment uses AWS OIDC and SSM, avoiding a checked-in SSH private key.

See [Deployment](DEPLOYMENT.md).
