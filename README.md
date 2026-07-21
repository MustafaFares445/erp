# iERP

An ERP application built on Laravel and Filament.

## Requirements

- PHP ^8.3
- Composer
- Node.js and npm
- MySQL (or another DB supported by `DB_CONNECTION`)
- [Laravel Herd](https://herd.laravel.com/) (recommended for local sites, e.g. `https://ierp-new.test`) or `php artisan serve`

## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Update `.env` with your database credentials (default `DB_DATABASE=ierp`), then run migrations and seed the database:

```bash
php artisan migrate --seed
npm install
npm run build
```

Or run the whole setup in one step:

```bash
composer run setup
```

The application entry point (`/`) redirects to the Filament admin panel at `/admin`. Log in with the admin user created by `database/seeders/DatabaseSeeder.php`.

## Local Development

Start the app, queue worker, log tailer, and Vite dev server together:

```bash
composer run dev
```

## Testing & Quality Gates

```bash
composer test          # full CI gate: lint, static analysis, type coverage, unit/feature tests
vendor/bin/pint --dirty        # format only changed files
vendor/bin/phpstan analyse     # static analysis
php artisan test --compact     # run tests, optionally with --filter=
```

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

