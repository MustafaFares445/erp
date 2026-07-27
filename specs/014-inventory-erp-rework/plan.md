# Implementation Plan: Inventory Module ERP-Pattern Rework

**Branch**: `014-inventory-erp-rework` | **Date**: 2026-07-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-inventory-erp-rework/spec.md`

## Summary

Rework the inventory module's surface to the reference ERP pattern while leaving the domain
underneath intact. Three separately-shaped documents (receipt, transfer, adjustment) collapse
into one operation document with one lifecycle; the product record becomes a tabbed hub with
variants folded in; product images arrive via the already-installed but entirely unused media
library; package types and instances are added as annotations on stock lines; and fifteen
navigation entries become four menus.

The stock balance grain stays `product_variant_id + warehouse_id` (A-001), so Constitution
Principle III needs no amendment. The one place the ERP pattern could not be copied directly is
in-transit visibility: the reference implementation gets it from two chained documents moving
stock through a transit *location*, which location-grain would have required. Instead a transfer
stays one document and carries an `InTransit` stage, governed by a single invariant that holds
for every operation type — *a warehouse's balance changes when that warehouse's custody changes*
(research [R-001](./research.md)).

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, `spatie/laravel-medialibrary` 11,
`spatie/laravel-permission` 8, `spatie/laravel-data` 4. **No new Composer dependency is
introduced** — see [R-004](./research.md).

**Storage**: Relational via Eloquent. Existing inventory schema; two new document tables
(`inventory_operations`, `inventory_operation_lines`), two new package tables (`package_types`,
`packages`), and nullable additive columns elsewhere.

**Testing**: Pest 4 / PHPUnit 12, Larastan 3 (`phpstan.neon` plus a baseline that may only
shrink), Pint. The gate is `composer test`, mirroring `.github/workflows/tests.yml`.

**Target Platform**: Filament admin panel in the IERP Laravel monolith, served locally by Laragon.

**Project Type**: Modular monolith — Laravel backend with a Filament admin panel for the
Inventory module, the sole module carrying an approved Filament exception (ADR 0001).

**Performance Goals**: No new targets. Existing list and detail responsiveness must not regress;
the product Quantities and IN/OUT tabs must not introduce N+1 queries against stock or movements.

**Constraints**:

- Balance grain fixed at product variant + warehouse (A-001).
- No new Composer dependencies without project-owner approval.
- No domain service deleted, no table dropped, no permission removed (A-006).
- `tests/Unit/ArchTest.php` must stay green: `App\Filament` may not use `InventoryStock` or
  `InventoryMovement` outside its read-only allowlist, so every new write surface goes through a
  domain service.
- All new screens bilingual Arabic/English, right-to-left in Arabic (SRS §5.1).

**Scale/Scope**: Touches five areas of a module holding 34 models, 22 Filament resource
directories and 20 inventory services. Five user stories, 41 functional requirements. The
uncommitted catalog-setup consolidation and location-annotation migration on the current branch
are the assumed baseline (A-011).

## Constitution Check

*GATE: evaluated before Phase 0 and re-evaluated after Phase 1 design.*

| Principle | Gate | Pre-Phase 0 | Post-Phase 1 |
|---|---|---|---|
| I. Specification-First | Spec approved and clarified before code; database design settled before implementation | PASS — spec plus five clarifications | PASS — data model settled |
| II. Domain-Driven Modular Monolith | Business rules in domain services, thin resources, no unrelated refactors | PASS — the unification *is* the feature; cleanup bounded by A-006 | PASS |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | Every stock change writes a movement; grain preserved; operations transactional; confirmed documents never deleted | PASS with tracked risk, below | PASS — reconciliation test mandated by [R-002](./research.md) |
| IV. Unified Access, Media & Payment | Spatie Permission for authorization; Media Library for all images; queues for long operations | PASS — [R-004](./research.md) closes a standing violation-by-omission | PASS |
| V. AI Isolation & Human Oversight | — | N/A, no AI surface | N/A |
| VI. Engineering Discipline | Tests per behavior, typed signatures, Pint, no baseline growth, report changed files | PASS | PASS |

**Principle III — the tracked risk.** Backfilling `inventory_operations` from `inventory_receipts`
and `stock_transfers` is the only balance-adjacent migration in this feature. Mitigation is
mandatory and specified in [R-002](./research.md): a reconciliation test asserting that every
`product_variant_id + warehouse_id` balance and the entire movement ledger are identical before
and after backfill. This gates the story, not the release. Legacy tables stay in place and
read-only until the features 003 and 004 acceptance suites pass against the new tables.

**Principle III — a second integration point.** The constitution states that delivery notes
affect inventory. FR-013 makes the inventory Delivery operation the record that moves stock for a
sales delivery note. Implementation MUST ensure a delivery note affects inventory through this
operation *only*, never in addition to an existing path, or stock moves twice. This is recorded
as a precondition in [contracts/inventory-operations.md](./contracts/inventory-operations.md).

**No violations require justification.** Complexity Tracking below is therefore empty.

## Project Structure

### Documentation (this feature)

```text
specs/014-inventory-erp-rework/
├── plan.md              # This file
├── spec.md              # Feature specification
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── inventory-operations.md
│   ├── product-record.md
│   └── packages-and-media.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── OperationType.php                    # NEW — Receipt | Delivery | InternalTransfer
│   ├── OperationStage.php                   # NEW — Draft|Waiting|Ready|InTransit|Done|Canceled
│   ├── TransferStatus.php                   # legacy, retained until backfill verified
│   ├── ReceiptStatus.php                    # legacy, retained until backfill verified
│   └── InventoryPermission.php              # extended; nothing removed (A-006)
├── Models/
│   ├── InventoryOperation.php               # NEW
│   ├── InventoryOperationLine.php           # NEW
│   ├── Package.php                          # NEW
│   ├── PackageType.php                      # NEW
│   ├── Product.php                          # + HasMedia
│   ├── ProductVariant.php                   # + HasMedia
│   └── InventoryStock.php                   # inTransitQuantity() re-pointed; grain unchanged
├── Services/Inventory/
│   ├── InventoryOperationService.php        # NEW — stage transitions, custody rule
│   ├── OperationBackfillReconciler.php      # NEW — R-002 verification surface
│   ├── StockTransferService.php             # retained, delegated to
│   ├── InventoryReceivingService.php        # retained, delegated to
│   └── ...                                  # 18 others untouched (A-006)
├── Policies/
│   └── InventoryOperationPolicy.php         # NEW
└── Filament/
    ├── AdminModuleRegistry.php              # 15 inventory items → 4 sections
    └── Resources/
        ├── InventoryOperations/             # NEW — Receipts, Deliveries, Internal Transfers
        ├── Packages/                        # NEW
        ├── PackageTypes/                    # NEW
        ├── Products/Pages/                  # + Attributes, Variants, Vendors,
        │                                    #   Quantities, MoveLines sub-navigation pages
        ├── ProductVariants/                 # retired from navigation; route redirects
        ├── StockReservations/               # retired from navigation → filter
        └── Returns/                         # retired from navigation → filter

