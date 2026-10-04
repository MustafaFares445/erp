---
status: active-plan
owner: engineering
last_verified: 2026-10-05
---

# Test Suite Optimization, Agent Test Selection, and Toolchain Upgrade Implementation Plan

Target: repository-wide testing / CI / agent workflow / dependency toolchain

Depends on: current dev branch behavior and existing quality gates.

Completion rule: remove this plan after implementation is reconciled into canonical docs and accepted.

## 1. Purpose

IERP now has a large regression suite. The goal is to preserve the current correctness guarantees while making the developer feedback loop, AI-agent workflow, and CI pipeline substantially faster and more predictable.

This plan deliberately separates two major programs:

1. **Program A — Test architecture and execution optimization**
   - Keep behavioral confidence and 100% quality thresholds.
   - Stop running the most expensive verification path for every local edit.
   - Select tests by scope/domain/risk.
   - Reduce Laravel boot, database, seeding, coverage, and CI sharding overhead.
   - Teach coding agents exactly which verification tier to run.

2. **Program B — Toolchain and dependency modernization**
   - Starts only after Program A is stable and fully green.
   - Upgrade all direct backend/frontend packages in controlled batches.
   - Upgrade from Pest 4 / PHPUnit 12 to Pest 5 / PHPUnit 13.
   - Adopt current Pest tooling such as TIA, time-balanced sharding, Agent plugin, and first-party PHPStan integration when compatible.
   - Regenerate/update AI testing skills after the upgrade.

Program B must not be mixed into Program A. Performance refactoring and dependency-major upgrades must remain independently diagnosable.

## 2. Current Baseline

Repository inspection on 2026-10-05 found:

| Metric | Current state |
|---|---:|
| Test files | 774 |
| Feature test files | 694 |
| Unit test files | 80 |
| `tests/Feature/Coverage` files | 172 |
| Coverage-only/gap files as share of all test files | ~22.2% |
| Files using `RefreshDatabase` | ~690 |
| Current test framework | Pest 4.7 / PHPUnit 12.5 |
| Runtime | PHP 8.4 / Laravel 13 |
| Fast local suite | Pest compact + parallel, coverage disabled |
| Code coverage | PCOV preferred, application scope `app/`, minimum 100% |
| Type coverage | minimum 100% |
| CI behavioral sharding | 4 shards |
| Time-balanced shard data | not yet committed |
| Default test database | SQLite in-memory |
| Dedicated concurrency/acceptance database | MySQL for selected jobs |

The current `composer test` is a full verification command. It runs lint/static/type checks and the complete coverage path. This is appropriate as a final gate, but too expensive as the default feedback loop for every edit.

The current `test:unit` script is also misleading: it currently invokes the Laravel test runner without limiting execution to the Unit suite.

The current Pest bootstrap binds Laravel `Tests\TestCase` to both `Feature` and `Unit`, so many tests under `tests/Unit` unnecessarily have access to a booted Laravel application.

## 3. Non-Negotiable Quality Rules

The optimization may change **when** and **how** tests are run. It must not weaken **what is protected**.

The following remain mandatory:

- 100% type coverage.
- 100% code coverage at the authoritative full verification gate.
- No deletion of meaningful regression tests just to improve runtime.
- No new PHPStan baseline debt to compensate for test/tool changes.
- Accounting, inventory, payment, authorization, lifecycle, idempotency, and cross-domain invariants stay fully protected.
- MySQL-specific concurrency semantics remain covered by dedicated MySQL jobs.
- A local fast path never becomes evidence that the full suite is unnecessary.
- CI full-suite runs always start from a clean checkout and may not use cached pass/fail results as a replacement for execution.
- Unrelated working-tree changes may not be reset, reformatted, committed, or rewritten during this program.

## 4. Target Verification Model

IERP will use explicit verification tiers.

### Tier 0 — Exact Target

Purpose: immediate feedback while implementing one behavior.

Examples:

```bash
vendor/bin/pest tests/Feature/Sales/QuotationPricingTest.php --compact
vendor/bin/pest --filter="quotation uses the configured price floor" --compact
```

Use after each small edit when a known regression test or exact test file owns the behavior.

Coverage: off.

### Tier 1 — Domain Regression

Purpose: verify the owning domain and explicitly known seams.

Examples:

```bash
composer test:sales
composer test:inventory
composer test:support
composer test:accounting
```

Coverage: off.

A domain command may include more than one directory when that is part of the domain contract. Cross-domain changes must explicitly add seam suites.

