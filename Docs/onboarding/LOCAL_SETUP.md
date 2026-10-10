# Local Setup

---
status: canonical
owner: engineering
last_verified: 2026-10-07
verified_against: composer.json, package.json, user-guide/package.json, .env.example, current Laravel configuration
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
5. installs root npm dependencies;
6. builds Laravel frontend assets;
7. installs the isolated `user-guide/` dependencies with its lockfile;
8. builds the standalone VitePress guide to `public/guide`.

It does not seed data.

## Manual Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
npm ci --prefix user-guide
npm --prefix user-guide run docs:build
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

## Standalone User Guide

The user-facing guide has its own Node package and does not run inside Filament.

After changing module navigation, Filament Resources or their registered pages, regenerate the guide inventory/content:

```bash
npm --prefix user-guide run docs:generate
```

Then run the development site:

```bash
cd user-guide
npm install
npm run docs:dev
```

The development server uses port `5174`. A production build writes static output to `public/guide` and is served at `/guide/` by default:

```bash
npm run docs:build
```

Use `composer test:guide` to validate the registry manifest, EN/AR article parity, registered-page coverage, screenshot placeholders, workflow files and the VitePress build. Set `USER_GUIDE_URL` when the dashboard should link to another documentation host or subdomain.

## Documentation

After setup, start at [Docs/README.md](../README.md) and the domain you intend to change. User-facing operational content lives under `user-guide/`.
