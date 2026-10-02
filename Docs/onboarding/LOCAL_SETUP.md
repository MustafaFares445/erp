# Local Setup

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: composer.json, package.json, .env.example, current Laravel configuration
---

## Requirements

- PHP 8.4+
- Composer 2
- Node.js + npm
- MySQL for production-like/concurrency testing; normal automated tests default to in-memory SQLite
- PHP extensions required by the installed Laravel/packages and the selected database driver

## Fast Setup

From the repository root:

```bash
composer run setup
```

The current Composer setup script:

1. installs Composer dependencies;
2. creates `.env` from `.env.example` if missing;
3. generates `APP_KEY`;
4. runs migrations;
5. installs npm dependencies;
6. builds frontend assets.

It does not seed data.

## Manual Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Configure the database before migration when not using the default local configuration.

## Development Process

The repository provides:

```bash
composer run dev
```

It runs the Laravel server, queue listener, Laravel Pail and Vite through `concurrently`.

If your local environment already serves Laravel through Laragon/Herd/a web server, use that environment and run only the queue/Vite processes you need rather than starting duplicate servers.

## Test Environment

`phpunit.xml` currently uses:

- `APP_ENV=testing`;
- in-memory SQLite;
- array cache/session/mail;
- synchronous queues;
- fake Employee transcription driver;
- disabled Pulse/Telescope/Nightwatch.

MySQL-specific warehouse/concurrency acceptance is run separately in CI.

## Documentation

After setup, start at [Docs/README.md](../README.md) and the domain you intend to change.
