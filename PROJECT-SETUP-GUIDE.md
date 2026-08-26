# Laravel Project Setup Guide

Full reference for reproducing this project's stack, tooling, and
AI-assistant setup in another Laravel project — packages, quality gates,
frontend build, and the Claude Code / Laravel Boost / skills configuration.

## 1. Runtime packages (`require`)

```json
"require": {
    "php": "^8.4",
    "dedoc/scramble": "^0.13.35",
    "filament/filament": "~5.0",
    "laravel/framework": "^13.8",
    "laravel/tinker": "^3.0",
    "spatie/laravel-activitylog": "^5.0",
    "spatie/laravel-data": "^4.23",
    "spatie/laravel-medialibrary": "^11.23",
    "spatie/laravel-permission": "^8.3"
}
```

| Package | Purpose |
|---|---|
| `filament/filament` | Admin panel / resource CRUD framework |
| `dedoc/scramble` | Auto-generated OpenAPI docs from routes/FormRequests |
| `spatie/laravel-permission` | Roles & permissions |
| `spatie/laravel-medialibrary` | File/image attachments on models |
| `spatie/laravel-activitylog` | Audit trail / model change logging |
| `spatie/laravel-data` | Typed DTOs with validation, casting, transformation |

## 2. Dev/test packages (`require-dev`)

```json
"require-dev": {
    "driftingly/rector-laravel": "^2.5",
    "fakerphp/faker": "^1.23",
    "larastan/larastan": "^3.10",
    "laravel/boost": "^2.4",
    "laravel/pail": "^1.2.5",
    "laravel/pao": "^1.0.6",
    "laravel/pint": "^1.27",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.6",
    "pestphp/pest": "^4.7",
    "pestphp/pest-plugin-laravel": "^4.1",
    "pestphp/pest-plugin-type-coverage": "^4.0",
    "phpunit/phpunit": "^12.5.12",
    "roave/security-advisories": "dev-latest",
    "spatie/guidelines-skills": "^1.0"
}
```

| Package | Purpose |
|---|---|
| `laravel/pint` | Code style (PSR-12 + Laravel preset, config in `pint.json`) |
| `driftingly/rector-laravel` | Automated refactoring rules (`rector.php`, dry-run in CI) |
| `larastan/larastan` | PHPStan wrapper with Laravel-aware rules (`phpstan.neon`) |
| `pestphp/pest` + `pest-plugin-laravel` | **Test framework** — all tests are written in Pest syntax (`test()`/`it()`/`expect()`), never raw PHPUnit test classes |
| `pestphp/pest-plugin-type-coverage` | Enforces typed-code coverage % |
| `phpunit/phpunit` | Underlying test runner/assertion engine that Pest is built on top of — configured via `phpunit.xml`, but never targeted directly (`pest`, not `phpunit`, is the CLI you run) |
| `fakerphp/faker` / `mockery/mockery` | Test data + mocking |
| `roave/security-advisories` | Blocks installing packages with known CVEs |
| `laravel/pail` | Tailing app logs in the `composer dev` process group |
| `laravel/pao` | Prompts/autocompletion helper for Artisan commands |
| `laravel/boost` | Laravel MCP server exposing app-aware tools to AI agents (see §5) |
| `spatie/guidelines-skills` | Ships Spatie's Laravel/PHP/JS/security/version-control coding-guideline skills consumed by AI agents (see §6) |

Register required Composer plugins:

```json
"config": {
    "optimize-autoloader": true,
    "preferred-install": "dist",
    "sort-packages": true,
    "allow-plugins": {
        "pestphp/pest-plugin": true,
        "php-http/discovery": true
    }
}
```

## 3. Testing framework: Pest (not PHPUnit)

Tests are written exclusively in **Pest** syntax. PHPUnit is only present
as the engine Pest runs on top of (`phpunit.xml` configures the runner;
`vendor/bin/phpunit`/`php artisan test` still work because Pest is
PHPUnit-compatible, but the project standard is `vendor/bin/pest` /
`php artisan test` with Pest-style test files, not `*Test extends TestCase`
classes with `public function test...()` methods).

### `tests/Pest.php` — global test configuration

```php
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

beforeEach(function (): void {
    // per-test-process isolation for parallel runs (public/local disk roots keyed by ParallelTesting::token())
    // Http::preventStrayRequests(), Process::preventStrayProcesses(), Sleep::fake(), $this->freezeTime()
});

expect()->extend('toBeOne', fn () => $this->toBe(1));
```

Binds every test in `tests/Feature` and `tests/Unit` to `Tests\TestCase`,
registers global `beforeEach()` hooks (safe parallel storage disks, strict
HTTP/process faking, frozen/faked time), and is the place to add custom
`expect()` macros or global helper functions.

### `phpunit.xml` — runner config Pest reads

```xml
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <!-- ...other testing-only env overrides -->
    </php>
</phpunit>
```

