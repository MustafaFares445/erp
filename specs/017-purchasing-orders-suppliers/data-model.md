# Phase 1 Data Model: Purchasing — Purchase Orders and Supplier Confirmations

**Feature**: `017-purchasing-orders-suppliers` | **Date**: 2026-08-18

This document is the authoritative schema for the feature. Per Principle I, `Docs/database/ERD.md` must be updated to match **before** any migration is written.

Conventions follow the existing codebase: `bigint unsigned` keys, `decimal(15,3)` quantities, `decimal(15,2)` money, `created_by` / `updated_by` blameable columns via `TracksBlameable`, `deleted_at` soft deletes on documents, and enum-backed string status columns.

---

## 1. Entity Relationship Overview

```
suppliers (existing) ──< purchase_orders ──< purchase_order_lines
     │                        │                      │
     │                        │                      └──> product_variants (existing)
     │                        │                      └──> units (existing)
     │                        │                      └──> supplier_product_references (existing, nullable)
     │                        │
     │                        └──> warehouses (existing, destination)
     │                        │
     │                        └──< inventory_operations (existing, via source_document morph)
     │
     └──< supplier_confirmations >── confirmable morph ──> purchase_orders
                                                      └──> orders (existing customer order)

purchase_settings (singleton)
```

---

## 2. Table: `purchase_orders` *(new — ERD extension E-1)*

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `purchase_order_number` | varchar(100) | No | | Human-readable number, unique across all rows including soft-deleted |
| `supplier_id` | bigint unsigned | No | | Supplier, `restrictOnDelete` |
| `destination_warehouse_id` | bigint unsigned | No | | Receiving warehouse, `restrictOnDelete` |
| `status` | varchar(30) | No | `draft` | Lifecycle state, see §5 |
| `currency_code` | char(3) | No | | Document currency; all lines share it |
| `ordered_at` | date | No | | Order date |
| `expected_at` | date | Yes | null | Buyer's expected arrival date |
| `total_amount` | decimal(15,2) | No | 0 | Stored sum of line totals (R-008) |
| `submitted_by` | bigint unsigned | Yes | null | User who submitted for approval |
| `submitted_at` | timestamp | Yes | null | Submission timestamp |
| `approved_by` | bigint unsigned | Yes | null | Approver; equals `submitted_by` on auto-approval (R-004) |
| `approved_at` | timestamp | Yes | null | Approval timestamp |
| `rejection_reason` | text | Yes | null | Why approval was declined |
| `sent_at` | timestamp | Yes | null | Transmission timestamp; immutability boundary (FR-025) |
| `closed_at` | timestamp | Yes | null | Short-close timestamp |
| `closure_reason` | text | Yes | null | Why the outstanding quantity was abandoned |
| `cancelled_at` | timestamp | Yes | null | Cancellation timestamp |
| `cancellation_reason` | text | Yes | null | Why the order was voided |
| `notes` | text | Yes | null | Free-form buyer notes |
| `created_by` | bigint unsigned | Yes | null | Blameable |
| `updated_by` | bigint unsigned | Yes | null | Blameable |
| `created_at` | timestamp | No | current timestamp | |
| `updated_at` | timestamp | No | current timestamp | |
| `deleted_at` | timestamp | Yes | null | Soft delete (FR-018) |

**Indexes**: primary key on `id`; unique on `purchase_order_number`; index on `supplier_id`, `destination_warehouse_id`, `status`, `ordered_at`, `created_at`; composite index on `(status, supplier_id)` for the open-commitments report.

**Constraints**: foreign keys on `supplier_id` and `destination_warehouse_id` restrict deletion; `submitted_by`, `approved_by`, `created_by`, `updated_by` null on user deletion.

**Notes**: `total_amount` is service-owned and recomputed only while `status = draft`. Every status-changing column (`submitted_*`, `approved_*`, `sent_at`, `closed_*`, `cancelled_*`) is service-owned and therefore **not** fillable, mirroring how `InventoryOperation` treats `stage` and `operation_number`.

---