### Tier 2 — Fast Repository Regression

Purpose: broad local confidence before handoff/push without coverage instrumentation.

```bash
composer test:fast
```

Runs all normal behavioral tests in parallel, excluding tests deliberately classified as coverage-only when that classification is proven safe.

Coverage: off.

### Tier 3 — Clean CI Behavioral Regression

Purpose: execute every behavioral test from a clean checkout.

- Runs across CI shards.
- Uses committed timing data to balance shards.
- Does not use Pest TIA.
- Does not use local test-result caches to skip execution.
- Coverage instrumentation remains off in behavioral shard jobs.

### Tier 4 — Authoritative Full Verification

Purpose: release/merge confidence.

Includes:

- lint / Rector check;
- docs consistency checks;
- PHPStan / Larastan;
- type coverage 100%;
- full behavioral suite;
- code coverage 100%;
- MySQL seed-integrity;
- MySQL warehouse/inventory concurrency acceptance;
- other dedicated critical acceptance jobs.

The final authoritative command must have a clear name such as:

```bash
composer test:full
```

or:

```bash
composer ci
```

The existing `composer test` alias may either remain the authoritative full gate or be redirected only after all documentation and agent instructions are migrated in the same change.

## 5. Test Selection Matrix for Humans and Agents

The following matrix is the target contract that will later be encoded in project AI skills.

| Change type | Required during iteration | Required before completion |
|---|---|---|
| Documentation-only | relevant docs check | docs check |
| Pure calculation/value object/helper with no Laravel dependency | exact Unit test | Unit suite + relevant domain if behavior is domain-critical |
| Domain service/action | exact service test | owning domain suite |
| Bug fix | reproduce bug with exact regression test | owning domain suite |
| Model relationship/cast/scope | exact model test | owning domain Feature suite |
| Filament resource/page/action/widget | exact Filament test | relevant Filament + owning domain suite |
| API/controller/request/resource | exact API test | `tests/Feature/Api` relevant subtree + owning domain |
| Permission/policy/role change | exact authorization test | owning domain + permission/architecture tests |
| Seeder/reference-data change | exact seeder test | relevant domain + seed-integrity where applicable |
| Migration/schema change | exact model/feature test | relevant domain + migration/seed checks; broader regression if shared table |
| Queue/job/listener/notification | exact job/event test | owning domain + notification/integration tests |
| Sales-only behavior | exact test | Sales |
| Inventory stock/reservation/custody behavior | exact test | Inventory + relevant MySQL acceptance when concurrency/locking is affected |
| Purchasing workflow | exact test | Purchasing + Inventory seam when receiving/stock is affected |
| Payment/allocation/refund/tax behavior | exact test | Payments + Accounting/Sales seam as applicable |
| Accounting posting/balance/period behavior | exact test | Accounting + originating domain seam |
| Support/maintenance/warranty/SLA behavior | exact test | Support |
| Employee plan/visit/AI behavior | exact test | Employees |
| CRM behavior | exact test | CRM |
| Shared enum/permission/module registry used across domains | exact Unit/architecture test | Fast repository regression |
| Cross-domain workflow | exact seam test | every owning domain touched + existing cross-module suite |
| Test runner / Pest.php / phpunit.xml / composer test-script change | exact runner smoke check | full behavioral + coverage gate |
| CI workflow/sharding change | local full behavioral | one complete CI run with every shard + coverage |
| Composer dependency change | focused smoke tests | full verification appropriate to package impact |
| Framework / Pest / PHPUnit major upgrade | targeted compatibility tests | complete Tier 4 gate |
| CSS/Blade-only non-business visual change | focused render/page test | relevant Filament/UI suite |
| Coverage-only test refactor | focused coverage test | code coverage 100% |

### Critical-risk escalation rule

Regardless of file count, any change affecting the following must escalate at least one tier beyond an ordinary same-domain change:

- ledger posting or reversal;
- inventory balance/reservation/custody;
- payment allocation/refund/provider settlement;
- tax recognition;
- fiscal-period restrictions;
- posted-document immutability/correction;
- authorization boundaries;
- idempotency;
- concurrency/locking;
- cross-domain provenance.

## 6. Program A — Implementation Phases

### Phase A0 — Establish Reproducible Performance Baselines

#### Goal

Measure before changing execution behavior.

#### Tasks

