---
description: "Task list for Inventory Dashboard Foundation & Guardrails (FI-0)"
---

# Tasks: Inventory Dashboard Foundation & Guardrails

**Input**: Design documents from `specs/001-inventory-dashboard-foundation/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/)

**Tests**: INCLUDED — mandated by the constitution (Principle VI), spec SC-004 (architecture test), and the `composer test` gate (100% type + code coverage). Write test tasks before their implementation and confirm they fail first.

**Organization**: Tasks are grouped by user story (US1 P1, US2 P2, US3 P3) so each is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 / US2 / US3 for story-phase tasks; Setup/Foundational/Polish carry no story label

## Path Conventions

Single Laravel application. Source under `app/`, migrations/seeders/factories under `database/`, translations under `lang/`, tests under `tests/` (Pest). Paths below are repo-relative.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a clean starting point (project is already scaffolded — Filament v5 panel, Spatie permission tables, and permission migration are committed).

- [X] T001 Confirm a green baseline before changes: run `php artisan test --compact tests/Feature/Filament tests/Unit` and record that existing tests pass (no files changed in this task)

**Checkpoint**: Baseline green — foundational work can begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Introduce the `user_type` identity primitive every story relies on (gate + factory states). No user story can begin until this phase is complete.

**⚠️ CRITICAL**: Blocks US1, US2, and US3.

- [X] T002 [P] Create backed string enum `App\Enums\UserType` with cases `Admin='admin'`, `Customer='customer'`, `Employee='employee'` in `app/Enums/UserType.php`
- [X] T003 Create engine-agnostic migration adding a NOT NULL `user_type` string column with least-privilege default `'customer'` to the `users` table in `database/migrations/2026_07_22_000001_add_user_type_to_users_table.php` (see research.md R3, R10)
- [X] T004 Edit `app/Models/User.php`: add `'user_type'` to `#[Fillable]`, cast `user_type` to `UserType` in `casts()`, and add an `isAdmin(): bool` helper (depends on T002, T003)
- [X] T005 Edit `database/factories/UserFactory.php`: set `definition()` default `user_type => UserType::Admin` (test convenience, research R8) and add `admin()`, `customer()`, `employee()` states (depends on T002)

**Checkpoint**: `user_type` exists, is cast, and factory states are available — user stories can proceed.

---

## Phase 3: User Story 1 - Only authorized administrators can enter the dashboard (Priority: P1) 🎯 MVP

**Goal**: Restrict the `/admin` panel so only System Administrators can enter; deny customers, employees, and guests.

**Independent Test**: Sign in as each `user_type` (and as a guest) and confirm only `admin` reaches `/admin`; others are denied/redirected.

### Tests for User Story 1 ⚠️ (write first, confirm they fail)

- [X] T006 [P] [US1] Create `tests/Feature/Filament/PanelAccessTest.php` asserting: admin → HTTP 200 on `/admin`; customer → denied (403/no access); employee → denied; guest → 302 redirect to `/admin/login` (matches [contracts/panel-access.md](contracts/panel-access.md)). Before T007 the customer/employee cases MUST fail (gate not yet applied).

### Implementation for User Story 1

- [X] T007 [US1] Implement the gate in `app/Models/User.php`: `canAccessPanel(Panel $panel)` returns true for panel id `admin` only when `$this->isAdmin()` — replaces the current `return true`. **Hardened after code review**: unknown/future panel ids now fail closed (`default => false`) rather than the initial permissive `true`, per research.md R2. (depends on T004, T006)

**Checkpoint**: US1 fully functional — `PanelAccessTest` and the existing `DashboardPageTest` both pass (the latter unchanged; its factory users default to `Admin`).

---

## Phase 4: User Story 2 - Administrators see and reach only what their permissions allow (Priority: P2)

**Goal**: Define and seed the granular `inventory.*` permission catalogue and provide the reusable ability→permission policy pattern that FI-1+ resources will apply.

**Independent Test**: Seed permissions, grant a subset to a role, assign to a user, and confirm `can()` reflects exactly that subset; confirm the policy trait grants/denies per its mapped permission.

### Tests for User Story 2 ⚠️ (write first, confirm they fail)