database/migrations/                         # new tables, additive nullable columns, backfill
tests/Feature/Inventory/                     # one suite per user story + reconciliation test
tests/Unit/ArchTest.php                      # must stay green; no new exceptions added
lang/{ar,en}/                                # new keys for stages, menus, packages
```

**Structure Decision**: The existing layout is kept exactly — domain services in
`app/Services/Inventory`, Filament resources in `app/Filament/Resources/<Plural>/` with their
`Pages/`, `Tables/` and `Schemas/` subfolders, models flat in `app/Models`. No new top-level
directory is introduced, per the standing instruction not to create base folders without
approval. The new operation resources follow the conventions already set by `Adjustments` and
`Transfers`, including reading balances through `Warehouse::currentOnHand()` and
`currentAvailable()` rather than `InventoryStock` directly — which is what keeps `ArchTest` green
without adding exceptions to it.

## Phase Sequencing

Ordered by the spec's story priorities, with the risk front-loaded.

| Order | Story | Gate to pass before proceeding |
|---|---|---|
| 1 | US1 — unified operations | Reconciliation test green; features 003 and 004 suites green |
| 2 | US2 — product record tabs | Sub-navigation renders; no N+1 on Quantities or IN/OUT |
| 3 | US3 — media | Uploads persist through the media library; no new dependency |
| 4 | US4 — packages | Balances provably unchanged by package presence |
| 5 | US5 — navigation | Every retired route redirects; `InventoryNavigationTest` green |

Stories 2, 3 and 4 are independent of story 1 and of one another, so they may run in parallel if
capacity allows. Story 5 must be last, because it groups pages the earlier stories create.

## Complexity Tracking

No Constitution Check violations. This table is intentionally empty.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|--------------------------------------|
| — | — | — |
