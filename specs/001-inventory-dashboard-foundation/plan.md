# Implementation Plan: Inventory Dashboard Foundation & Guardrails

**Branch**: `001-inventory-dashboard-foundation` (work branch: `feature/filament-inventory-dashboard`) | **Date**: 2026-07-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-inventory-dashboard-foundation/spec.md`

## Summary

Establish the admin-panel rails for the inventory dashboard **before any inventory screen exists**: (1) restrict panel access to System Administrators, (2) define and seed the granular `inventory.*` permission catalogue plus a reusable authorization/policy pattern, (3) provide a thin action→domain-service adapter so every stock-changing action delegates to a service and surfaces domain errors as notifications, and (4) install an architecture test that fails the build if any Filament class writes stock or movement records directly. The technical approach reuses the already-committed Filament v5 panel scaffolding (`AdminPanelServiceProvider`, `AdminModuleRegistry`, `ModulePlaceholder`) and Spatie Laravel Permission; it adds no user-facing Resource and no business logic.

## Technical Context

**Language/Version**: PHP 8.4 (composer requires `^8.4`)

**Primary Dependencies**: Laravel 13 (`^13.8`); **Filament v5** (`~5.0`, currently 5.7 — see research.md R1; prior "v4" doc references have been corrected); `spatie/laravel-permission ^8.3`; `spatie/laravel-data ^4.23` (validation reuse); `spatie/laravel-medialibrary ^11` (not used in FI-0)

**Storage**: Relational DB; engine unconfirmed (MySQL vs PostgreSQL — PRD §11 / Open Question #6). Local test DB is SQLite (`database/database.sqlite`). Permission tables and users table already migrated. Migrations added here MUST be engine-agnostic.

**Testing**: Pest v4 (Feature + Unit + `arch()` presets in `tests/Unit/ArchTest.php`); Larastan v3 (PHPStan); Pint; Rector. CI gate `composer test` enforces **100% type coverage and 100% code coverage** — new code must be fully typed and covered.

**Target Platform**: Laravel web application; Filament admin panel served at `/admin` with **session authentication** (already wired), distinct from the token API.

**Project Type**: Single Laravel application (API + Filament admin surface in one codebase).

**Performance Goals**: No feature-specific throughput target. Hard rule: **stock balances MUST NOT be cached** (SYSTEM_ARCHITECTURE §9); FI-0 introduces no caching.

**Constraints**: No hard-delete capability on inventory ledgers; authorization via Spatie only (no custom ACL); engine-agnostic migration; must not break the existing `DashboardPageTest`; must keep `composer test` (incl. 100% coverage) green.

**Scale/Scope**: Foundation only — **0 user-facing resources**. Deliverables: 1 enum, 1 migration, `User` model edits, 1 Filament action-adapter concern, 1 base policy pattern, 1 permission seeder, factory/seeder updates, `lang/en` keys, and tests (panel access, permission seeding, adapter behavior, architecture guard).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Status |
|---|---|---|
| I. Specification-First | Work derived from approved spec + plan doc; no code ahead of spec | ✅ PASS — derived from spec.md and `FILAMENT_INVENTORY_DASHBOARD_PLAN.md` §3 (FI-0) |
| II. Domain-Driven Modular Monolith | Thin controllers; business rules in services; no unrelated refactors | ✅ PASS — FI-0 adds presentation rails only; **zero** business logic; edits to `User`/factory/tests are directly required by the gate, not unrelated |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | Every stock change goes through a service that writes a movement | ✅ PASS — FI-0 *is* the mechanism: action adapter forces service delegation; arch test forbids direct stock/movement writes from Filament |
| IV. Unified Access, Media & Payment | Spatie permission; `users.user_type` identifies channel; no custom ACL | ✅ PASS — gate uses `user_type` + Spatie permissions; no bespoke authorization |
| V. AI Isolation & Human Oversight | N/A to FI-0 | ✅ N/A |
| VI. Engineering Discipline | Tests for every rule; audit for sensitive actions; report changes | ✅ PASS — every rail ships with a test; audit is delegated to services (realized when services exist) |

**Gate result: PASS.** One coordination decision is recorded in Complexity Tracking (introducing a minimal `user_type` primitive ahead of the dedicated auth spec).

**Post-design re-check (after Phase 1)**: Still PASS. The design (enum + minimal column, permission source + idempotent seeder, policy trait, action-adapter concern, arch guard) adds no business logic, no bespoke ACL, no caching, and no new dependency. It strengthens Principle III rather than risking it. No new entries needed in Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/001-inventory-dashboard-foundation/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (permission catalogue, panel-access, policy abilities, adapter contract)
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── UserType.php                                  # NEW — Admin | Customer | Employee (backed string enum)
│   └── InventoryPermission.php                       # NEW — canonical enum of inventory.* permission strings (single source for seeder + policies); lives here, not app/Support, per the repo's Pest arch() Laravel preset (all enums under App\Enums)
├── Models/
│   └── User.php                                      # EDIT — user_type cast + fillable; canAccessPanel() gates 'admin' panel on UserType::Admin
├── Filament/
│   ├── AdminModuleRegistry.php                       # UNCHANGED (naming authority)
│   ├── Pages/ (Dashboard, ModulePlaceholder)         # UNCHANGED
│   └── Concerns/
│       └── InteractsWithInventoryServices.php        # NEW — action→service adapter: run operation, translate DomainException/ValidationException → Filament Notification, no partial writes
├── Policies/
│   └── Concerns/
│       └── ChecksInventoryPermissions.php            # NEW — reusable policy base mapping abilities → inventory.* permissions (used by FI-1+ resource policies)
└── Providers/Filament/AdminPanelServiceProvider.php  # UNCHANGED (gate lives on the User model per Filament v5 convention)

database/
├── migrations/
│   └── 2026_xx_xx_add_user_type_to_users_table.php   # NEW — engine-agnostic string column, NOT NULL, least-privilege default 'customer' (see research.md R3)
├── seeders/
│   ├── InventoryPermissionSeeder.php                 # NEW — seeds inventory.* permissions (idempotent)
│   └── DatabaseSeeder.php                             # EDIT — seed an explicit System Admin + call permission seeder
└── factories/
    └── UserFactory.php                               # EDIT — definition() default user_type=Admin (test convenience) + admin()/customer()/employee() states

lang/
└── en/admin.php                                      # EDIT — inventory permission labels + adapter notification keys
                                                       # (lang/ar restoration tracked separately — Open Question #8, not FI-0 scope)

tests/
├── Feature/Filament/
│   ├── PanelAccessTest.php                            # NEW — admin allowed; customer/employee/guest denied
│   └── InventoryPermissionSeederTest.php             # NEW — full catalogue seeded, idempotent, grantable to a role
├── Unit/
│   ├── InteractsWithInventoryServicesTest.php        # NEW — success notification; DomainException → error notification, no writes
│   └── ArchTest.php                                  # EDIT — add guardrail: App\Filament must not use inventory write models
└── Feature/Filament/DashboardPageTest.php            # UNCHANGED — factory default (Admin) keeps these passing (see research.md R8)
```

