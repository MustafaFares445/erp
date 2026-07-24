# Implementation Plan: Stock Adjustments

**Branch**: `003-stock-adjustments` (work branch: `feature/filament-inventory-dashboard`) | **Date**: 2026-07-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-stock-adjustments/spec.md`

## Summary

Deliver **FI-3 — Stock Adjustments**, the first *stock-changing* screen on the FI-0 rails. An `AdjustmentResource` lets a permitted administrator prepare a **draft** adjustment (a warehouse, a required reason, and one line per product variant giving the newly counted quantity) that touches nothing, then **confirm** it. Confirmation is the single stock-mutating act: a new `InventoryAdjustmentService::confirm()` runs one DB transaction that, per item, writes an `inventory_movements` row (`movement_type = adjustment`, signed difference), updates the `inventory_stocks` balance by exactly that difference, and writes one `audit_logs` record — all-or-nothing. The Filament action is a thin adapter over the service via the FI-0 `InteractsWithInventoryServices` concern; it computes no balances and writes no ledger rows itself (the FI-0 arch guard already forbids `App\Filament` from touching `InventoryStock`/`InventoryMovement`, so delegation is enforced for free).

Per the project owner's decision recorded for spec 002 (and continued here in Complexity Tracking), this feature is **self-contained**: it creates the ERD-faithful backing tables it needs (`inventory_adjustments`, `inventory_adjustment_items`, and the previously-absent `audit_logs`), their models/factories, the adjustment domain service, the shared audit-log writer, an `InventoryAdjustmentPolicy` delegating to the FI-0 `ChecksInventoryPermissions` trait, and the Filament resource — without pulling in FI-4 transfers, FI-5 reservations/returns, or FI-6 widgets/exports. The `inventory.adjustment.view|create|confirm` permissions already exist in `App\Enums\InventoryPermission` and are seeded by `InventoryPermissionSeeder` (FI-0), and `AdminModuleRegistry` already imports `AdjustmentResource`, so the sidebar link activates automatically when the class exists (no registry edit).

## Technical Context

**Language/Version**: PHP 8.4 (composer requires `^8.3`).

**Primary Dependencies**: Laravel 13 (`^13.8`); **Filament v5** (`~5.0`, currently 5.7); `spatie/laravel-permission ^8.3` (authorization); `spatie/laravel-data ^4.23` (validation reuse for adjustment rules). No new dependencies.

**Storage**: Relational DB; engine unconfirmed (MySQL vs PostgreSQL — Open Question #6). Local/CI test DB is SQLite. All new migrations MUST be engine-agnostic (Blueprint methods only, `decimal(15,3)` for quantities per ERD §6, `string` columns for status/number, `json` columns for audit old/new values, no native enum columns).

**Testing**: Pest v4 (Feature + Unit + `arch()` in `tests/Unit/ArchTest.php`); Larastan v3 at level `max` with `parseModelCastsMethod: true`; Pint; Rector. CI gate `composer test` enforces **100% type coverage and 100% code coverage** — every new file must be fully typed and covered.

**Target Platform**: Laravel web app; Filament admin panel at `/admin` with session auth (FI-0). `AdjustmentResource` auto-discovers into the committed `inventory` navigation group; the registry already references it, so **no registry edit** is needed (research R2).

**Project Type**: Single Laravel application (API + Filament admin surface in one codebase).

**Performance Goals**: No feature-specific throughput target. Hard rule: **stock balances MUST NOT be cached** (SYSTEM_ARCHITECTURE §9) — the confirm path reads the live `inventory_stocks` row (with a row lock) and the draft form reads current on-hand directly.

**Constraints**: Every stock change goes through the service and creates a movement (constitution Principle III; FR-009/FR-010); confirmation runs in a single DB transaction so it is all-or-nothing (FR-011); confirmed adjustments are immutable and cannot be re-applied (FR-016/FR-017); drafts are soft-deleted, never hard-deleted (FR-018); prepare vs apply are gated by distinct permissions (FR-020/FR-021); authorization reuses the FI-0 policy trait (no forked ACL); confirmation writes an audit record (FR-013). Must keep `composer test` (incl. 100% coverage) green and must not break the FI-0/FI-1/FI-2 suites.

**Scale/Scope**: 1 Filament resource (`AdjustmentResource`) + 4 pages + form/infolist schemas + adjustments table + 1 relation manager (items); 3 new models (`InventoryAdjustment`, `InventoryAdjustmentItem`, `AuditLog`); 1 status enum (`AdjustmentStatus`); 3 migrations (`inventory_adjustments`, `inventory_adjustment_items`, `audit_logs`); 1 domain service (`InventoryAdjustmentService`) + 1 shared audit writer (`AuditLogger`); 1 validation Data object (`AdjustmentData`); 1 policy (`InventoryAdjustmentPolicy`); factories; `lang/en/admin.php` keys; feature + unit tests. **No** transfers, reservations, returns, widgets, exports, or CSV import.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Status |
|---|---|---|
| I. Specification-First | Work derived from approved spec + plan doc; DB design finalized before coding | ✅ PASS — derived from `spec.md` and `FILAMENT_INVENTORY_DASHBOARD_PLAN.md` §6; `inventory_adjustments`, `inventory_adjustment_items`, and `audit_logs` shapes taken verbatim from ERD §6 (design finalized there). |
| II. Domain-Driven Modular Monolith | Thin controllers; business rules in services; no unrelated refactors | ✅ PASS — no controllers (Filament); **all** stock logic lives in `App\Services\Inventory\InventoryAdjustmentService`; the Filament action is a thin adapter via `InteractsWithInventoryServices`. Only inventory-module code is touched. |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | Every stock change creates a movement through a service; source of truth `product_variant_id + warehouse_id`; confirmed docs not deleted; operations in transactions | ✅ PASS — confirmation is the sole writer; per item it creates a movement AND updates the `(variant, warehouse)` balance inside one transaction; confirmed adjustments are immutable and never hard-deleted; the FI-0 arch guard forbids Filament from writing the ledger, forcing delegation. |
| IV. Unified Access, Media & Payment | Spatie permission; `user_type` channel; no custom ACL | ✅ PASS — `InventoryAdjustmentPolicy` delegates to `ChecksInventoryPermissions`, mapping abilities to the already-seeded `inventory.adjustment.view/create/confirm`; `confirm` is a distinct permission (FR-020). No bespoke authorization. |
| V. AI Isolation & Human Oversight | N/A | ✅ N/A — no AI in this feature. |
| VI. Engineering Discipline | Tests per rule; audit sensitive actions; report changes; no unrelated refactors | ✅ PASS — every behavior ships with a Pest test; confirmation (a sensitive stock-changing action) writes an `audit_logs` record via the shared `AuditLogger`; changes reported per task. |

**Gate result: PASS.** Two coordination decisions (this feature creating the `inventory_adjustments`/`inventory_adjustment_items` tables ahead of backend spec 005, and introducing the `audit_logs` table + shared audit writer here as the first sensitive action) are recorded in Complexity Tracking.

**Post-design re-check (after Phase 1)**: Still PASS. The design introduces exactly one write path (the service `confirm()`), wrapped in a transaction, producing a movement + balance change + audit row per line; it adds no caching of balances, no forked ACL, no new dependency, and no second audit trail. Making `AdjustmentResource`/its confirm action the first production consumer of `InteractsWithInventoryServices` lets us **shrink** the `phpstan.neon` test-only shim for that trait (see research R5) — an incremental-improvement win, not a new baseline entry. No new Complexity entries needed beyond the two above.

## Project Structure

### Documentation (this feature)

```text
specs/003-stock-adjustments/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (adjustment-service, adjustment-resource, authorization, audit-log)
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   └── AdjustmentStatus.php                           # NEW — draft|confirmed (backed string enum; drives status badge + transition guard)
├── Models/
│   ├── InventoryAdjustment.php                        # NEW — SoftDeletes; belongsTo Warehouse; hasMany items; createdBy; status cast to AdjustmentStatus
│   ├── InventoryAdjustmentItem.php                    # NEW — belongsTo InventoryAdjustment + ProductVariant; old/new/difference decimal:3
│   └── AuditLog.php                                   # NEW — actor, action, entity_type/id, old_values/new_values (json), source_channel
├── Data/
│   └── Inventory/
│       └── AdjustmentData.php                         # NEW — spatie/laravel-data: warehouse_id, reason, items[] rules (reused by Filament form; API later)
├── Services/
│   ├── Inventory/
│   │   └── InventoryAdjustmentService.php             # NEW — confirm(InventoryAdjustment): single transaction → movements + stock + audit; number generation; transition guard
│   └── Audit/
│       └── AuditLogger.php                            # NEW — shared audit-log writer (source_channel=dashboard); the ONLY writer of audit_logs
├── Filament/
│   ├── Concerns/
│   │   └── InteractsWithInventoryServices.php         # UNCHANGED — FI-3 is its first PRODUCTION consumer (used by the confirm action)
│   ├── AdminModuleRegistry.php                        # UNCHANGED — already imports AdjustmentResource; link activates when the class exists (R2)
│   └── Resources/
│       └── Adjustments/
│           ├── AdjustmentResource.php                 # NEW — draft CRUD + confirm; soft deletes; edit/delete hidden once confirmed
│           ├── Pages/{List,Create,Edit,View}Adjustment.php
│           ├── Schemas/AdjustmentForm.php             # warehouse (required), reason (required); adjustment_number read-only (service-owned)
│           ├── Schemas/AdjustmentInfolist.php         # view-page detail incl. status, items, resulting movements link
│           ├── Tables/AdjustmentsTable.php            # columns: number, warehouse, reason, status badge, items_count, created_by, created_at; filters status/warehouse/date
│           └── RelationManagers/
│               └── AdjustmentItemsRelationManager.php # variant (required), old_quantity (read-only, from live balance), new_quantity (required ≥ 0), difference (computed/read-only); editable only while draft
└── Policies/
    └── InventoryAdjustmentPolicy.php                  # NEW — view→adjustment.view; create/update/delete/restore→adjustment.create (update/delete draft-only); confirm→adjustment.confirm