## 3. Table: `purchase_order_lines` *(new — ERD extension E-2)*

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `purchase_order_id` | bigint unsigned | No | | Parent, `cascadeOnDelete` |
| `product_variant_id` | bigint unsigned | No | | Ordered variant, `restrictOnDelete` |
| `unit_id` | bigint unsigned | No | | Ordering unit, `restrictOnDelete` |
| `supplier_product_reference_id` | bigint unsigned | Yes | null | Price provenance (FR-013), `nullOnDelete` |
| `supplier_item_number` | varchar(100) | Yes | null | Snapshot at draft time |
| `quantity_ordered` | decimal(15,3) | No | | Must be > 0 |
| `quantity_received` | decimal(15,3) | No | 0 | Cumulative; service-owned, never fillable |
| `unit_cost` | decimal(15,2) | No | 0 | Ordered cost per unit; must be >= 0 |
| `last_received_unit_cost` | decimal(15,2) | Yes | null | Most recent actual cost (FR-043) |
| `line_total` | decimal(15,2) | No | 0 | Stored `quantity_ordered * unit_cost` (R-008) |
| `expected_at` | date | Yes | null | Per-line expected arrival |
| `created_at` | timestamp | No | current timestamp | |
| `updated_at` | timestamp | No | current timestamp | |

**Indexes**: primary key on `id`; index on `purchase_order_id`, `product_variant_id`, `supplier_product_reference_id`; **unique on `(purchase_order_id, product_variant_id, unit_id)`** — this is what makes FR-014's duplicate rejection a database guarantee rather than a validation-only rule, and what makes receipt attribution unambiguous.

**Notes**: No soft delete — lines belong to their order's lifecycle. `quantity_received` is written only by the receipt-completion listener holding a row lock (R-003).

---

## 4. Table: `supplier_confirmations` *(new table, ERD-defined shape with extensions E-3, E-4, E-5)*

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `confirmable_type` | varchar(255) | No | | Morph type: `PurchaseOrder` or `Order` only (E-3) |
| `confirmable_id` | bigint unsigned | No | | Morph id |
| `supplier_id` | bigint unsigned | No | | Supplier asked, `restrictOnDelete` |
| `confirmation_status` | varchar(30) | No | `pending` | `pending` / `confirmed` / `rejected` |
| `promised_at` | date | Yes | null | Supplier's committed date (E-5) |
| `confirmed_by` | bigint unsigned | Yes | null | Admin who recorded the answer |
| `confirmed_at` | timestamp | Yes | null | When the answer was recorded |
| `notes` | text | Yes | null | Supplier discussion notes |
| `created_by` | bigint unsigned | Yes | null | Blameable |
| `updated_by` | bigint unsigned | Yes | null | Blameable |
| `created_at` | timestamp | No | current timestamp | |
| `updated_at` | timestamp | No | current timestamp | |

**Indexes**: primary key on `id`; morph index on `(confirmable_type, confirmable_id)`; index on `supplier_id`, `confirmation_status`, `created_at`.

**Divergence from ERD**: `order_id` becomes the morph (E-3); the ERD's redundant generic `status` column is dropped, leaving `confirmation_status` as the only lifecycle column (E-4); `promised_at` is added (E-5). No soft delete — the record is append-only evidence (R-007).

---

## 5. Table: `purchase_settings` *(new — ERD extension E-6)*

Singleton, following the `inventory_settings` precedent exactly.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `approval_threshold_amount` | decimal(15,2) | No | 0 | At or below this, submission auto-approves |
| `approval_threshold_currency` | char(3) | No | `AED` | Currency the threshold is expressed in |
| `created_at` | timestamp | No | current timestamp | |
| `updated_at` | timestamp | No | current timestamp | |

**Notes**: A default of `0` means every order requires explicit approval until the owner sets a value — the safe default. Threshold comparison applies only when the order's currency matches the threshold currency; a mismatched currency always routes to explicit approval, since no conversion exists in this feature.

---

## 6. Modified Table: `orders` *(existing — ERD extension E-7, minimal)*

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `pending_reason` | varchar(100) | Yes | null | Why the order awaits a supplier (FR-033) |

Adds three values to the existing `status` column's accepted set: `pending_supplier_confirmation`, `supplier_confirmed`, `supplier_rejected` — all already present in the ERD's status catalog.

**Explicitly not added**: `supplier_id`, `payment_status`, `grand_total` (R-012). These belong to the future sales/accounting feature.

---

## 7. Reused Tables — No Schema Change

| Table | Role |
|---|---|
| `suppliers` | Purchase-order supplier and confirmation counterparty |
| `supplier_product_references` | Cost defaulting source and writeback target |
| `product_variants`, `units` | Line entry |
| `warehouses` | Destination |
| `inventory_operations` | Receiving, via the **existing** nullable `source_document` morph and existing `supplier_id` / `supplier_reference` columns |
| `inventory_operation_lines` | Received quantities, lots, serials, expiry — unchanged |
| `inventory_stocks`, `inventory_movements` | Written **only** by `InventoryOperationService` (R-001) |
| `users` | Blameable, submitter, approver, confirmer |
| `activity_log` | Audit trail, per ADR 0005 |