**Structure Decision**: Single Laravel app. FI-0 lives in `app/Enums`, `app/Filament/Concerns`, `app/Policies/Concerns`, `database/`, `lang/en`, and `tests/`. `InventoryPermission` lives in `app/Enums` (not a separate `app/Support` namespace) per the repository's Pest `arch()` Laravel preset, which requires every enum to live under `App\Enums`. It reuses (does not modify) the committed Filament v5 registry/panel scaffolding; the placeholder-to-resource mechanism means no wiring is needed for future resources (registry §1.2).

## Requirement coverage at FI-0

FI-0 is the foundation; some spec requirements are **fully** realized now and others are **structurally established** and completed as resources arrive (FI-1+):

| Spec item | FI-0 outcome |
|---|---|
| US1 / FR-001, FR-002, SC-001 | **Fully realized** — panel access gate on `UserType::Admin`. |
| US2 / FR-003, FR-010 | **Fully realized** — full `inventory.*` catalogue defined + seeded + grantable. |
| US2 / FR-004, FR-005, SC-002 | **Structurally established** — reusable policy pattern + `canAccessPanel` permission check; per-resource hide/403 realized when each resource ships (FI-1+). |
| US3 / FR-006, SC-004 | **Fully realized** — architecture test forbids Filament direct writes to stock/movement models (forward-active as models appear). |
| US3 / FR-007 | **Fully realized** — action adapter translates domain/validation errors to notifications with no partial writes (unit-tested with a fake operation). |
| US3 / FR-008, FR-009, SC-003, SC-005 | **Structurally established** — adapter + policy pattern; audit + real movement/error paths realized when domain services exist (dependency, Open Question #11). |
| FR-011 | **Fully realized** — no hard-delete capability introduced; guard documented as a baseline. |
| FR-012 | **Fully realized** — enum, adapter concern, policy base, permission source are the reusable building blocks. |

## Complexity Tracking

| Violation / Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| FI-0 introduces `UserType` enum + `user_type` column ahead of the dedicated auth spec (`003-auth-users-spatie-access` in the constitution's extraction map) | The panel access gate (FR-001/FR-002) cannot exist without a way to identify System Admins; the constitution itself mandates `users.user_type` as the channel identifier, and the committed `User` model comment explicitly anticipates this gate being "layered on top once user roles are implemented" | Waiting for the full auth spec would block the entire inventory dashboard track indefinitely; the primitive is minimal (one enum + one column + cast) and is designed to be **extended, not replaced**, by the auth spec |
