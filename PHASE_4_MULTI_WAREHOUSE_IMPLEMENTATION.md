# Phase 4 — Multi-Warehouse Purchase Inbound Implementation

## Status

Implementation complete on `feat/phase-4-allocation-service` for the multi-warehouse purchase inbound scope described below.

This document records the shipped architecture and traceability. It does **not** claim the current branch has passed runtime CI: GitHub had no published workflow/status result for the feature head when this document was written. Runtime validation remains a release gate.

## Domain ownership

- **Purchasing** owns suppliers, purchase orders and lines, commercial quantities, approvals, supplier communication, and the commercial purchase lifecycle.
- **Inventory / Logistics** owns warehouses, physical inbound allocation, inventory operations, stock movements, lots/serials, transfers, physical supplier returns, and replenishment.
- **Accounting** owns supplier bills, supplier credits, AP, GRNI, journals, tax accounting, financial matching, and payment.
- A `PurchaseOrder` does **not** own a destination warehouse. Warehouse destination is a quantity split represented by `PurchaseInboundAllocation` after acceptance.
- Creating or allocating a PO does not move stock. Stock changes only when an Inventory receipt reaches `done` through `InventoryOperationService`.
- Receipt completion does not create or post a supplier bill, AP, GRNI, or journal entry in this Phase 4 scope.

## 4.1 — Schema and deterministic migration

Migration:

`database/migrations/2026_09_13_020000_add_multi_warehouse_purchase_inbound_allocation_foundation.php`

Implemented:

- `purchase_inbound_allocations.allocated_base_quantity DECIMAL(20,6)`.
- Unique allocation grain `(purchase_inbound_line_id, warehouse_id)`.
- `inventory_operation_lines.purchase_inbound_allocation_id` FK/index for physical provenance.
- Duplicate legacy line/warehouse allocations are rejected rather than silently merged.
- Backfill is deterministic only; ambiguous historical provenance is left unresolved rather than guessed.
- Rollback refuses to collapse true split allocations into a single-warehouse representation.

## 4.2 — Canonical models

`PurchaseInboundLine`:

- `allocations(): HasMany` is the canonical relationship.
- `allocation(): HasOne` remains only as a deprecated compatibility view.
- exposes inbound, allocated, and unallocated base-quantity helpers.

`PurchaseInboundAllocation`:

- stores canonical allocated base quantity.
- links to Inventory operation lines through allocation provenance.
- derives physically received quantity from `done` receipt lines only.
- derives remaining physical quantity from allocated minus completed receipt quantity.

`InventoryOperationLine`:

- carries `purchase_inbound_allocation_id` for receipt provenance.

### Compatibility rule

New multi-warehouse code must use `allocations()` or an explicit `PurchaseInboundAllocation`. New business logic must not use the deprecated singular `allocation()` relationship to choose a warehouse.

## 4.3 — Allocation service and invariants

Canonical write service:

`app/Services/Purchasing/PurchaseInboundService.php`

Implemented invariants:

- allocation quantities are canonical base quantities with at most six decimal places.
- quantities must be positive.
- a warehouse may appear only once per inbound line.
- sum of allocations cannot exceed the canonical PO-line base quantity.
- allocation writes lock inbound, inbound line, PO line, and allocation rows in a deterministic transaction.
- explicit `updateAllocation()` and `removeAllocation()` operations are service-owned.
- an allocation cannot be reduced below quantity committed by a non-cancelled receipt.
- an allocation with committed receipt quantity cannot move to another warehouse.
- an allocation with committed receipt quantity cannot be removed.
- the legacy no-quantity allocation path is accepted only when its result is deterministic.

## 4.4 — Allocation-aware receiving

Canonical service:

`app/Services/Purchasing/PurchaseOrderReceivingService.php`

Canonical explicit receipt input:

```php
[
    [
        'purchase_inbound_allocation_id' => 123,
        'quantity' => '20.000000',
    ],
]
```

Implemented behavior:

- every canonical purchase receipt consumes an explicit allocation.
- warehouse is derived from the allocation, not from the PO.
- one Inventory receipt operation may contain only allocations for one destination warehouse.
- receipt quantity cannot exceed allocation availability.
- receipt quantity cannot exceed PO-line availability.
- non-cancelled Draft/Waiting/Ready receipts are commitments when calculating new receipt availability.
- generated receipt lines use canonical base UOM and persist both PO-line and allocation provenance.
- receipt initiation creates a Draft Inventory operation only; it does not move stock.
- completion revalidates PO and allocation limits inside the Inventory completion transaction.
- deterministic legacy provenance may be inferred only when PO line + destination warehouse resolves to exactly one allocation.
- ambiguous legacy provenance fails rather than choosing arbitrarily.

`PurchaseOrderReceivingService::availableBaseQuantityForAllocation()` is the UI-safe read path for availability because it includes open receipt commitments.

## 4.5 — Aggregate inbound status

Canonical service:

`app/Services/Purchasing/PurchaseInboundStatusService.php`

Status is derived from exact allocation and completed physical-receipt facts:

