# Testing and Quality

---
status: canonical
owner: engineering
last_verified: 2026-10-05
verified_against: composer.json, phpunit.xml, .github/workflows/tests.yml, current Pest 4 test tree
---

## Verification Model

The repository has two different testing needs:

1. **Fast developer/agent feedback** — run the smallest test scope that can prove the current change.
2. **Authoritative full verification** — run the complete quality/coverage/acceptance gates in CI or when explicitly required.

Do not use the most expensive full coverage gate after every small edit. This changes execution strategy only; it does not weaken the required 100% type/code coverage gates.

Coding agents must use:

```text
.agents/skills/ierp-test-selection/SKILL.md
```

to select exact, domain, broad-fast, MySQL-acceptance, or full verification scope.

## Project Quality Gates

### Exact/focused iteration

Run the exact test file or filtered test first:

```bash
php vendor/bin/pest tests/Feature/<domain>/<TestFile>.php --compact
php vendor/bin/pest --filter="<test name>" --compact
```

The current domain test folders are the preferred completion scope for ordinary same-domain behavior changes.

### Fast behavioral suite

```bash
composer test:fast
```

Runs Pest compact/parallel locally without PCOV/Xdebug coverage.

Use this for shared/core/uncertain-impact or cross-domain local regression. It is intentionally broader than a normal single-domain completion check.

### Formatting / automated refactor check

```bash
composer test:lint
```

Runs Pint in test mode and Rector dry-run.

To apply the configured code cleanup/formatting:

```bash
composer lint
```

### Documentation check

```bash
composer test:docs
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

This is an authoritative/full-verification command, not the default post-edit feedback command.

### Full Composer gate

```bash
composer test
```

Current sequence:
1. `test:lint`
2. `test:docs`
3. `test:types`
4. `test:type-coverage`
5. `test:coverage`

Use the full gate when the task explicitly requires it, before major toolchain/test-runner changes are accepted, or through the CI/full-verification stage.

## Current Important Caveat

The existing `composer test:unit` script is not yet a trustworthy Unit-only selector; it currently invokes the Laravel test runner without limiting it to `tests/Unit`.

Until the active test-suite optimization plan corrects this, agents should use:

```bash
php vendor/bin/pest tests/Unit --compact
```

for explicit Unit-directory runs.

## CI

`.github/workflows/tests.yml` runs on pull requests and pushes to `main` / `dev`.

It currently includes:

- quality job: docs, Pint/Rector, PHPStan, 100% type coverage;
- behavioral tests sharded across four Ubuntu runners;
- 100% code coverage job using PCOV;
- focused CRM acceptance;
- MySQL fresh-migrate/seed integrity;
- MySQL warehouse release/concurrency acceptance.

The active plan `Docs/plans/TEST_SUITE_OPTIMIZATION_AND_TOOLCHAIN_UPGRADE_IMPLEMENTATION_PLAN.md` will add time-balanced sharding and clearer test tiers before the later Pest 5/toolchain upgrade.

## Test Defaults

Normal PHPUnit/Pest tests use in-memory SQLite with synchronous queues and fake Employee transcription.

Do not assume passing SQLite tests proves database-concurrency semantics. Inventory concurrency has dedicated MySQL acceptance coverage.

## Test Ownership

Domain-specific testing guidance lives under `Docs/domains/<domain>/TESTING.md` where the domain needs it.

Cross-domain behavior should be tested at the seam as well as inside individual services.

During ordinary implementation:

1. exact regression test;
2. owning domain suite;
3. broaden only for shared/cross-domain/critical-risk impact.

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

Changes to concurrency/locking semantics must use the dedicated MySQL acceptance path rather than relying on SQLite alone.

## Do Not Weaken the Gate

Do not:
- lower type/coverage thresholds;
- delete meaningful tests to pass;
- add arbitrary PHPStan baseline entries;
- bypass architecture tests;
- replace real domain assertions with superficial UI assertions;
- use focused local passing tests as a claim that the full repository gate was executed.