The `<source><include><directory>app</directory>` block is what scopes
code coverage to application code (excluding vendor, config, tests
themselves). `DB_DATABASE=":memory:"` + `DB_CONNECTION="sqlite"` gives each
test run a fast, isolated in-memory database.

### Coverage configuration

Coverage needs a driver — Xdebug locally (`xdebug.mode = develop,debug,coverage`
in `php.ini`) or PCOV, plus the `coverage: xdebug` (or `pcov`) step in CI.
Run it with:

```bash
vendor/bin/pest --coverage --min=100
```

```bash
vendor/bin/pest --coverage --min=100 --parallel --processes=2 --compact
```

```bash
vendor/bin/pest --coverage-html=storage/coverage
```

The project's `composer test:coverage` script wires this up directly:

```json
"test:coverage": [
    "Composer\\Config::disableProcessTimeout",
    "@php -d memory_limit=1536M -d xdebug.mode=coverage vendor/bin/pest --coverage --only-summary-for-coverage-text --min=100 --compact --parallel --processes=2"
]
```

- `-d xdebug.mode=coverage` forces the Xdebug driver on for that one PHP
  invocation without changing the global `php.ini` mode.
- `--min=100` fails the run if line coverage drops below 100% — treat this
  as a ratchet, not a suggestion; don't lower it to unblock a merge.
- `--only-summary-for-coverage-text` keeps CI/terminal output to a summary
  table instead of a per-file dump.
- `--parallel --processes=2` mirrors `test:unit`/CI concurrency so local
  runs match CI timing and isolation behavior.

Separately, `pestphp/pest-plugin-type-coverage` enforces **type**
coverage (parameter/return/property type-hint completeness), not line
coverage:

```json
"test:type-coverage": [
    "Composer\\Config::disableProcessTimeout",
    "pest --type-coverage --min=100"
]
```

## 4. Frontend packages (`package.json`)

```json
"devDependencies": {
    "@tailwindcss/vite": "^4.0.0",
    "concurrently": "^9.0.1",
    "laravel-vite-plugin": "^3.1",
    "tailwindcss": "^4.0.0",
    "vite": "^8.0.0"
},
"dependencies": {
    "leaflet": "^1.9.4"
}
```

`concurrently` powers the `composer dev` script (§4) which runs the PHP
server, queue listener, log tailer, and Vite dev server together.

## 5. Composer scripts

```json
"scripts": {
    "setup": [
        "composer install",
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
        "@php artisan key:generate",
        "@php artisan migrate --force",
        "npm install --ignore-scripts",
        "npm run build"
    ],
    "dev": [
        "Composer\\Config::disableProcessTimeout",
        "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others"
    ],
    "lint": [
        "rector",
        "pint --parallel"
    ],
    "test:lint": [
        "pint --parallel --test",
        "rector --dry-run"
    ],
    "test:types": "phpstan analyse --memory-limit=1G",
    "test:type-coverage": [
        "Composer\\Config::disableProcessTimeout",
        "pest --type-coverage --min=100"
    ],
    "test:coverage": [
        "Composer\\Config::disableProcessTimeout",
        "@php -d memory_limit=1536M -d xdebug.mode=coverage vendor/bin/pest --coverage --only-summary-for-coverage-text --min=100 --compact --parallel --processes=2"
    ],
    "test:unit": [
        "@php artisan config:clear --ansi @no_additional_args",
        "@php artisan test --compact --parallel --processes=2 --passthru-php=\"-d memory_limit=1G\""
    ],
    "test": [
        "@test:lint",
        "@test:types",
        "@test:type-coverage",
        "@test:coverage"
    ],
    "post-autoload-dump": [
        "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
        "@php artisan package:discover --ansi",
        "@php artisan filament:upgrade"
    ],
    "post-update-cmd": [
        "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
    ]
}
```

`composer test` runs, in order: style/refactor check → static analysis →
type-coverage threshold → full test suite with code coverage. This is the
same gate CI (`.github/workflows/tests.yml`) should run. `composer setup`
bootstraps a fresh clone; `composer dev` runs the local dev process group.

## 6. Supporting config files to copy over

- **`phpstan.neon`** — `level: max`, includes
  `vendor/larastan/larastan/extension.neon` + `phpstan-baseline.neon`,
  `paths` pointed at `app`, `bootstrap/app.php`, `config`, `database`,
  `routes`. New baseline entries are forbidden going forward — the baseline
  may only shrink.
- **`pint.json`** — `"preset": "laravel"` plus stricter rules
  (`declare_strict_types`, `strict_comparison`, `date_time_immutable`,
  `mb_str_functions`, `modernize_types_casting`, `visibility_required`, etc).
- **`rector.php`** — `withComposerBased(laravel: true)`, prepared sets
  `deadCode`, `codeQuality`, `typeDeclarations`, `earlyReturn`,
  `codingStyle`, plus project-specific `withSkip()` exceptions (document
  *why* each skip exists inline).