| State | Meaning |
|---|---|
| `awaiting_allocation` | At least one inbound line is not fully allocated and no completed receipt exists. |
| `awaiting_receipt` | All inbound quantities are allocated and no receipt has completed. |
| `partially_received` | At least one physical receipt completed but the inbound total is incomplete. |
| `received` | Every inbound line is physically received in full. |
| `cancelled` | Terminal; synchronization does not recalculate it. |

Draft/Waiting/Ready receipt operations do not count as physical receipt for status progression.

`AdvancePurchaseOrderOnOperationCompleted` reconciles allocation provenance, PO received quantities, inbound status, PO status, and replenishment inside the same completion transaction so a validation failure rolls back the physical posting as well.

## 4.6 — Warehouse-aware replenishment

Canonical purchase incoming calculator:

`app/Services/Inventory/PurchaseInboundIncomingSupplyService.php`

Rule:

```text
incoming purchase supply for warehouse allocation
= allocated_base_quantity
- completed received base quantity for that allocation
```

Both of these use the shared calculator:

- `PurchaseReplenishmentCoverageService`
- `ReplenishmentProjectionService`

Therefore a PO line of 100 split as A=60 and B=40 contributes 60 to A and 40 to B, not 100 to both warehouses. After completing A=20 and B=15, incoming becomes A=40 and B=25.

Historical compatibility is conservative:

- one allocation with an unknown historical allocation quantity can deterministically use the PO line quantity.
- a multi-allocation historical split with unknown quantity is never guessed.

## 4.7 — Filament purchasing UX

Purchase-order allocation UI:

`app/Filament/Resources/PurchaseOrders/RelationManagers/AllocationsRelationManager.php`

Displays line-level:

- ordered base quantity
- allocated total
- unallocated quantity
- received total
- remaining total

Displays aligned per-warehouse split data:

- warehouse
- allocated quantity
- received quantity
- remaining quantity

Actions:

- Add Allocation
- Edit Allocation
- Remove Allocation

All mutations delegate to `PurchaseInboundService`; Filament does not reproduce allocation business rules.

Purchase-order Receive action:

`app/Filament/Resources/PurchaseOrders/Actions/PurchaseOrderActions.php`

- selects an explicit allocation.
- shows SKU, warehouse, and currently available base quantity.
- availability excludes quantities already committed by open non-cancelled receipts.
- submits allocation id + quantity to `PurchaseOrderReceivingService`.
- does not guess a warehouse and does not post stock directly.

Translations are provided in:

- `lang/en/purchase_inbound.php`
- `lang/ar/purchase_inbound.php`

## 4.8 — Regression and acceptance coverage

Focused test coverage includes:

- `tests/Feature/Purchasing/PurchaseInboundAllocationServiceTest.php`
- `tests/Feature/Purchasing/PurchaseInboundAllocationReservationTest.php`
- `tests/Feature/Purchasing/PurchaseInboundReceivingTest.php`
- `tests/Feature/Purchasing/PurchaseInboundStatusServiceTest.php`
- `tests/Feature/Purchasing/PurchaseOrderReceivingTest.php`
- `tests/Feature/Purchasing/PurchaseOrderOverReceiptTest.php`
- `tests/Feature/Inventory/PurchaseInboundIncomingSupplyServiceTest.php`
- `tests/Feature/Inventory/PurchaseReplenishmentCoverageLifecycleTest.php`
- `tests/Feature/Inventory/PurchaseReplenishmentMultiWarehouseCoverageTest.php`
- `tests/Feature/Purchasing/PurchaseMultiWarehouseInboundEndToEndTest.php`

The end-to-end acceptance scenario covers:

```text
PO line = 100
allocate A = 60
allocate B = 40
complete A = 20
complete B = 15
=> PO received = 35
=> inbound = partially_received
=> A available = 40
=> B available = 25
complete A = 40
complete B = 25
=> PO received = 100
=> PO = received
=> inbound = received
=> stock A = 60
=> stock B = 40
=> every purchase receipt line retains allocation provenance
```

## 4.9 — Concurrency, legacy, and maintenance rules

### Locking / transaction boundary

Allocation and receiving services lock the Purchasing rows they validate. Receipt completion revalidates the purchase context while Inventory completion is still inside its transaction. The physical stock posting and Purchasing reconciliation therefore commit together or roll back together.

### Historical data

Never fabricate allocation distribution for ambiguous history. A null historical allocation quantity or missing receipt provenance may be repaired only when the available facts identify one result.

### Deprecated singular relationship

`PurchaseInboundLine::allocation()` exists only for compatibility with old code. It is not a supported source for new multi-warehouse decisions. New code must use `allocations()` and explicit allocation provenance.

### Accounting separation

Multi-warehouse receipt work must not introduce a second bill/AP/accounting path. A physical receipt and a supplier bill are separate business documents with separate lifecycle and ownership.

## Release validation gate

Before production release, run the repository's normal gates on the merged `dev` head:

```bash
php artisan migrate:fresh --seed
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

If the repository CI executes broader Composer scripts (type coverage / 100% coverage / Rector), those remain authoritative as well.

A merge commit or fast-forward alone is not evidence that these runtime gates passed.