database/
├── migrations/
│   ├── 2026_xx_xx_create_inventory_adjustments_table.php       # NEW — ERD §6; status string default 'draft'; soft deletes; created_by/updated_by
│   ├── 2026_xx_xx_create_inventory_adjustment_items_table.php  # NEW — ERD §6; old/new/difference decimal(15,3)
│   └── 2026_xx_xx_create_audit_logs_table.php                  # NEW — ERD §6 (audit_logs); introduced here as first sensitive action
├── factories/
│   ├── InventoryAdjustmentFactory.php                          # NEW — draft state + confirmed state
│   ├── InventoryAdjustmentItemFactory.php                      # NEW
│   └── AuditLogFactory.php                                     # NEW
└── seeders/
    └── InventoryDemoSeeder.php                                 # EDIT (optional, dev only) — add a sample draft adjustment for manual smoke

lang/
└── en/admin.php                                                # EDIT — adjustment column/attribute labels + confirm action + confirm success notification key
                                                                # (lang/ar restoration remains deferred — Open Question #8)

phpstan.neon                                                    # EDIT — remove the tests/Unit/InteractsWithInventoryServicesTest.php shim path now that a production consumer exists (baseline shrinks, R5)

tests/
├── Feature/Filament/
│   └── AdjustmentResourceTest.php                              # NEW — draft CRUD, unique auto-number, validation (reason/items/non-negative), permission hide/403, confirm hidden without confirm-permission
├── Feature/Inventory/
│   └── ConfirmAdjustmentTest.php                               # NEW — confirm creates movements + updates balances atomically; zero-stock variant; audit written; domain failure rollback; double-confirm refused; immutability
└── Unit/
    └── ArchTest.php                                            # UNCHANGED — the existing guard already bans App\Filament\Resources\Adjustments from InventoryStock/InventoryMovement, enforcing service delegation (no edit needed — see R4)
