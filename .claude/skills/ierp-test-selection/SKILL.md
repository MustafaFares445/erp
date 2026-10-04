---
name: ierp-test-selection
description: "IERP-specific test selection policy. Use this skill whenever an agent changes application code, tests, migrations, seeders, permissions, Filament UI, APIs, CI/testing configuration, or dependencies. It decides the smallest safe test scope during iteration and the required escalation before completion. This project policy supplements the generic pest-testing skill."
---

# IERP Test Selection

Use this skill after identifying the owning domain and before choosing which tests to run.

The goal is fast feedback without weakening the repository's full CI, 100% code coverage, 100% type coverage, or critical MySQL acceptance gates.

## Core Rule

Do **not** run the entire repository test/coverage gate after every edit.

During iteration:

1. run the exact regression test or exact test file;
2. expand to the owning domain suite;
3. escalate only when the change is shared, cross-domain, infrastructure-sensitive, or critical-risk.

The clean CI/full verification gate remains authoritative before merge/release and when explicitly requested.

## Current Commands Only

These rules describe the repository **before** the planned test-tooling optimization is fully implemented. Do not invent future Composer aliases.

### Exact test

```bash
php vendor/bin/pest tests/Feature/<path>/<TestFile>.php --compact
php vendor/bin/pest --filter="<test name>" --compact
```

### True Unit scope

Until the planned Unit-suite cleanup is implemented, use the directory directly instead of the current misleading `composer test:unit` alias:

```bash
php vendor/bin/pest tests/Unit --compact
```

### Owning domain suites

```bash
php vendor/bin/pest tests/Feature/Accounting --compact
php vendor/bin/pest tests/Feature/Crm --compact
php vendor/bin/pest tests/Feature/Employees --compact
php vendor/bin/pest tests/Feature/Inventory --compact
php vendor/bin/pest tests/Feature/Payments --compact
php vendor/bin/pest tests/Feature/Purchasing --compact
php vendor/bin/pest tests/Feature/Sales --compact
php vendor/bin/pest tests/Feature/Settings --compact
php vendor/bin/pest tests/Feature/Support --compact
```

For Filament-specific work, run the exact Filament test and the owning domain tests when the behavior is domain-owned:

```bash
php vendor/bin/pest tests/Feature/Filament --compact
```

For customer/API work, select the relevant subtree under:

```text
tests/Feature/Api
```

### Broad local regression without coverage

Use when impact is shared/uncertain or before handoff of a cross-domain change:

```bash
composer test:fast
```

### Static/type checks

Run the applicable checks when PHP behavior or types changed:

```bash
composer test:types
composer test:type-coverage
composer test:lint
```

### Authoritative expensive gates

Do not use these after every small edit:

```bash
composer test:coverage
composer test
```

They remain required by CI/full verification policy and when the task explicitly requires the complete gate.

## Selection Matrix

| Change | During iteration | Before agent completion |
|---|---|---|
| Docs only | relevant docs check | `composer test:docs` when applicable |
| Pure calculation/helper/value object | exact Unit test | Unit directory or owning domain if business-critical |
| Domain service/action | exact service regression | owning domain suite |
| Bug fix | exact reproducing regression first | owning domain suite |
| Model relation/cast/scope | exact model test | owning domain suite |
| Filament resource/page/action/widget | exact Filament test | relevant Filament tests + owning domain |
| API/controller/request/resource | exact API test | relevant API subtree + owning domain |
| Policy/permission/role | exact authorization test | owning domain + architecture/permission tests |
| Seeder/reference data | exact seeder test | owning domain; seed-integrity if production seed behavior changed |
| Migration/schema | exact affected tests | owning domain; broad regression if shared schema |
| Queue/job/listener/notification | exact test | owning domain + notification/integration tests |
| Shared enum/module registry/common concern | exact Unit/arch test | `composer test:fast` |
| Cross-domain workflow | exact seam test | every affected domain + existing cross-module tests |
| Test runner/Pest/phpunit/composer test scripts | runner smoke | `composer test:fast` + coverage/static gates |
| CI workflow/sharding | local full fast regression | complete CI validation |
| Dependency/framework major update | focused compatibility tests | complete full gate |

## Domain Ownership / Seam Escalation

Use the repository domain map and canonical docs. Typical escalations:

- Sales changes that alter payment/tax/accounting effects -> Sales + Payments/Accounting seam.
- Delivery/fulfilment changes that mutate stock -> Sales/Logistics + Inventory seam.
- Purchasing receiving changes -> Purchasing + Inventory.
- Payment allocation/refund changes -> Payments + Accounting and originating document domain.
- Support billing/payment changes -> Support + Payments/Accounting as applicable.
- Employee AI changes -> Employees plus AI failure-isolation tests.

Do not duplicate an owning domain's calculation in another domain just to make a test easier.

## Critical-Risk Escalation

Always expand beyond a single exact test when changing:

- accounting posting/reversal/balance;
- inventory stock, reservation, custody, movement, or locking;
- payments, allocations, deposits, refunds, provider settlement;
- tax recognition;
- posted-document immutability/correction;
- authorization or tenant/customer/employee ownership boundaries;
- idempotency;
- concurrency/race handling;
- cross-domain provenance.

For database concurrency/locking semantics, SQLite is insufficient. Run the dedicated MySQL acceptance test(s) that cover the changed invariant. Current examples include:

```text
tests/Feature/Inventory/InventoryBalanceConcurrencyTest.php
tests/Feature/Inventory/InventoryOperationConcurrencyTest.php
tests/Feature/Inventory/InventoryReleaseAcceptanceTest.php
```

Do not run unrelated MySQL acceptance jobs for ordinary non-concurrency edits.

## Test-Writing Rules

When a behavior changes:

- add or update the smallest regression test that proves the behavior;
- prefer an existing owning-domain test file before creating a new coverage-gap file;
- do not add a test merely to execute lines if an existing behavioral test can cover the branch;
- use datasets for input variants with the same scenario;
- never delete a production-bug regression;
- do not test framework guarantees;
- do not mock internal domain services merely to force unit isolation.

Use the generic `pest-testing` skill for Pest syntax and `test-guard` after writing/editing tests.

## Completion Report

An agent completing code work must state:

- owning domain(s);
- exact/focused tests run;
- domain suite(s) run;
- whether `composer test:fast` was required/run;
- whether MySQL acceptance was required/run;
- whether full coverage/full Composer gate was run or intentionally left to CI/final verification.

Never claim “all tests pass” unless the full relevant suite actually ran.

## Planned Future Update

The active plan `Docs/plans/TEST_SUITE_OPTIMIZATION_AND_TOOLCHAIN_UPGRADE_IMPLEMENTATION_PLAN.md` will introduce normalized domain Composer aliases, changed-domain selection, time-balanced sharding, and later Pest 5/TIA/Agent tooling.

After those commands exist, update this skill to use the implemented aliases/TIA. Until then, the commands in this file are the source of truth for agent selection policy.
