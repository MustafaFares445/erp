---
name: ierp-test-selection
description: "IERP-specific test selection policy. Use whenever an agent changes application code, tests, migrations, seeders, permissions, Filament UI, APIs, CI/testing configuration, or dependencies. Selects the smallest safe test scope and required escalation before completion."
---

# IERP Test Selection

Use this skill after identifying the owning domain and before choosing tests.

The goal is fast feedback without weakening clean CI, 100% code coverage, 100% type coverage, architecture tests, or critical MySQL acceptance.

## Decision Algorithm

For every code change:

1. Identify the owning domain.
2. Classify the change type.
3. Decide whether it is critical-risk or cross-domain.
4. Run the exact regression test while iterating.
5. Run the owning domain suite before completion.
6. Add seam suites when another domain owns a side effect.
7. Escalate to `composer test:fast` for shared/core/unknown impact.
8. Run dedicated MySQL acceptance for locking/concurrency/engine semantics.
9. Run the full coverage/full Composer gate only when the task, CI, dependency/tooling change, or release stage requires it.
10. Report exactly what ran.

Do **not** run the entire repository coverage gate after every edit.

## Commands

### Exact test

```bash
php vendor/bin/pest tests/Feature/<path>/<TestFile>.php --compact
php vendor/bin/pest tests/Unit/<TestFile>.php --compact
php vendor/bin/pest --filter="<test name>" --compact
```

### Changed-impact selection

```bash
composer test:changed
composer test:changed -- --dry-run
```

Use `--dry-run` when you need to inspect the selector's decision first.

The selector is intentionally conservative: shared models/enums/migrations/config/test-runner/CI changes escalate to the fast repository suite.

### Unit / broad behavioral

```bash
composer test:unit
composer test:feature
composer test:behavioral
composer test:fast
composer test:tia
```

- `test:unit`: true Unit tests; Laravel does not boot by default.
- `test:feature`: Feature tests in parallel.
- `test:behavioral`: normal behavioral suite sequentially for troubleshooting.
- `test:fast`: broad normal behavioral suite in parallel.
- `test:tia`: Pest 5 local-only Test Impact Analysis for iterative broad feedback; run the exact regression first and never use TIA as a clean-CI replacement.
- normal fast commands exclude the explicit `coverage-only` and `architecture` groups; architecture has its own required gate.

Framework-dependent tests that were historically misclassified as Unit live under `tests/Feature/UnitIntegration`.

### Domain suites

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

### Performance / shard maintenance

```bash
composer test:profile
composer test:shards:update
```

Do not refresh shard timing after every edit. Refresh after material suite/runtime changes or observed CI imbalance.

### Static / full gates

```bash
composer test:docs
composer test:lint
composer test:types
composer test:type-coverage
composer test:coverage
composer test:full
composer test
```

`test:coverage` and `test:full` are authoritative expensive gates, not default iteration commands.

## Selection Matrix

| Change | During iteration | Before completion |
|---|---|---|
| Documentation only | relevant doc inspection | `composer test:docs` |
| Pure helper/value object/calculation | exact Unit test | `composer test:unit` or owning domain if business-critical |
| Domain service/action | exact service regression | owning domain alias |
| Bug fix | exact reproducing regression first | owning domain alias |
| Model relation/cast/scope | exact test | owning domain; shared model => `test:fast` |
| Filament resource/page/action/widget | exact Filament test | `test:filament` + owning domain when domain behavior is involved |
| API/controller/request/resource | exact API test | `test:api` + owning domain |
| Policy/permission/role | exact auth test | owning domain + architecture/permission tests |
| Seeder/reference data | exact seeder test | owning domain; seed-integrity if production seed behavior changed |
| Migration/schema | exact affected test | owning domain; shared schema => `test:fast` |
| Queue/job/listener/notification | exact test | owning domain + `test:notifications` when applicable |
| Shared enum/module registry/common concern | exact Unit/architecture test | `composer test:fast` |
| Cross-domain workflow | exact seam test | every affected owner + existing cross-module suite |
| Pest/phpunit/composer test scripts | runner smoke | `test:fast` + static/type/coverage gates |
| CI/sharding change | dry-run/focused checks | full CI validation |
| Composer/NPM dependency change | focused compatibility tests | affected domains; major/toolchain => full gate |
| Framework/Pest/PHPUnit major upgrade | focused compatibility tests | complete full gate |

## Domain / Seam Escalation

Typical ownership rules:

- Sales changes altering collection/tax/accounting -> Sales + Payments/Accounting seam.
- Delivery/fulfilment changing stock -> Sales/Logistics + Inventory.
- Purchasing receiving -> Purchasing + Inventory.
- Payment allocation/refund -> Payments + Accounting + originating document domain as applicable.
- Support billing/payment -> Support + Payments/Accounting as applicable.
- Employee AI -> Employees plus AI failure-isolation tests.
- Shipment execution changing stock -> Shipments + Inventory/Sales as applicable.

Do not duplicate the owning domain's calculation to make a test easier.

## Critical-Risk Escalation

Always expand beyond one exact test for:

- ledger posting/reversal/balance;
- inventory stock/reservation/custody/movement;
- payments/allocations/deposits/refunds/provider settlement;
- tax recognition;
- fiscal-period restrictions;
- posted-document immutability/correction;
- authorization or customer/employee ownership boundaries;
- idempotency;
- concurrency/locking;
- cross-domain provenance.

For database concurrency/locking semantics, SQLite is insufficient. Run the applicable MySQL acceptance tests, including:

```text
tests/Feature/Inventory/InventoryReleaseAcceptanceTest.php
tests/Feature/Inventory/InventoryBalanceConcurrencyTest.php
tests/Feature/Inventory/InventoryOperationConcurrencyTest.php
```

Do not run unrelated MySQL acceptance work for ordinary non-concurrency edits.

## Coverage-Only Rules

Tests under the configured coverage directories are grouped `coverage-only`.

They:

- are excluded from normal fast feedback;
- remain included in `composer test:coverage`;
- must not contain a production-bug regression that should execute on every normal regression run.

Prefer adding real new behavior tests to the owning domain rather than adding another generic coverage-gap file.

## Test-Writing Rules

When behavior changes:

- add/update the smallest regression test that proves it;
- prefer an existing owning-domain test file;
- use datasets for input variants of the same scenario;
- never delete a production-bug regression;
- do not test framework guarantees;
- do not mock internal domain services merely to force unit isolation.

Use the generic `testing-best-practices` skill for Laravel test design/review, version-specific docs for Pest syntax, and `test-guard` after writing/editing tests.

## Completion Report

Every coding agent must state:

- owning domain(s);
- exact/focused tests run;
- domain suite(s) run;
- whether `test:fast` was required/run;
- whether MySQL acceptance was required/run;
- whether full coverage/full gate was run or intentionally left to CI/final verification.

Never claim “all tests pass” unless the relevant full suite actually ran.

## Pest 5 Local Accelerators

IERP runs Pest 5 / PHPUnit 13.

After the exact regression test, agents may use `composer test:tia` for faster broad local feedback when a TIA baseline exists or when recording one is worthwhile. TIA is local-only: clean CI behavioral shards must continue to execute the full required suite.

For one-off exploratory verification inside the real Pest/Laravel environment, the installed Agent plugin may be invoked with:

```bash
php vendor/bin/pest --agent "<PHP snippet>" --compact
```

Agent snippets are disposable verification, not a substitute for committed regression tests. The Pest PHPStan extension is also enabled in `phpstan.neon`; do not suppress new static-analysis findings or add baseline debt merely to adopt it.