---

## 8. Enums

### `PurchaseOrderStatus` (string-backed)

| Case | Value | Terminal |
|---|---|---|
| `Draft` | `draft` | No |
| `PendingApproval` | `pending_approval` | No |
| `Approved` | `approved` | No |
| `Rejected` | `rejected` | No |
| `Sent` | `sent` | No |
| `PartiallyReceived` | `partially_received` | No |
| `Received` | `received` | **Yes** |
| `Closed` | `closed` | **Yes** |
| `Cancelled` | `cancelled` | **Yes** |

**Transition matrix** — implemented as `canTransitionTo(self $target): bool`, mirroring `OperationStage::canTransitionTo()`:

| From | Permitted targets |
|---|---|
| `Draft` | `PendingApproval`, `Approved` (auto), `Cancelled` |
| `PendingApproval` | `Approved`, `Rejected`, `Cancelled` |
| `Rejected` | `Draft`, `Cancelled` |
| `Approved` | `Sent`, `Cancelled` |
| `Sent` | `PartiallyReceived`, `Received`, `Closed`, `Cancelled` |
| `PartiallyReceived` | `Received`, `Closed` |
| `Received` | — |
| `Closed` | — |
| `Cancelled` | — |

`Sent → Cancelled` and `PartiallyReceived → Cancelled` are further gated at the service layer: cancellation is refused once **any** receipt has completed (FR-026), which is why `PartiallyReceived` has no `Cancelled` target at all.

Helper predicates: `isReceivable()` returns true for `Sent` and `PartiallyReceived` (FR-036); `isEditable()` returns true only for `Draft` (FR-025); `isTerminal()` for the three terminal cases.

### `SupplierConfirmationStatus` (string-backed)

| Case | Value |
|---|---|
| `Pending` | `pending` |
| `Confirmed` | `confirmed` |
| `Rejected` | `rejected` |

`Pending → Confirmed` and `Pending → Rejected` only. Answered confirmations are immutable (FR-031).

### `PurchasePermission` (string-backed)

See `contracts/permissions.md` for the full catalogue and role mapping.

---

## 9. Validation Rules

| ID | Rule | Enforced at |
|---|---|---|
| V-01 | Supplier must be active and not soft-deleted on new orders | Form + service |
| V-02 | Destination warehouse must be active | Form + service, re-checked at receipt initiation (FR-044) |
| V-03 | At least one line before submission | Service |
| V-04 | `quantity_ordered > 0`, `unit_cost >= 0` | Form + service |
| V-05 | No duplicate `(product_variant_id, unit_id)` per order | Form + service + unique index |
| V-06 | Order is editable only while `status = draft` | Policy + service (both checkpoints, FR-025) |
| V-07 | Submitter ≠ approver for above-threshold orders, unless System Admin | Service (R-005) |
| V-08 | `quantity_received + incoming <= quantity_ordered` per line | Service under row lock (R-003) |
| V-09 | Confirmation target must be `PurchaseOrder` or `Order` | Service (FR-028) |
| V-10 | `promised_at >= ordered_at` of the target document | Form + service (FR-030) |
| V-11 | Answered confirmation is immutable | Policy + service (FR-031) |
| V-12 | Receipt initiation requires `status.isReceivable()` | Service (FR-036) |
| V-13 | Cancellation refused when any linked receipt is complete | Service (FR-026) |
| V-14 | One active `supplier_product_references` row per `(supplier_id, product_variant_id)` | Service + partial unique index where `is_active` |
| V-15 | Purchase-order number unique including soft-deleted rows | Unique index (FR-011) |

---

## 10. State Ownership

Columns written **only** by services, never mass-assignable — the same discipline `InventoryOperation` applies to `stage`:

- `purchase_orders`: `purchase_order_number`, `status`, `total_amount`, `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `rejection_reason`, `sent_at`, `closed_at`, `closure_reason`, `cancelled_at`, `cancellation_reason`
- `purchase_order_lines`: `quantity_received`, `last_received_unit_cost`, `line_total`
- `supplier_confirmations`: `confirmation_status`, `confirmed_by`, `confirmed_at`

---

## 11. Migration Order

1. `create_purchase_settings_table`
2. `create_purchase_orders_table`
3. `create_purchase_order_lines_table`
4. `create_supplier_confirmations_table`
5. `add_pending_reason_to_orders_table`
6. `add_active_supplier_product_reference_unique_index` *(V-14 backfill: resolve any existing duplicates before applying)*

Step 6 touches existing data. It must run a duplicate check first and fail loudly rather than silently deactivating rows.
