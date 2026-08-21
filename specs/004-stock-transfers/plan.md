# Implementation Plan: Stock Transfers

**Branch**: `004-stock-transfers` | **Date**: 2026-07-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/004-stock-transfers/spec.md`

## Summary

Stock Transfers (FI-4) is the second stock-changing screen in the Filament inventory dashboard. An administrator prepares a **draft** transfer (a source warehouse, a different destination warehouse, optional notes, and one line per product variant with a quantity), then a permitted administrator **confirms** it. Confirmation is the single, atomic moment stock moves: for each line the system writes a **pair** of ledger movements (negative out of the source, positive into the destination) and updates both stock balances in one transaction, after checking the source has enough *available* stock for every line. Confirmed transfers are immutable; drafts are soft-deletable and restorable; every lifecycle action is audited.

**Technical approach**: Mirror the shipped Stock Adjustments feature (FI-3, spec 003) end-to-end — a `readonly` domain service is the sole writer (guards, `lockForUpdate`, `DB::transaction`), a Filament resource under `App\Filament\Resources\Transfers` collects input and delegates all stock reads/writes to the service and to a new `Warehouse::currentAvailable()` helper (never touching `InventoryStock`/`InventoryMovement` directly, per the ArchTest guard), and the existing `AuditLogger`, `InteractsWithInventoryServices` trait, `ChecksInventoryPermissions` trait, `MovementType::Transfer` case, seeded `inventory.transfer.*` permissions, and `AdminModuleRegistry` wiring are reused unchanged. The only structural divergences from adjustments are: **two** warehouses and **paired** movements per line; an **available-stock** precheck (summed per variant across duplicate lines) instead of a negative-result guard; a **full trashed/restore UI**; and **all-lifecycle** audit (not only confirm).

## Technical Context

**Language/Version**: PHP 8.4 (`declare(strict_types=1)` throughout)

**Primary Dependencies**: Laravel 13, Filament 5, spatie/laravel-permission 8, spatie/laravel-data 4 (DTO + validation rules), spatie/laravel-medialibrary (N/A this phase)

**Storage**: MySQL/MariaDB via Eloquent. Two new tables (`stock_transfers`, `stock_transfer_items`); reads/writes to existing `inventory_stocks`, `inventory_movements`, `audit_logs`, `warehouses`, `product_variants`.

**Testing**: Pest 4 (`pest-plugin-laravel`), `RefreshDatabase`; Livewire/Filament testing helpers for the resource; direct service tests for the confirm flow. Larastan (PHPStan) level per `phpstan.neon`; Pint; Rector. Gate: `composer test` = pint + rector dry-run + phpstan + `pest --type-coverage --min=100` + `pest --coverage --min=100`.

**Target Platform**: Filament admin panel (System Administrator dashboard channel), server-rendered.

**Project Type**: Web application — single Laravel modular monolith; this feature is a dashboard (admin panel) slice with a domain service.

**Performance Goals**: Interactive dashboard; no throughput target. SC-010: locate a transfer via filters in <30s on a representative data set (satisfied by indexed list filters).

**Constraints**: Inventory Integrity (Constitution III) is non-negotiable — every stock change writes a movement, balances never go negative, all mutation inside one transaction, confirmed documents never physically deleted. The Filament layer MUST NOT read/write `InventoryStock`/`InventoryMovement` directly (ArchTest). Balances are never cached (read current rows under lock at confirm time).

**Scale/Scope**: Single-tenant admin usage; a handful of warehouses, thousands of variants. Transfers are hand-prepared, one at a time (bulk/import is out of scope, FI-6).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Status |
|-----------|------|--------|
| I. Specification-First | Plan derived from approved spec.md + clarifications; DB design finalized in data-model.md before implementation. | ✅ PASS |
| II. Domain-Driven Modular Monolith | Business rules live in `StockTransferService` (domain service); Filament pages stay thin (collect input, call service). Input validation via `TransferData::rules()` (spatie-data), the established dashboard analogue to Form Requests (FI-3 precedent). No API surface this phase (API Resources N/A). No unrelated refactors. | ✅ PASS |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | Each confirmed line writes a movement pair (source of truth `product_variant_id + warehouse_id`); confirm runs in one `DB::transaction`; source can never go negative (available-stock precheck + guard); confirmed transfers immutable and soft-delete-only (never physically deleted). | ✅ PASS |
| IV. Unified Access, Media & Payment | Authorization via spatie/laravel-permission (`inventory.transfer.*`, already seeded). No media, no payments. No long-running work → no queue needed (synchronous confirm is correct and atomic). | ✅ PASS |
| V. AI Isolation & Human Oversight | N/A — no AI in this feature. | ✅ PASS |
| VI. Engineering Discipline | Spec + constitution read first; thin pages + service; validation via Data rules; transaction-wrapped; tests for every rule; **all** sensitive/lifecycle actions audited; no unrelated refactors; changed-file report at completion. | ✅ PASS |
| Product Scope (ADR 0001) | Filament dashboard is approved for the Inventory module only — this feature is squarely inside that exception. | ✅ PASS |

**Gate result**: PASS — no violations. Complexity Tracking left empty.

**Post-design re-check (after Phase 1)**: PASS — the design adds no new dependency, no new authorization mechanism, no cross-module coupling. The one model edit (`Warehouse::currentAvailable()`) is an additive read helper that keeps the ArchTest boundary intact; the `StockTransferObserver` uses the existing `AuditLogger`. No principle is weakened.

## Project Structure

### Documentation (this feature)

```text
specs/004-stock-transfers/
├── plan.md              # This file
├── research.md          # Phase 0 output — decisions & rationale
├── data-model.md        # Phase 1 output — tables, enum, invariants
├── quickstart.md        # Phase 1 output — validation guide
├── contracts/           # Phase 1 output — class-surface contracts
│   ├── transfer-service.md
│   ├── transfer-resource.md
│   ├── authorization.md
│   └── audit-log.md
├── checklists/
│   └── requirements.md  # (existing) spec quality checklist — 16/16
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   └── TransferStatus.php                 # NEW — Draft|Confirmed (MovementType::Transfer & InventoryPermission::Transfer* already exist)
├── Data/Inventory/
│   └── TransferData.php                   # NEW — DTO + rules() (from_warehouse_id, to_warehouse_id different, notes, items.*.quantity gt:0)
├── Models/
│   ├── StockTransfer.php                  # NEW — SoftDeletes, TracksBlameable, #[ObservedBy(StockTransferObserver)]
│   ├── StockTransferItem.php              # NEW
│   └── Warehouse.php                      # EDIT — add currentAvailable(int $productVariantId): float
├── Observers/
│   └── StockTransferObserver.php          # NEW — created/updated/deleted/restored → AuditLogger (lifecycle audit)
├── Policies/
│   └── StockTransferPolicy.php            # NEW — uses ChecksInventoryPermissions; view/create/confirm + draft-gated update/delete/restore
├── Services/Inventory/
│   └── StockTransferService.php           # NEW — final readonly; confirm(StockTransfer, User): void
└── Filament/Resources/Transfers/
    ├── TransferResource.php               # NEW — already referenced by AdminModuleRegistry (string FQCN, no edit needed)
    ├── Pages/
    │   ├── ListTransfers.php              # CreateAction header; TrashedFilter via table
    │   ├── CreateTransfer.php
    │   ├── EditTransfer.php               # draft-only edit; Delete/Restore/ForceDelete(=false) header actions
    │   └── ViewTransfer.php               # hosts the Confirm action (uses InteractsWithInventoryServices)
    ├── Schemas/
    │   ├── TransferForm.php                # from/to warehouse Selects (active; to != from), read-only number, notes
    │   └── TransferInfolist.php            # header + items + (confirmed) paired movements linked to StockMovementResource
    ├── Tables/
    │   └── TransfersTable.php              # number, from→to, status badge, items_count, creator, created_at; status/warehouse/date + Trashed filters
    └── RelationManagers/
        └── TransferItemsRelationManager.php # product_variant + quantity(>0); shows source available via Warehouse::currentAvailable(); draft-only

