# Phase 1 Data Model: Inventory Module ERP-Pattern Rework

**Feature**: `specs/014-inventory-erp-rework` | **Date**: 2026-07-27

Everything here is **additive**. No existing column is dropped or re-typed, and the balance grain
`inventory_stocks.(product_variant_id, warehouse_id)` is untouched (A-001, Constitution
Principle III).

---

## 1. Enums

### `OperationType`

| Case | Value | Source warehouse | Destination warehouse |
|---|---|---|---|
| `Receipt` | `receipt` | none (external supplier) | required |
| `Delivery` | `delivery` | required | none (external customer) |
| `InternalTransfer` | `internal_transfer` | required | required, must differ from source |

No `Dropship` case (FR-018, A-005).

### `OperationStage`

| Case | Value | Applies to | Meaning |
|---|---|---|---|
| `Draft` | `draft` | all | Composing; freely editable; no stock effect |
| `Waiting` | `waiting` | all | Blocked — insufficient available quantity, or an unmet dependency |
| `Ready` | `ready` | all | Requirements met; outbound quantity reserved |
| `InTransit` | `in_transit` | **internal transfer only** | Released by source, not yet received |
| `Done` | `done` | all | Destination custody taken; terminal |
| `Canceled` | `canceled` | all | Terminated before completion; terminal |

**Legal transitions** (anything else is rejected):

```
Draft     → Waiting | Ready | Canceled
Waiting   → Ready | Canceled
Ready     → InTransit (internal transfer only) | Done | Waiting | Canceled
InTransit → Done | Canceled
Done      → (terminal)
Canceled  → (terminal)
```

`Ready → Waiting` exists because reserved stock can be lost to a competing operation.

**Custody rule** — the single invariant behind every balance change (FR-003):

| Type | Source on-hand decreases at | Destination on-hand increases at |
|---|---|---|
| Receipt | — | `Done` |
| Delivery | `Done` | — |
| Internal Transfer | `InTransit` | `Done` |

---

## 2. `inventory_operations`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `operation_type` | string(30) | no | `OperationType`; indexed |
| `stage` | string(30) | no | `OperationStage`; default `draft`; indexed |
| `operation_number` | string(100) | yes | unique; assigned on leaving `Draft` |
| `source_warehouse_id` | fk → `warehouses` | yes | required for delivery + internal transfer; `restrictOnDelete` |
| `destination_warehouse_id` | fk → `warehouses` | yes | required for receipt + internal transfer; `restrictOnDelete` |
| `supplier_id` | fk → `suppliers` | yes | receipts only; `nullOnDelete` |
| `source_document_type` | string(255) | yes | polymorphic type of the originating commercial document |
| `source_document_id` | bigint | yes | polymorphic id; composite index with type |
| `supplier_reference` | string(100) | yes | carried from `inventory_receipts` |
| `scheduled_at` | timestamp | yes | |
| `responsible_id` | fk → `users` | yes | `nullOnDelete` |
| `dispatched_at` | timestamp | yes | set on entering `InTransit` |
| `completed_at` | timestamp | yes | set on entering `Done` |
| `canceled_at` | timestamp | yes | set on entering `Canceled` |
| `notes` | text | yes | |
| `legacy_receipt_id` | bigint | yes | backfill provenance; unique; dropped after R-002 verification |
| `legacy_transfer_id` | bigint | yes | backfill provenance; unique; dropped after R-002 verification |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable`, matching siblings |
| timestamps, `softDeletes` | | | matches `stock_transfers` and `inventory_receipts` |

**Indexes**: `operation_type`, `stage`, `(operation_type, stage)`, `source_warehouse_id`,
`destination_warehouse_id`, `(source_document_type, source_document_id)`, `created_at`.

**Validation rules**:

- **V-01** Warehouse presence must match the type table in §1.
- **V-02** `source_warehouse_id !== destination_warehouse_id` for an internal transfer.
- **V-03** `stage = in_transit` is permitted only when `operation_type = internal_transfer`.
- **V-04** Once `stage ∈ {done, canceled}` the row is immutable and undeletable (FR-008).
- **V-05** `operation_number` is unique and, once assigned, never changes.
- **V-06** An operation cannot leave `Draft` with zero lines.
- **V-07** An operation cannot leave `Draft` if any line's product is Inactive or Coming Soon
  (SRS §3.3).

---

## 3. `inventory_operation_lines`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `inventory_operation_id` | fk → `inventory_operations` | no | `cascadeOnDelete` |
| `product_variant_id` | fk → `product_variants` | no | `restrictOnDelete` |
| `quantity` | decimal(15,3) | no | > 0; three decimals matches `inventory_stocks` (A-010) |
| `unit_id` | fk → `units` | no | `restrictOnDelete` |
| `warehouse_location_id` | fk → `warehouse_locations` | yes | optional annotation only (A-001, FR-015) |
| `package_id` | fk → `packages` | yes | optional annotation only (FR-033) |
| `inventory_lot_id` | fk → `inventory_lots` | yes | |
| `serialized_inventory_unit_id` | fk → `serialized_inventory_units` | yes | |
| `is_picked` | boolean | no | default false; mirrors the reference ERP's per-line Picked toggle |
| `unit_cost` | decimal(15,4) | yes | receipts; visibility gated by permission (SRS §5.3) |
| timestamps | | | |

**Indexes**: `inventory_operation_id`, `product_variant_id`, `package_id`.

**Validation rules**:

- **V-08** `quantity > 0`. Direction comes from the operation type, never from a negative number.
- **V-09** A serialised unit may appear on at most one non-canceled operation line at a time
  (SRS §3.4, §4).
- **V-10** `warehouse_location_id`, when set, must belong to the warehouse the line affects —
  reuse `WarehouseLocation::belongsToWarehouse()`.
- **V-11** `package_id`, when set, must belong to the warehouse the line affects.
- **V-12** Quantity precision must not exceed the unit's allowed decimals; reject rather than
  silently truncate (SRS §3.5).

---

## 4. `package_types`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `name` | string(150) | no | e.g. Box, Carton |
| `code` | string(50) | yes | unique when present |
| `is_active` | boolean | no | default true |
| `created_by` / `updated_by` | fk → `users` | yes | |
| timestamps, `softDeletes` | | | |

- **V-13** Deletion is refused while any `packages` row references it (FR-035).

## 5. `packages`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `name` | string(150) | no | e.g. "Pack of → 10" |
| `package_type_id` | fk → `package_types` | no | `restrictOnDelete` |
| `warehouse_id` | fk → `warehouses` | no | `restrictOnDelete` |
| `warehouse_location_id` | fk → `warehouse_locations` | yes | `nullOnDelete` |
| `is_active` | boolean | no | default true |
| `created_by` / `updated_by` | fk → `users` | yes | |
| timestamps, `softDeletes` | | | |

**There is deliberately no quantity column** (FR-034, [R-005](./research.md)). A package annotates
lines; it never holds a balance. Introducing one would create a second place quantity lives.

- **V-14** Deletion is refused while any operation, adjustment or stock line references it.
- **V-15** `warehouse_location_id`, when set, must belong to `warehouse_id`.
- **V-16** Changing `warehouse_id` while goods reference the package is refused unless the goods
  move with it as a recorded movement (spec Edge Cases).

## 6. Package foreign keys added elsewhere

Nullable, `nullOnDelete`, additive — no existing query changes:

- `inventory_adjustment_items.package_id`
- `stock_transfer_items.package_id` (legacy table, kept in sync during the dual-write window)
- `inventory_movements.package_id`

---

## 7. Media collections

No new table. `media` already exists (`2026_07_20_095708_create_media_table.php`) and is unused.

| Model | Change | Collection | Rules |
|---|---|---|---|
| `Product` | implements `HasMedia`, uses `InteractsWithMedia` | `images` | image mimes only; size capped by config; ordered; first is main |
| `ProductVariant` | implements `HasMedia`, uses `InteractsWithMedia` | `images` | same; falls back to parent product's main image when empty (FR-027) |

A `thumb` conversion is registered for list rendering. No feature-specific file table is created
(Constitution Principle IV).

---

## 8. Relationships

```
Warehouse ──< InventoryOperation (source_warehouse_id)
Warehouse ──< InventoryOperation (destination_warehouse_id)
Warehouse ──< Package
Supplier  ──< InventoryOperation
User      ──< InventoryOperation (responsible_id)