1. Record:
   - full behavioral runtime without coverage;
   - full coverage runtime;
   - Unit suite runtime;
   - Feature suite runtime;
   - each major domain runtime;
   - current 4 CI shard runtimes;
   - peak memory where practical.
2. Run Pest profiling and record the slowest tests:
   `vendor/bin/pest --profile`.
3. Benchmark parallel worker counts supported by the machine/CI:
   - 4;
   - 8;
   - 12;
   - 16;
   - default CPU count.
4. Record repeated seeder hot spots and application-boot hot spots.
5. Do not commit generated benchmark logs unless a small curated baseline file is intentionally introduced.

#### Acceptance criteria

- Baseline numbers exist before optimization.
- Slowest tests are known.
- Worker count is chosen by measured wall-clock time, not assumption.
- No test semantics changed.

### Phase A1 — Normalize Composer Test Command Semantics

#### Goal

Make every command name accurately describe its scope.

#### Target commands

At minimum implement:

```text
test:unit
test:feature
test:fast
test:full
test:coverage
test:types
test:type-coverage
test:lint
test:docs
test:<domain>
```

Recommended domain aliases:

```text
test:accounting
test:crm
test:employees
test:filament
test:inventory
test:payments
test:purchasing
test:sales
test:settings
test:support
```

#### Required corrections

- `test:unit` must only run `tests/Unit` / Unit testsuite.
- Add an explicit Feature-only command.
- `test:fast` must remain coverage-free.
- `test:coverage` remains the single authoritative code-coverage command.
- `test:full` must clearly compose every required repository gate.
- Avoid ambiguous aliases where “test” unexpectedly means “coverage + static analysis + all acceptance”.

#### Files expected to change

- `composer.json`
- possibly `scripts/` for reusable orchestration
- `Docs/onboarding/TESTING_AND_QUALITY.md` only after commands are implemented

#### Acceptance criteria

Every documented command runs exactly the documented scope.

### Phase A2 — Separate True Unit Tests From Laravel-Feature Tests

#### Goal

Do not boot Laravel for tests that do not need Laravel.

#### Tasks

1. Remove global Laravel `TestCase` binding from `tests/Unit`.
2. Audit all existing Unit files.
3. Categorize each Unit test:
   - true PHP unit test;
   - Laravel-container unit-like test;
   - database/model test that belongs under Feature;
   - architecture/static test.
4. Move only misclassified tests whose semantics clearly require Laravel/database integration.
5. For isolated tests temporarily living in a Laravel test context, consider Laravel 13's `#[UnitTest]` attribute where it improves clarity without hiding dependencies.
6. Preserve existing test names/history where practical.
7. Do not convert meaningful integration tests into mock-heavy unit tests.

#### Acceptance criteria

- True Unit tests execute without booting the Laravel application.
- Unit suite runtime materially improves.
- No domain behavior loses integration coverage.
- Test Guard rules remain satisfied.

### Phase A3 — Cache Safe Laravel Bootstrap Work

#### Goal

Reduce repeated application bootstrap overhead.

#### Tasks

1. Trial Laravel `WithCachedConfig` on the normal Feature suite.
2. Identify tests that intentionally mutate boot/config state and exclude them when necessary.
3. Evaluate `WithCachedRoutes` because the project has a large Filament/web route surface.
4. Benchmark each optimization independently before keeping it.
5. Verify parallel safety.

#### Acceptance criteria

- Optimization is retained only if measured runtime improves.
- Configuration/route mutation tests remain correct.
- Parallel runs remain deterministic.

### Phase A4 — Reduce Repeated Immutable Seeding

#### Goal

Stop paying repeated setup cost for reference data when test isolation does not require it.

#### High-frequency candidates discovered

- inventory permissions;
- support permissions;
- accounting permissions;
- sales permissions;
- purchasing permissions;
- CRM permissions;
- employee permissions;
- chart of accounts;
- currencies;
- notification templates;
- static statuses/reference catalogs.

#### Tasks

1. Inventory which tests require immutable reference data versus scenario data.
2. Build explicit lightweight test-reference seeders if the production seeders are unnecessarily broad.
3. Seed immutable reference data once per parallel test database/process when safe.
4. Keep scenario records local to each test via factories.
5. Never share mutable business entities across tests.
6. Verify tests that intentionally modify permissions/reference data remain isolated.
7. Prefer idempotent seeders for reference catalogs.
8. Use Laravel ParallelTesting setup hooks only where they provide a measured improvement.

#### Acceptance criteria