database/
├── migrations/
│   ├── 2026_07_22_000011_create_stock_transfers_table.php       # NEW
│   └── 2026_07_22_000012_create_stock_transfer_items_table.php  # NEW
└── factories/
    ├── StockTransferFactory.php           # NEW — draft() default + confirmed() state
    └── StockTransferItemFactory.php       # NEW

lang/en/
└── admin.php                              # EDIT — add inventory.transfer.* subtree (labels, confirm, notifications, errors incl. same_warehouse, insufficient_stock); admin.resources.transfers already present

tests/
├── Feature/Filament/
│   └── TransferResourceTest.php           # NEW — form/table/relation-manager/permission/confirm-action coverage
└── Feature/Inventory/
    └── ConfirmTransferTest.php            # NEW — service: paired movements, dual-balance, availability rollback, immutability, audit

database/seeders/
└── InventoryDemoSeeder.php                # (optional EDIT) add a sample draft transfer mirroring seedDraftAdjustment()
```

**Reused unchanged (no edit):** `App\Services\Audit\AuditLogger`; `App\Filament\Concerns\InteractsWithInventoryServices`; `App\Policies\Concerns\ChecksInventoryPermissions`; `App\Models\Concerns\TracksBlameable`; `App\Enums\MovementType` (`Transfer` case present); `App\Enums\InventoryPermission` (`TransferView/Create/Confirm` present + seeded by `InventoryPermissionSeeder`); `AdminModuleRegistry` (imports `TransferResource` FQCN as a string — `::class` does not autoload); `StockMovementsTable` (already maps `'transfer' → TransferResource` and colours `MovementType::Transfer`); `tests/Unit/ArchTest.php` (its comment already anticipates "Transfers" and it is intentionally **not** excepted).

**Structure Decision**: Single Laravel modular monolith, inventory domain. The feature is delivered as a Filament dashboard resource plus one domain service, exactly mirroring `App\Filament\Resources\Adjustments` and `App\Services\Inventory\InventoryAdjustmentService`. No new top-level directories; `app/Observers/` is the only new folder and follows Laravel convention (registered via the `#[ObservedBy]` attribute, no provider edit).

