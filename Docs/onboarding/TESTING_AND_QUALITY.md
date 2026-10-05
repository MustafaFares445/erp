# Testing and Quality

---
status: canonical
owner: engineering
last_verified: 2026-10-05
verified_against: composer.json, phpunit.xml, tests/Pest.php, scripts/test-changed.php, .github/workflows/tests.yml
---

## Verification Model

IERP uses tiered verification so developers and coding agents get fast feedback without weakening the authoritative CI gates.

1. **Exact regression** — the smallest test/file/filter that proves the current change.
2. **Domain regression** — the owning domain and required cross-domain seams.
3. **Fast repository regression** — all normal behavioral tests, parallel, without coverage-only suites.
4. **Authoritative full verification** — static/type checks plus 100% code coverage and dedicated MySQL acceptance jobs.

Do not run the most expensive full coverage gate after every edit.

Coding agents must use:

```text
.agents/skills/ierp-test-selection/SKILL.md
```

## Core Commands

### Exact / filtered

```bash
php vendor/bin/pest tests/Feature/<domain>/<TestFile>.php --compact
php vendor/bin/pest --filter="<test name>" --compact
```

### Changed-impact selector

```bash
composer test:changed
composer test:changed -- --dry-run
```

`scripts/test-changed.php` reads Git changes, maps clearly-owned service changes to domain/seam suites, and conservatively escalates shared infrastructure (models, enums, migrations, config, Composer, PHPUnit/Pest, CI) to the broad fast suite.

It is a developer/agent feedback tool, not a replacement for clean CI.

### Pest 5 local TIA

```bash
composer test:tia
```

`test:tia` runs Pest 5 Test Impact Analysis locally with parallel execution. Use it only as an iterative broad-feedback accelerator after the exact regression test. The first eligible run records the dependency graph; partial/file-filtered runs execute the selected tests directly because TIA does not apply to partial runs.

Clean CI behavioral shards do **not** use TIA and continue to execute the complete behavioral suite.

### Unit

```bash
composer test:unit
```

True Unit tests do not boot Laravel by default.

Laravel/framework-dependent tests that were previously misclassified under `tests/Unit` live under:

```text
tests/Feature/UnitIntegration
```

### Feature / behavioral

```bash
composer test:feature
composer test:behavioral
composer test:fast
```

- `test:feature`: Feature tests in parallel, excluding `coverage-only`.
- `test:behavioral`: normal behavioral tests sequentially, useful for deterministic troubleshooting.
- `test:fast`: normal behavioral tests in parallel; this is the broad local regression command.
- PCOV/Xdebug coverage is disabled for these commands.

### Domain aliases

```bash
composer test:accounting
composer test:api
composer test:crm
composer test:employees
composer test:filament
composer test:inventory
composer test:notifications
composer test:payments
composer test:performance
composer test:purchasing
composer test:sales
composer test:settings
composer test:shipments
composer test:support
```

Use the owning domain alias before completion of an ordinary same-domain behavior change.

### Profiling

```bash
composer test:profile
```

Shows Pest's slowest tests for performance investigations. Performance improvements must be measured; do not trade determinism for speed.

### Shard timing

```bash
composer test:shards:update
```

Refreshes `tests/.pest/shards.json` for time-balanced CI sharding. Refresh after material suite/runtime changes or when CI shards become imbalanced.

### Pest 5 agent verification

The installed `pestphp/pest-plugin-agent` supports one-off verification inside the real Pest/Laravel environment via `vendor/bin/pest --agent "<PHP snippet>"`. Use it for exploratory checks only; durable behavior still requires committed regression tests.

The installed `pestphp/pest-plugin-phpstan` extension is included from `phpstan.neon` so PHPStan understands Pest's functional API and test closure context for test files that are part of the configured PHPStan analysis paths.

### Formatting / automated refactor check

```bash
composer test:lint
composer lint
```

### Documentation

```bash
composer test:docs
```

### Static analysis

```bash
composer test:types
```

New PHPStan baseline debt must not be introduced merely to pass CI.

### Type coverage

```bash
composer test:type-coverage
```