- [X] T008 [P] [US2] Create canonical permission source `App\Enums\InventoryPermission` listing the 13 `inventory.*` strings from [contracts/permissions.md](contracts/permissions.md) plus a method returning all names, in `app/Enums/InventoryPermission.php` (relocated from the originally planned `app/Support/Inventory` — the repo's Pest `arch()` Laravel preset requires all enums under `App\Enums`; see research.md R4)
- [X] T008A [US2] **(scope addition, discovered during implementation)** Add `Spatie\Permission\Traits\HasRoles` to `app/Models/User.php` — `$user->can()` and role/permission assignment (needed by T009, T010, T011, T013) do not resolve without it; this was omitted from plan.md's file-change list and is a prerequisite gap, not new business scope
- [X] T009 [P] [US2] Create `tests/Feature/Filament/InventoryPermissionSeederTest.php`: full catalogue exists on guard `web` after seeding; running the seeder twice creates no duplicates; a role granted a subset yields exactly that subset via `$user->can()` (depends on T008)
- [X] T010 [P] [US2] Create `tests/Unit/ChecksInventoryPermissionsTest.php`: the policy trait resolves each ability to its mapped permission and grants/denies based on `$user->can()` for a user with and without that permission (matches [contracts/policy-abilities.md](contracts/policy-abilities.md)) (depends on T008)

### Implementation for User Story 2

- [X] T011 [US2] Create idempotent `Database\Seeders\InventoryPermissionSeeder` using Spatie `Permission::firstOrCreate` per name on guard `web`, sourced from `InventoryPermission`, in `database/seeders/InventoryPermissionSeeder.php` (makes T009 pass; depends on T008)
- [X] T012 [US2] Edit `database/seeders/DatabaseSeeder.php`: create an explicit System Admin user (`user_type => UserType::Admin`) and invoke `InventoryPermissionSeeder` (depends on T005, T011)
- [X] T012A [US2] **(scope addition)** Create `tests/Feature/DatabaseSeederTest.php` asserting `$this->seed()` produces the explicit admin user and the full permission catalogue — `DatabaseSeeder` was otherwise unverified by any test
- [X] T013 [US2] Create policy trait `App\Policies\Concerns\ChecksInventoryPermissions` mapping abilities to `inventory.*` permission checks via `$user->can()`, using an explicit ability→permission map supplied by each consuming policy (see [research.md](research.md) R5 for why per-resource string-interpolation was rejected — the catalogue is not uniform across resources), in `app/Policies/Concerns/ChecksInventoryPermissions.php` (makes T010 pass; depends on T008)

**Checkpoint**: US2 functional — catalogue seeds idempotently, is grantable, and the reusable policy pattern is proven (per-resource navigation-hide/403 is realized when FI-1+ resources ship).

---

## Phase 5: User Story 3 - Every stock change is forced through trusted logic and is auditable (Priority: P3)

**Goal**: Provide the thin action→domain-service adapter (translate outcomes to notifications, no partial writes) and the architecture guard that forbids any Filament class from writing stock/movement records directly.

**Independent Test**: Drive the adapter with a fake operation (success and throwing `DomainException`) and assert notifications/no-writes; run the architecture test and confirm it fails the build if a Filament class references the inventory write models.

### Implementation prerequisites for User Story 3

- [X] T014 [US3] Edit `lang/en/admin.php`: add inventory adapter notification keys (`admin.inventory.notifications.success` / `.error`) used by the adapter's notification titles

### Tests for User Story 3 ⚠️ (write first, confirm they fail)

- [X] T015 [P] [US3] Create `tests/Unit/InteractsWithInventoryServicesTest.php` using `Notification::fake()`: success closure → success notification; closure throwing `DomainException` → danger notification with the message and zero model writes; closure throwing `ValidationException` → danger notification (matches [contracts/action-adapter.md](contracts/action-adapter.md))

### Implementation for User Story 3

- [X] T016 [US3] Create trait `App\Filament\Concerns\InteractsWithInventoryServices` with `runInventoryOperation(callable $operation, string $successMessageKey): void` that invokes the operation, catches `DomainException` and `Illuminate\Validation\ValidationException`, and sends success/danger Filament `Notification`s — performing no writes itself, in `app/Filament/Concerns/InteractsWithInventoryServices.php` (makes T015 pass; depends on T014)
- [X] T017 [US3] Extend `tests/Unit/ArchTest.php` with an `arch()` expectation that `App\Filament` does NOT use `App\Models\InventoryStock` or `App\Models\InventoryMovement` (SC-004 guard; verified to actively fail the build via a temporary probe — see research.md R7)

**Checkpoint**: US3 functional — the adapter surfaces domain/validation errors with no partial writes, and the build fails on any direct Filament stock/movement write.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Formatting, static analysis, and full gate validation across all stories.

- [X] T018 [P] Run `vendor/bin/pint --dirty --format agent` and resolve style issues on all changed PHP files. Also ran `vendor/bin/rector process` (part of the `composer test:lint` gate) which applied 2 safe fixes (`readonly` anonymous class, explicit arrow-function return type) to the new test files; re-verified with Pint + the full suite afterward.
- [X] T019 Run `composer test:types` (PHPStan/Larastan) and `composer test:type-coverage` (100%); fix findings without adding baseline entries. **Result**: both pass with **0 errors / 100%**. `test:types` fixed one real bug (`phpstan.neon` was missing `parseModelCastsMethod: true`, causing Larastan to mistype `casts()`-declared attributes) and resolved 2 `trait.unused` findings on `InteractsWithInventoryServices`/`ChecksInventoryPermissions` by adding their existing FI-0 unit-test fixtures (which already `use` each trait) to `phpstan.neon`'s analysed `paths`, then properly retyping those two test files to satisfy `max` level (array/interface typing, switching to Filament's `Notification::assertNotified()` testing helper instead of raw `session()` array access). An abstract-class conversion was tried first, found to violate the project's Pest arch Strict preset (no abstract classes/protected methods anywhere in `App\*`), and reverted. Full detail in research.md R11.
- [X] T020 Run the full CI gate `composer test` (lint, static analysis, 100% type coverage, unit, 100% code coverage) and ensure it is green. **Result**: fully green end-to-end — pint ✅, rector ✅, phpstan ✅ (0 errors), type coverage ✅ (100%), unit tests ✅ (62/62), code coverage ✅ (100%).
- [X] T021 Execute [quickstart.md](quickstart.md) validation scenarios (panel access, permission seeding, adapter behavior, architecture guard) and confirm each passes — all confirmed, including a live-fire proof of the architecture guard (temporary probe class added and removed; guard failed the build with the probe present, passed once removed).
- [X] T022 **(code-review follow-up)** Applied two findings from an independent code review: (1) documented, in `phpstan.neon`, why the two test files are listed in `paths` and when to remove them; (2) hardened `User::canAccessPanel()` to fail closed for unknown/future panel ids (`default => false`) instead of the initial permissive `true` — see research.md R2. Updated `UserTest`, `contracts/panel-access.md`, and `research.md` accordingly; re-ran the full gate.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none — start immediately.
- **Foundational (Phase 2)**: after Setup — BLOCKS all user stories.
- **User Stories (Phases 3–5)**: after Foundational. US1, US2, US3 are otherwise independent and may proceed in parallel (US3 in particular touches no US1/US2 files).
- **Polish (Phase 6)**: after all targeted stories complete.