```

**Structure Decision**: Single Laravel app. New models live in `app/Models`; the status enum in `app/Enums` (repo arch preset requires enums there); adjustment validation in `app/Data/Inventory` (spatie/laravel-data, matching `WarehouseData`); the **domain service** in a new `app/Services/Inventory` folder and the **shared audit writer** in `app/Services/Audit` (first occupants of the services layer, per constitution Principle II "business rules live in domain services/actions"); the Filament resource in `app/Filament/Resources/Adjustments` matching the namespace `AdminModuleRegistry` already imports. Because the registry already references `AdjustmentResource`, creating the class replaces its sidebar placeholder automatically (registry §1.2) with **no registry edit**.

## Requirement coverage

| Spec item | Realized by | Outcome |
|---|---|---|
| US1 / FR-001…FR-008, SC-001 | `AdjustmentResource` (form + items relation manager), `AdjustmentData`, `InventoryAdjustmentPolicy` | **Fully planned** — draft creation with warehouse + required reason + item lines; service-owned read-only `adjustment_number`; per line: read-only current on-hand, required non-negative counted qty, computed read-only difference; add/edit/remove lines while draft; draft touches no balance/ledger; validation blocks reasonless/empty adjustments. |
| US2 / FR-009…FR-015, SC-002, SC-005, SC-007 | `InventoryAdjustmentService::confirm()`, `AuditLogger`, confirm action via `InteractsWithInventoryServices` | **Fully planned** — confirmation is the sole writer; per line one `adjustment` movement carrying the signed difference + a balance change by exactly that difference, inside one transaction; zero-stock variant treated as current 0 (balance established); audit row per confirmation; results visible on FI-2 read-only screens; domain failure surfaces as a notification with nothing changed. |
| US3 / FR-016…FR-019, SC-003, SC-004 | `AdjustmentStatus` guard in service (row-locked status check) + `InventoryAdjustmentPolicy` (draft-only update/delete, no hard delete) | **Fully planned** — confirmed adjustments immutable (edit/delete hidden by policy); re-confirm refused (locked status check → domain error); drafts soft-deleted (recoverable); atomic rollback on partial failure; status visible in list + view. |
| FR-020, FR-021 | `InventoryAdjustmentPolicy` (`create` vs `confirm` distinct permissions) | **Fully planned** — prepare gated by `inventory.adjustment.create`, apply gated by `inventory.adjustment.confirm`; confirm control hidden and confirmation refused without the apply permission, even for one's own draft. |
| FR-022 (inherited FI-0) | `InventoryAdjustmentPolicy` `viewAny`/`view` → `inventory.adjustment.view` | **Realized per resource** — navigation hidden + direct-URL 403 without the view permission, identical to FI-1/FI-2. |
| FR-023 | `AdjustmentsTable` columns + filters | **Fully planned** — number, warehouse, reason, status, items_count, creator, created_at; filters for status, warehouse, and date range. |

## Complexity Tracking

| Violation / Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| This feature creates the `inventory_adjustments` + `inventory_adjustment_items` tables/models ahead of the backend catalog/inventory spec (`005-products-variants-warehouses-inventory` in the constitution's extraction map), continuing the project-owner decision recorded for spec 002. | FI-3 cannot exist without these tables; table design is already finalized in ERD §6 (satisfying Principle I). The models carry no business logic (plain Eloquent + relationships/casts/soft deletes); all logic lives in `InventoryAdjustmentService`. Owning them here keeps the approved dashboard track unblocked. | Waiting for spec 005 blocks the entire dashboard track on an unscheduled backend phase. The migrations are additive and ERD-faithful, so spec 005 extends rather than collides. |
| The `audit_logs` table + `AuditLog` model + a shared `AuditLogger` service are introduced **here**, even though FI-0/FI-1/FI-2 had no audit table. | Confirmation is the **first sensitive, stock-changing action** in the whole dashboard (constitution Principle VI + FR-013 require it to be audited). There is nothing to audit until now, so this is the natural, minimal point to add the ERD §6 `audit_logs` store and a single shared writer that every later sensitive action (transfers, reservation release) reuses. | Deferring audit would ship a stock-mutating flow with no audit trail, violating Principle VI and FR-013. Writing audit rows ad-hoc from the service (no shared writer) would invite a second, divergent audit path later — the plan (§2.4) mandates one shared audit-log service. |
| A **domain service layer** (`app/Services/…`) is created for the first time. | Principle II mandates business rules live in domain services/actions, and the FI-0 arch guard forbids Filament from writing the ledger — so the stock mutation *must* live in a service. This is required structure, not gold-plating. | Putting stock logic in the Filament resource/page would violate Principle II/III and fail the FI-0 arch test. |
| A shared `StatusTransitionService` (named in plan §2.1) is **NOT** built; the draft→confirmed guard is implemented inline in `InventoryAdjustmentService` (row-locked status check). | Only one document type transitions in FI-3. A single guarded transition does not justify a shared abstraction yet (YAGNI); the guard is fully tested inside the adjustment service. | Building a shared transition service now would be premature abstraction with a single caller; it is deferred to FI-4 when transfers need the same pattern and the shared shape is known. Flagged in research R3. |

## Operational Flow (Draft → Confirm)

The end-to-end flow, consolidating the Phase 0 decisions (research R1, R3, R6-R10) and the
[adjustment-service contract](contracts/adjustment-service.md). This is the authoritative
narrative of what happens and where; the service contract holds the exact step list and the
[data-model state diagram](data-model.md#state-transitions) the allowed transitions.

### Stage 1 — Prepare (draft, inert)

1. A user with `inventory.adjustment.create` opens **Create Adjustment** and picks an active
   `warehouse_id` and a required `reason`. `CreateAdjustment` sets `created_by = auth()->id()`,
   leaves `status` at its `draft` default, and leaves `adjustment_number` **null** (numbers are
   issued only at confirm — R6, so discarded drafts never burn a number).
2. In the items relation manager the user adds one line per variant: picks `product_variant_id`,
   sees the **live** current on-hand for `(variant, warehouse)` as a read-only `old_quantity`
   (0 when no stock row exists), enters a non-negative `new_quantity`, and sees a computed
   read-only `difference` (R7). Only `product_variant_id` and `new_quantity` are persisted on the
   draft item; `old_quantity`/`difference` are display-only until confirm.
3. Validation is single-sourced from `AdjustmentData` (R11): active warehouse, non-empty reason,
   ≥1 item, each `new_quantity ≥ 0`. **No stock balance and no ledger row change** at any point in
   this stage (FR-007) — the draft is fully editable/discardable (soft delete, recoverable).

### Stage 2 — Apply (confirm, the sole write path)

The **Confirm** action is visible only for a `draft` record to a user holding
`inventory.adjustment.confirm` (distinct from `create` — segregation of duties, FR-020/FR-021).
It is a thin adapter: `runInventoryOperation(fn () => app(InventoryAdjustmentService::class)
->confirm($record, auth()->user()), '…notifications.confirmed')` via the FI-0
`InteractsWithInventoryServices` concern — it computes nothing (the arch guard, R4, forbids the
resource from touching `InventoryStock`/`InventoryMovement`).

`InventoryAdjustmentService::confirm()` then runs **one `DB::transaction`** (all-or-nothing):

```
open transaction
  reload adjustment WITH items, lockForUpdate                        (R3)
  guard status === draft            else DomainException(not_draft)   (FR-017: re-confirm / concurrent)
  guard warehouse.is_active          else DomainException(inactive)   (FR-015)
  guard items not empty              else DomainException             (defense in depth, FR-008)
  for each item (stable order):                                       (R7, R8, R9)
     lock/read stock row for (variant, warehouse); missing ⇒ old = 0  (FR-012)
     difference = new_quantity − old ; newOnHand = old + difference
     if newOnHand < 0                else DomainException(negative)    (R8, FR-015)
     persist item.old_quantity + item.difference                      (finalized from locked values)
     upsert stock: on_hand = newOnHand ; available = on_hand − reserved (reserved untouched)
     create ONE inventory_movements row: type=adjustment, quantity=difference (signed, 0 kept),
        source_type='adjustment' (short code, matches shipped link resolver), source_id=id, status=confirmed, created_by=actor   (R9, SC-007)
  assign adjustment_number = 'ADJ-' + locked zero-padded sequence     (R6)
  status = confirmed ; updated_by = actor
  AuditLogger::log('inventory.adjustment.confirmed', …, source_channel='dashboard')  (R10, FR-013)