- Seeder invocation count drops materially.
- All tests remain order-independent.
- Parallel shards/processes remain isolated.
- No test depends on data left by another test.

### Phase A5 — Audit the Coverage-Only Test Layer

#### Goal

Keep 100% code coverage without forcing coverage-scaffolding tests into every local feedback cycle.

#### Scope

Start with `tests/Feature/Coverage` and `tests/Unit/Coverage`.

#### Classification

Every coverage-oriented test must be classified as one of:

1. **Behavioral regression**
   - catches a real business/framework integration regression;
   - move/rename to the owning domain where appropriate;
   - remains in normal fast regression.

2. **Coverage-only structural execution**
   - exists only to execute otherwise unobservable defensive branches/surfaces;
   - tag/group as `coverage-only`;
   - may be excluded from `test:fast`;
   - must remain included in `test:coverage` and full authoritative verification.

3. **Duplicate**
   - behavior is already proven by another test;
   - remove/merge only after evidence and review.

4. **Framework guarantee / low-value implementation test**
   - evaluate under Test Guard;
   - remove only when it genuinely provides no project-specific protection.

#### Rules

- Never mass-delete coverage tests.
- Every deletion/merge must preserve 100% coverage and behavioral confidence.
- Production bug regressions are sacred and may not be reclassified away from normal regression.
- Prefer datasets over near-duplicate cases.

#### Acceptance criteria

- Every coverage-only test has an explicit rationale/group.
- `test:fast` becomes faster.
- `test:coverage` remains 100%.
- No business regression coverage is lost.

### Phase A6 — Add Changed-Domain Test Selection

#### Goal

Provide a reliable pre-Pest-5 local selector for developers and agents.

#### Target command

```bash
composer test:changed
```

#### Design

Create a repository script that:

1. Reads changed paths against a deterministic Git base.
2. Maps production paths to owning test domains.
3. Escalates shared/core changes to broader suites.
4. Escalates migrations/config/composer/phpunit/Pest changes to a full fast regression.
5. Prints the selected suites before execution.
6. Never silently selects zero tests for a PHP behavior change unless the mapping explicitly declares it documentation/tooling-only.

#### Mapping examples

```text
app/Services/Sales, app/Filament/Resources/Quotations -> sales
app/Services/Inventory, inventory models/migrations -> inventory
app/Services/Purchasing -> purchasing (+ inventory when receiving/stock touched)
app/Services/Accounting -> accounting
app/Services/Payments -> payments (+ accounting/sales seam where relevant)
app/Services/Support -> support
app/Services/Employees -> employees
app/Services/Crm -> crm
app/Enums/shared permissions/module registry -> unit + architecture + fast fallback
database/migrations -> relevant domain + migration checks; unknown/shared => fast
composer.*, phpunit.xml, tests/Pest.php -> full fast
.github/workflows/tests.yml -> full fast + CI validation
```

This script is a temporary/intelligible impact selector. Pest 5 TIA may later become the preferred local engine, but the explicit domain mapping remains useful to agents for risk reasoning.

#### Acceptance criteria

- Single-domain edits select only the expected domain/seams.
- Shared infrastructure changes escalate safely.
- Selected command is visible in output.
- No use in authoritative clean CI as a replacement for full execution.

### Phase A7 — Profile and Enforce Test Performance Hygiene

#### Goal

Prevent runtime from degrading again.

#### Tasks

1. Add a documented profiling command, e.g. `composer test:profile`.
2. Track top slow tests.
3. Establish soft performance budgets by category.
4. Investigate expensive factories, seeders, filesystem operations, Filament boot/render paths, and repeated setup.
5. Use datasets to collapse repeated tests where appropriate.
6. Avoid tests of framework guarantees.
7. Keep performance metrics observational at first; only turn them into hard CI gates when stable.

#### Acceptance criteria

- Slow tests can be identified with one documented command.
- New test additions have a clear expected suite and cost.
- No hard timing gate is introduced until CI variance is measured.

### Phase A8 — Add Time-Balanced Sharding

#### Goal

Make CI duration depend on total work, not the slowest uneven shard.

#### Tasks

1. Generate timing data with Pest's `--update-shards`.
2. Commit `tests/.pest/shards.json`.
3. Keep the existing `--shard=index/total` CI shape.
4. Refresh shard timing data when:
   - test count/runtime materially changes;
   - large new modules land;
   - Pest changes its timing format;
   - shard imbalance exceeds the accepted threshold.