## Requirement coverage

| Spec item | Realized by | Outcome |
|-----------|-------------|---------|
| FR-001, FR-004, FR-005 | `TransferForm` + `TransferItemsRelationManager` (draft-only, quantity>0) | Prepare a draft with lines |
| FR-002 (source ≠ destination) | `TransferData::rules()` `different:from_warehouse_id` + `to_warehouse_id` Select excludes source; service re-guards `errors.same_warehouse` | Same-warehouse rejected before any stock touched |
| FR-003 (system number) | Service `nextTransferNumber()` → `TRF-%06d` assigned at confirm; field read-only/non-fillable | Admin can't set the number |
| FR-006 (draft touches nothing) | No stock/movement writes outside `StockTransferService::confirm()` | Draft is inert |
| FR-007 (can't apply if incomplete) | Confirm guards: no items / missing warehouse → domain error | Blocked with reason |
| FR-008 (confirm is sole writer) | `StockTransferService::confirm()` is the only writer; ArchTest forbids Filament writes | Single path |
| FR-009 + FR-009a (availability, summed duplicates) | Service groups lines by variant, sums quantity, checks `available_quantity` under lock; per-line movement pairs | Duplicate lines summed for check, not merged |
| FR-010, FR-011, FR-012 | Per line: −Q source movement + +Q destination movement; establish destination row at 0 if absent; all in one `DB::transaction` | Atomic paired relocation |
| FR-013, FR-020 | Available-stock precheck + `newOnHand < 0` guard; Σout = Σin invariant (test) | Never negative; balanced ledger |
| FR-014 + FR-014a (all-lifecycle audit) | `StockTransferService` writes `inventory.transfer.confirmed` (with before/after balances); `StockTransferObserver` writes created/edited/discarded/restored | Every action audited |
| FR-015 | Movements/balances read from the same `inventory_stocks`/`inventory_movements` the FI-2 screens show | Immediately visible |
| FR-016 | `InteractsWithInventoryServices::runInventoryOperation()` catches `DomainException` → danger notification | Clear failure message, nothing changed |
| FR-017, FR-018 | Policy `update/delete` require `isDraft()`; confirm guard rejects non-draft; confirmed edit/delete controls hidden | Immutable, single-confirm |
| FR-019 + FR-019a | `SoftDeletes`; `TrashedFilter` + `RestoreAction`; policy `restore` → `TransferCreate` | Recoverable + self-service restore UI |
| FR-021 | Status badge column + infolist | Status visible everywhere |
| FR-022, FR-023 | `TransferPolicy` maps create→`TransferCreate`, confirm→`TransferConfirm` (distinct); confirm action `->authorize()`/`->visible()` gated | Segregation of duties |
| FR-024 | Panel access gate (FI-0) + policy `viewAny`/`view`; direct-URL access 403 for unpermitted | Area hidden/blocked |
| FR-025 | `TransfersTable` columns + status/warehouse/date filters | Discoverable list |

## Complexity Tracking

> No constitution violations — this table is intentionally empty.

## Operational Flow

**Stage 1 — Prepare (draft).** Admin (needs `inventory.transfer.create`) creates a `StockTransfer` via `CreateTransfer`: picks `from_warehouse_id` and a different active `to_warehouse_id`, optional notes; `TracksBlameable` sets `created_by`; `status = draft`; `transfer_number = null`. Lines are added in `TransferItemsRelationManager` (variant + quantity > 0); the manager shows current source availability via `Warehouse::currentAvailable()`. No stock or ledger row changes. Every save fires the observer → `inventory.transfer.created` / `inventory.transfer.edited` audit.

**Stage 2 — Apply (confirm).** On `ViewTransfer`, a permitted admin (`inventory.transfer.confirm`) triggers the Confirm action → `StockTransferService::confirm($transfer, $actor)`:

```text
DB::transaction:
  locked = StockTransfer::with(from,to)->lockForUpdate()->findOrFail(id)
  guard locked.status == Draft                      else DomainException(errors.not_draft)
  guard from != to                                  else errors.same_warehouse
  guard from.is_active && to.is_active              else errors.inactive_warehouse
  items = locked.items()->orderBy(id)->lockForUpdate()->get()
  guard items not empty                             else errors.no_items
  # availability: sum by variant across (possibly duplicate) lines
  need = items.groupBy(variant).map(sum quantity)
  for each variant,qty in need:
      avail = InventoryStock(variant, from)->lockForUpdate()->available_quantity ?? 0
      guard avail >= qty                            else errors.insufficient_stock
  for each item (per line):
      decrement source stock  by item.quantity  (on_hand & available recomputed)
      increment dest stock    by item.quantity  (row established at 0 if absent)
      forceCreate movement: type=Transfer, qty=-item.quantity, warehouse=from, source_type='transfer', source_id=locked.id, status='confirmed', created_by=actor
      forceCreate movement: type=Transfer, qty=+item.quantity, warehouse=to,   source_type='transfer', source_id=locked.id, status='confirmed', created_by=actor
  locked.forceFill(transfer_number=nextTransferNumber(), status=Confirmed, updated_by=actor).saveQuietly()   # saveQuietly → observer does NOT fire
  auditLogger.log('inventory.transfer.confirmed', locked, old={status:draft, balances:before}, new={status:confirmed, number, balances:after}, actor, 'dashboard')
```

Any guard throws `DomainException` → the whole transaction rolls back (0 movements, 0 balance changes) and `runInventoryOperation()` shows a danger notification.

**Stage 3 — After.** The transfer shows `Confirmed` with its `TRF-######` number; edit/delete controls are gone; a second confirm is refused by the `status == Draft` guard. The decreased source balances, increased destination balances, and the paired transfer movements appear on the read-only FI-2 Stock Levels and Stock Movements screens (the movement rows link back to this transfer via `source_type='transfer'`).

## Notes for downstream commands

- **/speckit-tasks** should group: Setup (migrations, enum, `TransferData`) → Foundational (models, `Warehouse::currentAvailable()`, factories, observer, policy) → US1 (form + relation manager + create/edit/list + trashed/restore) → US2 (service `confirm()` + View confirm action + lang) → US3 (immutability, atomicity, balanced-ledger, audit tests) → Polish (infolist movement links, demo seeder, `composer test`).
- **No** new permission, seeder entry, registry edit, ArchTest edit, or FI-2 edit is required — all pre-wired (verified).
- The `lang/en/admin.php` `inventory.transfer.*` subtree is the only guaranteed edit to a shared file; mirror the `adjustment` subtree and add `errors.same_warehouse` and `errors.insufficient_stock`.
- Enforce the 100% type + code coverage gate; every FR with observable behavior has a mapped test in `contracts/*` "Test obligations".