Required minimum: **100%**.

### Code coverage

```bash
composer test:coverage
```

The repository coverage runner uses PCOV when available and requires **100%** in CI.

Coverage-only test directories are explicitly grouped as `coverage-only`. They are excluded from normal fast behavioral feedback but remain part of `test:coverage`.

### Full gate

```bash
composer test:full
composer test
```

Current sequence:

1. `test:lint`
2. `test:docs`
3. `test:architecture`
4. `test:types`
5. `test:type-coverage`
6. `test:coverage`

The coverage command executes the complete suite, including coverage-only tests, so the local full gate does not redundantly run `test:fast` first.

## Laravel Test Bootstrap

Only Feature tests receive the project Laravel `Tests\TestCase` by default.

Feature tests intentionally do **not** use Laravel's `WithCachedConfig` or `WithCachedRoutes` traits.

Both optimizations were benchmarked during the 2026-10-05 test-suite optimization work and rejected after they changed authentication/session semantics for the first Filament request after database seeding (the first authenticated resource request redirected to login while the next request succeeded). Correct test isolation takes priority over the bootstrap speedup.

Do not re-enable these cache traits without a dedicated compatibility test proving first-request authentication, seeded admin access, configuration mutation, route behavior, and parallel isolation remain correct.

## Coverage-Only Classification

The following directories are grouped as `coverage-only`:

- `tests/Feature/Coverage`
- `tests/Feature/UnitIntegration/Coverage`
- `tests/Unit/Coverage`

A real production-bug regression or meaningful domain scenario should live in the owning domain instead of a generic coverage directory.

Do not delete coverage-only tests simply to improve runtime. They remain required by the 100% coverage gate until deliberately replaced by stronger behavioral coverage.

## CI

`.github/workflows/tests.yml` runs on pull requests and pushes to `main` / `dev`.

Responsibilities:

- **Quality** — docs, Pint/Rector, architecture, PHPStan, 100% type coverage.
- **Behavioral shards** — normal behavioral suite split across eight time-balanced runners, coverage disabled, coverage-only and architecture groups excluded.
- **Coverage** — full 100% PCOV coverage including coverage-only tests.
- **Seed integrity** — MySQL fresh migrate/seed verification.
- **Warehouse acceptance** — MySQL release/concurrency/locking behavior.

Composer download cache is shared through GitHub Actions cache.

The old focused CRM acceptance job was removed because its static and regression checks are already covered by the global quality and behavioral jobs and it did not require distinct infrastructure.

## Database / Concurrency Rules

Normal tests use in-memory SQLite, synchronous queues, and fake Employee transcription.

Passing SQLite tests does **not** prove database-concurrency semantics.

Changes to locking, races, inventory balances, reservations, or other engine-sensitive behavior must run the dedicated MySQL acceptance tests, including the applicable tests under:

```text
tests/Feature/Inventory/InventoryReleaseAcceptanceTest.php
tests/Feature/Inventory/InventoryBalanceConcurrencyTest.php
tests/Feature/Inventory/InventoryOperationConcurrencyTest.php
```

## Test Selection Order

During ordinary implementation:

1. exact regression test;
2. owning domain suite;
3. cross-domain seam suites when the rule crosses ownership boundaries;
4. `composer test:fast` for shared/core/uncertain impact;
5. MySQL acceptance when database-engine semantics are involved;
6. full gate when required by the task/CI/release stage.

## Critical Invariants

Tests must protect at least:

- inventory custody/reservation/balance invariants;
- accounting balance, posting immutability and period rules;
- payment allocation/deposit/tax/refund rules;
- authorization/domain isolation;
- lifecycle transitions;
- cross-domain provenance;
- AI failure isolation and human review;
- provider idempotency/reconciliation where applicable.

## Do Not Weaken the Gate

Do not:

- lower type/coverage thresholds;
- delete meaningful tests to pass;
- add arbitrary PHPStan baseline entries;
- bypass architecture tests;
- replace real domain assertions with superficial UI assertions;
- use focused local passing tests as a claim that the full repository gate was executed.