5. Add an explicit documented refresh command.
6. Evaluate 4 versus 6/8 CI shards based on measured cost and wall-clock improvement.

#### Acceptance criteria

- Slowest/fastest shard runtime delta target: <= 15-20% under stable CI conditions.
- No tests disappear when timing metadata is stale.
- CI remains deterministic.

### Phase A9 — Redesign CI by Responsibility

#### Goal

Make CI parallel, explicit, and easy to diagnose.

#### Target CI responsibilities

1. **Quality**
   - docs check;
   - Pint/Rector check;
   - PHPStan;
   - type coverage.

2. **Behavioral shards**
   - full behavioral suite;
   - clean checkout;
   - coverage disabled;
   - time-balanced shards.

3. **Coverage**
   - PCOV;
   - full coverage-required suites;
   - minimum 100%.

4. **Seed integrity**
   - MySQL migrate/seed;
   - relevant assertions.

5. **Critical MySQL acceptance**
   - inventory concurrency;
   - locking/race semantics;
   - other database-engine-specific invariants.

6. **Focused acceptance jobs**
   - retain only when they protect behavior not already represented by the general suite or require distinct infrastructure.

#### Optimization candidates

- cache Composer downloads;
- avoid repeated unnecessary setup;
- use matrix jobs for homogeneous shards;
- retain `cancel-in-progress`;
- keep coverage separate from behavioral shards;
- never run TIA as the normal CI pass/fail suite.

#### Acceptance criteria

- Every CI job has one clear purpose.
- Failures identify the responsible layer quickly.
- Total wall-clock time improves without lowering verification scope.

### Phase A10 — Update Canonical Testing Documentation

This phase occurs only after A1-A9 commands and CI behavior are real.

#### Files

- `Docs/onboarding/TESTING_AND_QUALITY.md`
- `Docs/onboarding/AGENT_WORKFLOW.md`
- relevant domain `TESTING.md` files where custom escalation rules are required
- `Docs/reference/COMMANDS.md` if it owns developer command reference

#### Required content

- verification tiers;
- exact Composer commands;
- domain suite list;
- critical-risk escalation;
- when coverage is required;
- when MySQL acceptance is required;
- sharding refresh process;
- profiling process;
- local versus CI distinction.

### Phase A11 — Update AI Agent Skills and Instructions

This phase occurs **after** the commands in A1-A10 are implemented and verified. Agents must never be taught commands that do not exist yet.

#### Strategy

Create a project-owned testing-selection skill rather than modifying an installed generic Pest skill as the primary customization.

Recommended canonical skill:

```text
.agents/skills/ierp-test-selection/SKILL.md
```

Mirror/expose it to Claude according to the repository's existing skill synchronization mechanism:

```text
.claude/skills/ierp-test-selection/
```

If skill synchronization is automated, use that mechanism instead of maintaining divergent copies.

#### Update these project-owned instruction surfaces

- `AGENTS.md`
- `CLAUDE.md`
- `.ai/guidelines/project/feature-development.md`
- new `ierp-test-selection` skill
- any agent workflow index that enumerates project skills

#### Do not hard-code project selection policy into generic vendor/generated skills

The existing `pest-testing` skill should continue teaching Pest syntax/features. Project-specific selection policy belongs in the IERP skill/canonical docs.

#### Skill decision algorithm

The skill must require agents to perform these steps for every code change:

1. Identify the owning domain.
2. Classify the change type.
3. Identify whether the change is critical-risk.
4. Identify cross-domain seams.
5. Run exact/focused tests during iteration.
6. Run the owning domain suite before completion.
7. Escalate to `test:fast` for shared/core/unknown-impact changes.
8. Escalate to MySQL acceptance for concurrency/locking/database-engine semantics.
9. Run full coverage only when required by the completion/CI stage, not after every edit.
10. State which suites were run in the completion report.

#### Agent examples

**Example: change `QuotationPricingService`**

```text
during iteration:
  exact quotation pricing test

before completion:
  composer test:sales

if payment/tax allocation behavior changed:
  add Payments/Accounting seam tests
```

**Example: change inventory reservation locking**

```text
during iteration:
  exact reservation/concurrency test

before completion:
  composer test:inventory
  dedicated MySQL concurrency acceptance
```

**Example: shared permission enum**

```text
during iteration:
  exact enum/policy/architecture tests

before completion:
  composer test:fast
```

**Example: composer.json / Pest.php**

```text
focused smoke checks first
then:
  composer test:fast
  composer test:coverage
  static/type gates
```