- **`php.ini`** (local dev) — `xdebug.mode = develop,debug,coverage` so
  `test:coverage` works locally without relying on CI-only coverage.
- **`tests/Unit/ArchTest.php`** — Pest architecture test enforcing
  structural rules.

## 7. AI-assistant / agent tooling

### Laravel Boost + Filament MCP servers (`.mcp.json`)

```json
{
    "mcpServers": {
        "laravel-boost": {
            "command": "php",
            "args": ["artisan", "boost:mcp"]
        },
        "filamentphp": {
            "command": "npx",
            "args": ["-y", "filamentphp-mcp"]
        }
    }
}
```

Install with `composer require laravel/boost --dev`, then
`php artisan boost:install` to publish guideline docs and register the MCP
server. This gives agents `database-query`, `database-schema`,
`search-docs`, `browser-logs`, `get-absolute-url`, `tinker`, and Artisan
introspection tools scoped to the actual app.

### Dev server config for AI browser preview (`.claude/launch.json`)

```json
{
    "version": "0.0.1",
    "configurations": [
        {
            "name": "<project-name>",
            "runtimeExecutable": "php",
            "runtimeArgs": ["artisan", "serve", "--port=8000"],
            "port": 8000
        }
    ]
}
```

### `CLAUDE.md` guideline rules

The project's `CLAUDE.md` embeds Laravel Boost's generated guideline
blocks (`foundation`, `php`, `herd`, `laravel/core`, `pint/core`,
`pest/core`, `deployments`) plus a project-authored
**AI Feature Development Standard** section covering: discover-before-change,
prefer `search-docs` over memorized syntax, small reviewable commits,
explicit types, mandatory tests per behavior change, and the "never weaken
quality gates to pass CI" rule. Regenerate the Boost blocks with
`php artisan boost:mcp` / `php artisan boost:install` after upgrading
Boost or adding packages it has doc coverage for.

### Claude Code skills (`.claude/skills`, `.agents/skills`, `skills-lock.json`)

Two sources feed the skills directories:

1. **`spatie/guidelines-skills` (Composer package, §2)** publishes:
   `spatie-laravel-php`, `spatie-javascript`, `spatie-security`,
   `spatie-version-control`, plus `laravel-best-practices` and
   `pest-testing`. These enforce Spatie's PHP/Laravel/JS coding standards,
   security/server-hardening checklist, and git conventions whenever
   matching code is touched.
2. **GitHub-sourced skills** tracked in `skills-lock.json`
   (`amElnagdy/guard-skills`): `clean-code-guard`, `docs-guard`,
   `test-guard` — pre-ship review passes for production code, docs, and
   test code respectively.
3. **Spec Kit skills** (`speckit-constitution`, `speckit-specify`,
   `speckit-clarify`, `speckit-plan`, `speckit-tasks`,
   `speckit-taskstoissues`, `speckit-analyze`, `speckit-checklist`,
   `speckit-implement`, `speckit-converge`) back the `.specify/` spec-driven
   workflow (`.specify/memory`, `.specify/templates`, `.specify/workflows`,
   per-feature `specs/NNN-*/` folders).

`skills-lock.json` records `source`, `sourceType`, `skillPath`, and a
content hash per GitHub-sourced skill so they can be re-fetched/verified;
re-run the skill installer after editing this file rather than hand-editing
`.claude/skills`/`.agents/skills`.

## 8. Day-to-day commands

```bash
vendor/bin/pint --dirty
```

```bash
vendor/bin/phpstan analyse
```

```bash
php artisan test --compact --filter=testName
```

```bash
composer test
```

```bash
composer dev
```

Run the full `composer test` gate before opening a PR; use the individual
`test:*` scripts or a `--filter` during iterative development to avoid
re-running the whole suite each time.

## 9. Rules of thumb when reusing this setup

1. Keep `test:lint`, `test:types`, `test:type-coverage`, and `test:coverage`
   as separate scripts (not one big blob) so failures are easy to localize.
2. Never lower the PHPStan level or the type-coverage `--min` threshold to
   make a build pass — fix the underlying issue instead.
3. `--parallel --processes=2` on `test:coverage`/`test:unit` mirrors CI;
   don't re-run the full suite single-worker afterward just to "confirm" —
   a parallel pass that's green is already trustworthy.
4. Pin `roave/security-advisories: dev-latest` in `require-dev` so
   `composer install`/`update` fails fast on packages with disclosed CVEs.
5. Install `laravel/boost` and `spatie/guidelines-skills` early — they
   shape how AI agents write code in the repo from day one instead of
   retrofitting conventions later.
6. Treat `CLAUDE.md` as living documentation: regenerate Boost's guideline
   blocks after package upgrades, and keep the project-specific standard
   section in sync with actual CI gates.