InventoryOperation ──< InventoryOperationLine
InventoryOperation ──> morphTo sourceDocument   (PurchaseOrder | DeliveryNote)

InventoryOperationLine ──> ProductVariant
InventoryOperationLine ──> Unit
InventoryOperationLine ──> WarehouseLocation   (nullable)
InventoryOperationLine ──> Package             (nullable)
InventoryOperationLine ──> InventoryLot        (nullable)
InventoryOperationLine ──> SerializedInventoryUnit (nullable)

PackageType ──< Package
Package     ──< InventoryOperationLine
Package     ──< InventoryAdjustmentItem
Package     ──< InventoryMovement

Product        ──< ProductVariant
Product        ──< Media (images)
ProductVariant ──< Media (images)
```

---

## 9. Derived values — no new columns

| Value | Derivation | Change from today |
|---|---|---|
| On-hand | `inventory_stocks.on_hand_quantity` | none |
| Reserved | `inventory_stocks.reserved_quantity` | none |
| Available | `inventory_stocks.available_quantity` | none |
| **In transit** | sum of `inventory_operation_lines.quantity` where the parent is an internal transfer at stage `InTransit` and its `destination_warehouse_id` matches | Re-pointed from "transfer status = dispatched" to "operation stage = in_transit". Same shape, same semantics — see `InventoryStock::inTransitQuantity()` |
| Damaged | existing damage movement derivation | none |

This is the whole of the balance-layer change: one query re-pointed, nothing re-keyed.

---

## 10. Backfill and rollback

Per [R-002](./research.md):

1. Create the new tables empty.
2. Copy `inventory_receipts` → operations with `operation_type = receipt`, mapping
   `warehouse_id` → `destination_warehouse_id` and `confirmed` → `Done`; record
   `legacy_receipt_id`.
3. Copy `stock_transfers` → operations with `operation_type = internal_transfer`, mapping
   `from_warehouse_id` / `to_warehouse_id`, and `draft|dispatched|received` →
   `Draft|InTransit|Done`; record `legacy_transfer_id`.
4. Copy the corresponding item rows into `inventory_operation_lines`.
5. Run `OperationBackfillReconciler`: assert every
   `(product_variant_id, warehouse_id)` balance and the entire movement ledger are identical
   before and after, and that in-transit totals agree under both derivations.

**Rollback**: the legacy tables are never written to destructively during backfill, so rollback is
dropping the new tables. The `legacy_*_id` columns keep provenance until reconciliation is
verified, then are dropped in a follow-up migration.

**Not migrated**: `inventory_adjustments` and `inventory_adjustment_items` keep their own tables.
Adjustments and scraps sit in a separate section from the three transfer types and have no
source/destination pair, so folding them in would add columns that are always null.