#### Acceptance criteria

- A fresh coding agent can choose the correct suite without guessing.
- Root instructions no longer imply that full coverage must run after every small edit.
- The authoritative full gate remains explicit.
- Generic Pest guidance and IERP project policy do not conflict.

## 7. Program A Completion Gate

Program A is complete only when all of the following are true:

- Composer command semantics are corrected.
- Unit/Feature classification is cleaned up.
- Safe Laravel boot caching has been benchmarked and either adopted or explicitly rejected.
- repeated immutable seeding is reduced where safe.
- coverage-only tests are classified.
- `test:changed` exists and is safe.
- profiling is documented.
- CI uses time-balanced shards.
- CI responsibilities are clear.
- 100% code coverage remains green.
- 100% type coverage remains green.
- MySQL critical acceptance remains green.
- canonical testing docs are current.
- AI agents have the new IERP test-selection skill.
- the working tree contains no accidental unrelated formatting/refactor from this program.

Only after this gate is achieved may Program B begin.

## 8. Program B — Full Package and Testing Toolchain Upgrade

### Phase B0 — Freeze a Green Pre-Upgrade Baseline

Before changing package versions:

1. Complete Program A.
2. Capture:
   - `composer test:full` result;
   - code coverage 100%;
   - type coverage 100%;
   - CI shard runtimes;
   - MySQL acceptance results.
3. Commit Program A separately.
4. Start dependency upgrades from a clean, reviewable branch/commit boundary.

### Phase B1 — Inventory Outdated Packages and Constraints

Run:

```bash
composer outdated --direct
composer show --outdated --direct
npm outdated
composer audit
npm audit
```

For blocked major versions use:

```bash
composer why-not vendor/package target-version
```

Produce a short upgrade matrix:

| Package family | Current | Target | Major? | Compatibility notes | Verification suite |
|---|---|---|---|---|---|

Do not assume “latest” from memory. Resolve versions at implementation time from Composer/NPM and official package documentation.

### Phase B2 — Upgrade Pest 4 to Pest 5 as an Isolated Change

Current official Pest 5 requirements:

- PHP 8.4+;
- PHPUnit 13;
- Pest-maintained plugins updated to their 5.x lines.

IERP already targets PHP 8.4, so the runtime prerequisite is aligned.

#### Planned dependency changes

- `pestphp/pest` -> latest compatible 5.x.
- `pestphp/pest-plugin-laravel` -> latest compatible 5.x.
- `pestphp/pest-plugin-type-coverage` -> latest compatible 5.x.
- Move from PHPUnit 12 to PHPUnit 13 as required by Pest 5.
- Evaluate removing the project's direct `phpunit/phpunit` constraint if Pest should own that dependency, unless a repository-specific reason requires it.
- Update other Pest-maintained plugins to matching 5.x.

#### Required compatibility work

1. Read Pest 5 upgrade guide.
2. Read PHPUnit 13 changelog relevant to used APIs.
3. Run:
   - Unit suite;
   - Feature suite;
   - architecture tests;
   - type coverage;
   - code coverage.
4. Fix deprecations/breaking behavior in tests rather than suppressing them.
5. Do not combine unrelated application feature changes with this upgrade.

#### Acceptance criteria

- Full Program A verification model remains green.
- Coverage stays 100%.
- No framework/test deprecation is hidden by lowering strictness.

### Phase B3 — Adopt Pest 5 TIA for Local Development

Pest 5 TIA is a local feedback accelerator, not the authoritative CI replacement.

#### Tasks

1. Enable/test local TIA:
   `vendor/bin/pest --parallel --tia`.
2. Keep TIA local-only.
3. Ensure CI full behavioral shards do not use `--tia`.
4. Document baseline invalidation behavior.
5. Decide whether to share a TIA baseline artifact from a dedicated workflow after merges to `dev`/main.
6. Add a Composer alias such as `test:tia` only after proven stable.

#### Agent policy after adoption

Agents may prefer TIA for iterative broad feedback **after** running the exact regression test, but must still use the domain/full completion tier dictated by risk.

### Phase B4 — Adopt Pest 5 Agent Tooling

Evaluate and, if compatible, install:

```bash
composer require pestphp/pest-plugin-agent --dev
```

The Agent plugin is intended for one-off verification inside the real Pest/Laravel environment.

Use it for targeted exploratory verification, not as a substitute for committed regression tests.

