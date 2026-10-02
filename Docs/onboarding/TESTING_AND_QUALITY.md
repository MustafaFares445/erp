# Testing and Quality

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: composer.json, phpunit.xml, .github/workflows/tests.yml
---

## Project Quality Gates

### Fast behavioral suite

```bash
composer test:fast
```

Runs Pest compact/parallel locally without PCOV/Xdebug coverage.

### Formatting / automated refactor check

```bash
composer test:lint
```

Runs Pint in test mode and Rector dry-run.

To apply the configured code cleanup/formatting:

```bash
composer lint
```

### Static analysis

```bash
composer test:types
```

Runs PHPStan/Larastan with the project configuration.

New baseline debt must not be introduced merely to pass CI.

### Type coverage

```bash
composer test:type-coverage
```

Required minimum: **100%**.

### Code coverage

```bash
composer test:coverage
```

Runs the repository's `.github/scripts/run-coverage.php` gate.

Required CI target: **100%**.

### Full Composer gate

```bash
composer test
```

Current sequence:
1. `test:lint`
2. `test:types`
3. `test:type-coverage`
4. `test:coverage`

## CI

`.github/workflows/tests.yml` runs on pull requests and pushes to `main` / `dev`.

It currently includes:

- quality job: Pint/Rector, PHPStan, 100% type coverage;
- behavioral tests sharded across four Ubuntu runners;
- 100% code coverage job using PCOV;
- focused CRM acceptance;
- MySQL fresh-migrate/seed integrity;
- MySQL warehouse release/concurrency acceptance.

## Test Defaults

Normal PHPUnit/Pest tests use in-memory SQLite with synchronous queues and fake Employee transcription.

Do not assume passing SQLite tests proves database-concurrency semantics. Inventory concurrency has dedicated MySQL acceptance coverage.

## Test Ownership

Domain-specific testing guidance lives under `Docs/domains/<domain>/TESTING.md` where the domain needs it.

Cross-domain behavior should be tested at the seam as well as inside individual services.

## Critical Invariants

Tests must protect at least:

- Inventory custody/reservation/balance invariants.
- Accounting balance, posting immutability and period rules.
- Payment allocation/deposit/tax/refund rules.
- Authorization/domain isolation.
- Lifecycle transitions.
- Cross-domain provenance.
- AI failure isolation and human review.
- Provider idempotency/reconciliation where applicable.

## Do Not Weaken the Gate

Do not:
- lower type/coverage thresholds;
- delete meaningful tests to pass;
- add arbitrary PHPStan baseline entries;
- bypass architecture tests;
- replace real domain assertions with superficial UI assertions.