### User Story Dependencies

- **US1 (P1)**: needs Foundational (T004 `isAdmin`, T005 factory states). No dependency on US2/US3.
- **US2 (P2)**: needs Foundational (admin factory for tests). No dependency on US1/US3.
- **US3 (P3)**: needs Foundational only nominally; the adapter/arch guard share no files with US1/US2.

### Within Each Story

- Write tests first and confirm they fail, then implement (Pest).
- Source/data classes before the seeder/trait/services that consume them.

### Parallel Opportunities

- **Foundational**: T002 [P] (enum) alongside starting T003 (migration); T004/T005 follow T002.
- **US1**: T006 [P] (test) authored while foundational settles; T007 follows.
- **US2**: T008 [P] then T009 [P] + T010 [P] (both tests) in parallel; T011/T013 (impl) in parallel after T008; T012 after T011.
- **US3**: T015 [P] after T014; T016 then; T017 independent.
- **Cross-story**: after Foundational, one developer can take US1, another US2, another US3 — minimal file overlap (only `app/Models/User.php` is shared, and only within Foundational+US1).

---

## Parallel Example: User Story 2

```bash
# After T008 (InventoryPermission source) exists, author both test files together:
Task: "Create tests/Feature/Filament/InventoryPermissionSeederTest.php"   # T009
Task: "Create tests/Unit/ChecksInventoryPermissionsTest.php"              # T010

# Then implement the two consumers in parallel (different files):
Task: "Create database/seeders/InventoryPermissionSeeder.php"            # T011
Task: "Create app/Policies/Concerns/ChecksInventoryPermissions.php"      # T013
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Phase 1 (Setup) → Phase 2 (Foundational) → Phase 3 (US1).
2. **STOP and VALIDATE**: `PanelAccessTest` green; non-admins/guests denied; admins allowed. This is a shippable security increment.

### Incremental Delivery

1. Setup + Foundational → identity primitive ready.
2. US1 → panel gate (MVP, demo).
3. US2 → permission catalogue + policy pattern (demo grant/deny).
4. US3 → service-delegation guardrail + arch guard (demo error handling + build guard).
5. Polish → full `composer test` green.

---

## Notes

- [P] = different files, no dependency on incomplete tasks.
- `app/Models/User.php` is edited in both Foundational (T004) and US1 (T007) — these are sequential, not parallel.
- Structurally-established requirements (per-resource 403/navigation-hide, real movement + audit paths) complete in FI-1+ once the inventory domain services and models exist (Open Question #11) — out of scope here by design.
- `lang/ar/admin.php` restoration is cross-cutting (Open Question #8) and intentionally excluded from FI-0.
- Commit after each task or logical group; keep the change small and reviewable.