Then run Laravel Boost installation/update and select the Pest Agent guidelines/skill where supported:

```bash
php artisan boost:install
```

After Boost updates skills:

- re-read generated Pest skills;
- reconcile the project-owned `ierp-test-selection` skill;
- ensure project policy still overrides generic “run all tests” advice.

### Phase B5 — Adopt Pest First-Party PHPStan Integration

Evaluate:

```bash
composer require pestphp/pest-plugin-phpstan --dev
```

If the extension installer does not register it automatically, include the documented extension in PHPStan configuration.

Goals:

- PHPStan understands Pest functional API and closure `$this`;
- test code receives stronger static analysis;
- existing Larastan application analysis remains intact.

No new baseline debt is permitted.

### Phase B6 — Evaluate Browser Plugin Only if It Serves Current IERP Needs

Optional:

```bash
composer require pestphp/pest-plugin-browser --dev
npm install playwright@latest
npx playwright install
```

Adopt only if browser-level regression adds value beyond existing Filament tests and available project browser tooling.

Do not install it merely because it is new.

If adopted:

- create a small high-value browser smoke suite;
- avoid duplicating every Feature test in a browser;
- keep browser tests in their own CI responsibility when runtime warrants it.

### Phase B7 — Upgrade Remaining Composer Packages in Controlled Families

Do not run one uncontrolled major update and then debug the entire application at once.

Recommended batches:

1. Laravel first-party ecosystem.
2. Filament / Livewire ecosystem.
3. Spatie packages.
4. Stripe and external integration SDKs.
5. Static-analysis/refactoring/formatting tooling.
6. Documentation/development tooling.
7. Remaining direct dependencies.

For each batch:

1. inspect official upgrade guide/changelog;
2. update constraints;
3. use Composer with appropriate dependency resolution, preferring minimal unrelated changes where possible;
4. run focused tests;
5. run affected domain suites;
6. run static analysis;
7. run the full verification tier before accepting a major-family batch.

Use `composer why-not` rather than forcing incompatible dependency graphs.

### Phase B8 — Upgrade Frontend Packages

Inventory with `npm outdated`.

Current major frontend families include:

- Vite;
- Laravel Vite plugin;
- Tailwind CSS / Tailwind Vite plugin;
- concurrently;
- Leaflet.

Upgrade in dependency-compatible batches.

Verification:

```bash
npm ci
npm run build
```

Then run relevant Filament/render/UI tests and the repository's full gate if generated assets or Blade behavior can be affected.

### Phase B9 — Refresh Generated Skills and Documentation After Package Upgrades

Because installed-version behavior changed:

1. refresh Laravel Boost/project skills through the supported generator;
2. verify `.agents/skills/pest-testing` reflects Pest 5;
3. verify Claude-exposed skills are synchronized;
4. update `AGENTS.md` stack versions;
5. update `CLAUDE.md` if new Boost/Pest commands are expected;
6. update `Docs/onboarding/TESTING_AND_QUALITY.md`;
7. update the project-owned `ierp-test-selection` skill for:
   - Pest 5 TIA;
   - Agent plugin;
   - time-balanced sharding;
   - PHPUnit 13;
   - any new browser/static-analysis tooling actually adopted.

Do not leave Pest 4 instructions after Pest 5 is installed.

### Phase B10 — Final Full Verification

Mandatory final checks:

```text
composer install from lock on clean checkout
npm ci
npm run build
composer test:full
100% type coverage
100% code coverage
all behavioral CI shards
MySQL seed integrity
MySQL concurrency/acceptance
composer audit
npm audit
```

Review generated lockfile changes and confirm no package was accidentally downgraded or replaced.

## 9. Expected Files Changed Across the Program

Program A likely touches:

- `composer.json`
- `tests/Pest.php`
- `phpunit.xml`
- `.github/scripts/run-coverage.php`
- new/updated test orchestration scripts under `scripts/`
- `.github/workflows/tests.yml`
- `tests/.pest/shards.json`
- selected test files moved/reclassified during Unit/Coverage audit
- `Docs/onboarding/TESTING_AND_QUALITY.md`
- `Docs/onboarding/AGENT_WORKFLOW.md`
- `Docs/reference/COMMANDS.md`
- `AGENTS.md`
- `CLAUDE.md`
- `.ai/guidelines/project/feature-development.md`
- new `.agents/skills/ierp-test-selection/SKILL.md`
- Claude skill exposure/synchronization path

Program B additionally touches:

- `composer.json`
- `composer.lock`
- `package.json`
- `package-lock.json`
- PHPStan configuration if Pest's plugin is installed
- generated Boost/Pest AI skills
- testing/CI config needed for Pest 5
- source/tests only where package upgrade compatibility requires changes

## 10. Implementation Commit Boundaries

Recommended commit sequence:

1. `test: capture baseline and normalize test commands`
2. `test: separate true unit suite`
3. `test: optimize Laravel bootstrap and reference seeding`
4. `test: classify coverage-only regressions`
5. `test: add changed-domain selection and profiling`
6. `ci: add time-balanced test sharding`
7. `docs: publish verification tiers and agent selection policy`
8. `chore(ai): add IERP test-selection skill`
9. **Program A green checkpoint**
10. `chore(deps): upgrade Pest 5 and PHPUnit 13`
11. `test: adopt Pest 5 TIA and agent/static-analysis tooling`
12. `chore(deps): update Laravel/Filament ecosystem`
13. `chore(deps): update remaining Composer packages`
14. `chore(deps): update frontend packages`
15. `chore(ai): refresh Boost/Pest skills for upgraded toolchain`
16. **Program B final green checkpoint**

Keep commits reviewable; do not force these exact commit names if the implementation naturally needs finer splits.

## 11. Performance Success Criteria

Exact values should be finalized from Phase A0 measurements, but target outcomes are:

- exact-test feedback: seconds, not minutes;
- single-domain completion check: substantially below full suite time;
- `test:fast`: materially faster than the current full verification command;
- coverage generation removed from ordinary edit/test loops;
- CI shards balanced to within ~15-20% runtime under stable load;
- full coverage remains 100%;
- type coverage remains 100%;
- no increase in flaky/race-dependent tests;
- no loss of MySQL-specific concurrency verification.

A performance optimization that makes tests flaky is rejected even if it is faster.

## 12. Rollback Strategy

Each major phase must be independently reversible.

- If cached config/routes cause semantic leaks, remove that optimization while keeping command-tier improvements.
- If seed-once setup creates order dependence, revert that setup and optimize narrower seeders instead.
- If coverage-only grouping hides meaningful regression behavior, move those tests back into normal regression.
- If time-balanced shard metadata causes issues, CI can fall back to ordinary sharding without changing tests.
- If Pest 5 upgrade breaks the suite beyond a bounded migration, revert only Program B package commits; Program A remains valid on Pest 4.
- Never solve an upgrade failure by lowering coverage/static-analysis thresholds.

## 13. Research Basis / Version-Specific Notes

Implementation should re-check official documentation at execution time.

Relevant current references at plan creation:

- Pest 5 release / features: https://pestphp.com/docs/pest5-now-available
- Pest upgrade guide: https://pestphp.com/docs/upgrade-guide
- Pest TIA: https://pestphp.com/docs/tia
- Pest optimizing tests / sharding: https://pestphp.com/docs/optimizing-tests
- Pest Agent plugin: https://pestphp.com/docs/agent
- Pest type coverage: https://pestphp.com/docs/type-coverage
- Laravel testing / testing attributes and caches: https://laravel.com/docs/13.x/testing
- Composer CLI / outdated / update controls: https://getcomposer.org/doc/03-cli.md

Pest 5 was released in 2026 and requires PHP 8.4+ and PHPUnit 13. IERP's current PHP 8.4 baseline satisfies the PHP requirement, but the upgrade remains a dedicated Program B change.

## 14. Definition of Done

This plan is fully complete when:

1. Developers and AI agents no longer run thousands of tests after every small edit by default.
2. Focused tests are selected predictably from domain/risk.
3. Full clean CI still executes the complete behavioral suite.
4. 100% code and type coverage gates remain authoritative.
5. CI is time-balanced and faster.
6. Unit tests do not boot Laravel unless they actually require it.
7. repeated immutable seeding and unnecessary bootstrap work are reduced.
8. coverage-only tests are explicit rather than silently mixed into the normal developer loop.
9. the IERP-specific agent skill teaches exact test-selection rules.
10. all direct Composer/NPM packages have been reviewed and upgraded to the latest compatible versions in controlled batches.
11. Pest 5 / PHPUnit 13 and adopted Pest 5 tooling are fully integrated.
12. generated and project-owned AI testing skills reflect the final installed toolchain.
13. the final clean full verification is green.
14. durable behavior has been merged into canonical docs and this temporary active plan can be removed.