commit
```

- **On success**: `#items` movements exist, each `(variant, warehouse)` balance moved by exactly
  its line difference, the document is `confirmed` with a unique number, and exactly one audit row
  exists. Results are visible **immediately** (synchronous, no queue) on the FI-2 read-only
  `StockLevelResource`/`StockMovementResource` screens (FR-014), which read live data.
- **On any throw**: the transaction rolls back — **zero** movements, **zero** balance changes,
  **zero** audit rows, adjustment stays `draft`; the domain message surfaces as a danger
  notification (FR-015, SC-003).

### Stage 3 — After confirm (immutable)

A `confirmed` adjustment is terminal: the policy returns `false` for `update`/`delete` and always
for `forceDelete`, so Edit/Delete/Confirm controls disappear and direct attempts are refused
(FR-016/FR-018); a second confirm is refused at the row-locked status guard (FR-017). The View
page infolist shows a read-only cross-module link to the resulting movements on the FI-2 ledger
(FR-014, module boundary preserved).

## Notes for downstream commands

- `/speckit-tasks` should order work: migrations + models + enum + factories → policy + permissions wiring (already seeded) → `AuditLogger` + `InventoryAdjustmentService` (with unit tests for confirm/atomicity/rollback/double-confirm) → `AdjustmentData` → Filament resource/pages/schemas/table/relation-manager + confirm action → `lang/en/admin.php` keys → `phpstan.neon` shim removal → feature tests → full `composer test` + Pint + Rector.
- The `confirm()` unit/feature tests are the heart of this phase (Principle III is NON-NEGOTIABLE): assert movement count == item count, exact balance deltas, audit row content, and that any thrown domain error leaves **zero** movements/balance changes.
