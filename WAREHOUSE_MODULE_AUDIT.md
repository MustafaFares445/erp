# Warehouse Module Audit

**Scope**: Read-only documentation of current behavior. No code was changed. Every claim below is evidence-tagged: **CONFIRMED** (verified by reading the code), **INFERRED** (reasonable conclusion from confirmed evidence, not directly proven), **NOT IMPLEMENTED** (searched for, absent), or **AMBIGUOUS** (could not be resolved from the repository alone). File:line citations are given throughout.

---

## 1. Executive Summary

This Laravel 13 / Filament 5 application implements warehouse/inventory management for a dental-lab ERP. The architecture is **mid-migration**: a legacy generation of separate documents (`InventoryReceipt`, `StockTransfer`) coexists with a newer **unified operation model**, `App\Models\InventoryOperation`, which represents Receipts, Deliveries, and Internal Transfers as one entity distinguished by an `operation_type` enum and driven through a single `Draft → Waiting → Ready → (InTransit) → Done → Canceled` lifecycle. Both generations are simultaneously live and can both mutate stock — the legacy paths are not dead code.

Stock quantity is a **materialized, per-(variant, warehouse) balance** (`InventoryStock`), kept in lockstep with an **append-only ledger** (`InventoryMovement`) by a single funnel service (`InventoryBalanceService`), inside DB transactions with row-level locking (`lockForUpdate()`). This is a well-engineered core: the audited concurrency scenario (two simultaneous deliveries against 1 unit of stock) is correctly blocked by pessimistic locking, and the DB schema has a composite unique key preventing duplicate stock rows.

LOT/batch tracking, expiry tracking, and serial-number tracking are all genuinely implemented, each keyed off a `product_type` enum on `Product` (`Machine` / `ExpiryMaterial` / `Grain`) that force-derives boolean tracking flags on every variant. FEFO exists only as a UI default-suggestion, not an enforced automatic-allocation algorithm — the operator always picks the lot manually.

The most significant **structural gap** is Units of Measure: there is no conversion-factor concept anywhere in the codebase. "1 box = 100 pieces" or "1 kg = 1000 g" cannot be modeled — `Unit` is a flat, unrelated label, and `product_units` is a display/order-entry allow-list with no numeric ratio. Warehouse hierarchy is intentionally flat (locations were dropped entirely); a `Package` concept (physical container) survives as the closest thing to sub-warehouse granularity. A **Returns** category exists in the UI/taxonomy (`MovementType::Return`, a "Returns" resource) but has no producing workflow anywhere — it is decorative. **Shipment** is confirmed to be a pure 1:1 tracking/confirmation wrapper around one Delivery operation with zero stock-mutation code, ruling out the double-deduction risk the brief asked about. **StockReservation** is a fully-built, entirely unused subsystem — the real reservation mechanism is an aggregate `reserved_quantity` column, not that table.

There is no API layer (`routes/api.php` does not exist) — Filament is the only entry point, which sidesteps the "API vs UI consistency" risk category by construction, at the cost of no external integration surface today.

---

## 2. Architecture Overview

**Core models**: `Product`, `ProductVariant` (+ `ProductAttribute`/`ProductAttributeValue`/`ProductVariantAttributeValue`), `Unit`, `Warehouse`, `InventoryStock`, `InventoryMovement`, `InventoryOperation` + `InventoryOperationLine`, `InventoryLot`, `SerializedInventoryUnit`, `InventoryAdjustment` + `InventoryAdjustmentItem`, `Shipment`, `StockReservation`, `Package` + `PackageType`. Legacy/parallel models still live: `InventoryReceipt` + `InventoryReceiptItem`, `StockTransfer` + `StockTransferItem`.

**Core services** (`app/Services/Inventory/`): `InventoryBalanceService` (sole writer of `InventoryStock`'s four balance columns), `InventoryOperationService` (unified lifecycle engine), `InventoryLotService`, `InventoryIdentityGuard` (SKU/serial/barcode uniqueness), `ProductTypeGuard` (per-product-type rule enforcement), `InventoryReceivingService` + `InventoryAdjustmentService` + `StockTransferService` (legacy/parallel write paths, still live), `InventoryDamageService`, `ReservationService`, `InventoryAlertService`, `SerializedInventoryTimelineService`, `InventoryOperationBackfiller` / `OperationBackfillReconciler` (one-time data-migration tooling).

**Filament resources**: `InventoryOperations` (the live UI for Receipt/Delivery/Internal Transfer), `InventoryReceipts` (legacy, still routed and writable), `Transfers` (legacy `StockTransfer`, still routed but not in the sidebar navigation), `Adjustments`, `Warehouses`, `StockLevels`, `StockMovements`, `StockReservations`, `InventoryLots` (read-only), `SerializedInventoryUnits` (read-only), `InventoryAlerts`, `InventoryReports`, `InventoryExports`, `InventoryImportRuns`, `InventorySettings`, `Returns` (read-only redirect into `StockMovements`).

**Events/Jobs**: `App\Events\InventoryOperationCompleted` (synchronous, drives `AdvancePurchaseOrderOnOperationCompleted`); `App\Jobs\GenerateInventoryExport`. No queued job mutates stock.

**Database**: `products`, `product_variants`, `product_attributes`/`product_attribute_values`/`product_variant_attribute_values`, `units`, `product_units`, `warehouses` (no `warehouse_locations` — dropped), `inventory_stocks`, `inventory_movements`, `inventory_operations`, `inventory_operation_lines`, `inventory_lots`, `serialized_inventory_units`, `inventory_adjustments`/`inventory_adjustment_items`, `stock_transfers`/`stock_transfer_items` (legacy), `inventory_receipts`/`inventory_receipt_items` (legacy), `shipments`, `stock_reservations`, `packages`/`package_types`, `inventory_alerts`, `activity_log`.

**API**: none. `routes/` contains only `web.php` and `console.php` — Filament (server-rendered) is the sole entry point into this domain.

---

## 3. Inventory Source of Truth

**Hybrid, CONFIRMED**: `InventoryStock` is a real, stored table (`database/migrations/2026_07_22_000006_create_inventory_stocks_table.php`) — `on_hand_quantity`, `reserved_quantity`, `damaged_quantity`, `available_quantity` are all persisted `decimal(15,3)` columns, unique per `(product_variant_id, warehouse_id)`. `InventoryMovement` is a parallel, append-only ledger recording a **signed delta** per event (no running-balance column), tagged with a free-form `source_type`/`source_id` pointer back to whatever document caused it (`'inventory_operation'`, `'receipt'`, `'adjustment'`, `'stock_damage'`, `'transfer'`, …). Both are written inside the same `DB::transaction()` by every audited code path, funneled through one class, `InventoryBalanceService::persist()` (`app/Services/Inventory/InventoryBalanceService.php:224-229`), which recomputes `available_quantity = on_hand - reserved - damaged` on every write. Reads always hit the stored `InventoryStock` row directly — nothing sums `InventoryMovement` at read time.

A concrete traced example (Opening 0 → Receipt +100 → Delivery −20 → Adjustment −5 → Transfer −10) resolves to **65**, confirmed by walking the actual service methods (`InventoryBalanceService::receive/transferOut/adjustTo`), not assumed.

---

## 4. Product / SKU Model

- SKU lives on **`ProductVariant`**, not `Product` (`database/migrations/2026_07_22_000003_create_product_variants_table.php:16`). Unique at the DB level (added by `2026_07_24_110900_extend_product_variants_and_inventory_references.php:32`) and pre-validated at the application level with soft-delete-aware uniqueness (`app/Services/Inventory/InventoryIdentityGuard.php:16-29`). **Manual entry, not auto-generated** — a plain required `TextInput` (`ProductVariantResource.php:83`), no generator/boot hook found.
- A separate, independently-unique **`barcode`** field also exists on `ProductVariant` (`.../extend_product_variants...php:23`), distinct from SKU and from serial numbers — a scannable identifier, optional, application- and DB-unique.
- Variants are built from `ProductAttribute` → `ProductAttributeValue` → `ProductVariantAttributeValue` (a pivot per variant). **AMBIGUOUS**: no uniqueness guard was found preventing two variants of the same product from sharing an identical attribute-value combination.
- Stock, movements, and operation lines all key to `product_variant_id`, never `product_id` — `Product` reaches stock only via a derived `hasManyThrough`. So "same product" (the `Product` row), "same SKU" (a `ProductVariant` row), and "same physical inventory" (an `InventoryStock` row, or an `InventoryLot`/`SerializedInventoryUnit` row within it) are three distinct, well-separated levels: a Product can have many SKUs (variants); each SKU has one stock balance per warehouse; that balance can be further broken down into many lots or many serial units.
- Tracking behavior (batches/expiry/serials) is driven by a `Product.product_type` enum (`Machine`/`ExpiryMaterial`/`Grain`) that force-derives `track_serials`/`track_expiry`/`track_batches` booleans on every variant via an observer, and is **locked once the product has stock history** (`Product::hasStockHistory()`, `app/Models/Product.php:158-165`).

---

## 5. UOM & Bulk Quantity

**NOT IMPLEMENTED — this is the most significant structural gap found.**

- `Unit` (`app/Models/Unit.php`) is a flat lookup: `name`, `symbol` (unique), `allows_decimal`, `is_active`. **No `conversion_factor`, `base_unit_id`, or `purchase_unit_id` column exists anywhere** — confirmed by an exhaustive grep across `app/` and every migration.
- `product_units` (migration `2026_08_26_073952...php`) is a plain pivot (`product_id`, `unit_id`, `is_default`) with **no dedicated model and no ratio column** — it is a set of alternate recording labels a product may be quantified in, not a UOM conversion hierarchy (confirmed by the form hint text: *"the units this product may have its quantity recorded in"*, `ProductForm.php:41`, and by `ProductUnitsTest.php`, which only tests allow-listing/default-selection, never conversion math).
- `ProductVariant` has exactly one `unit_id` (its stock/base unit). Nowhere in `InventoryOperationService`, `InventoryBalanceService`, or any receiving/delivery/transfer/adjustment path does a conversion multiplication/division occur before touching `on_hand_quantity` — the raw `quantity` value on a line is applied to the balance unchanged, regardless of which `unit_id` was selected on that line (`InventoryOperationService.php:595` et al.).
- Quantity precision: `decimal(15,3)` everywhere (stock, movements, lot/serial-adjacent quantities), fractional values allowed except where `ProductTypeGuard` forbids them (`Machine` type requires whole units; a `Unit` with `allows_decimal=false` forbids fractions). Rounding is confined to the one place actual arithmetic happens beyond straight addition — the derived `net_weight` display (`ProductVariant::weightFor()`, `round($quantity * $netWeight, 3)`) — which explicitly never feeds back into stock.
- **Net effect for the stated business scenarios** ("1 Box = 100 Pieces", "1 container = 25 kg, base unit = gram"): the system cannot represent these as one product with two linked units and a ratio. It can only be modeled today as two unrelated `Unit` rows with no numeric relationship — a receipt entered in "Box" and a delivery entered in "Piece" against the same variant would each add/subtract their raw numeric quantity to the same balance with no scaling, which is a real risk of stock corruption if operators mix units on one variant. **AMBIGUOUS** whether this is accepted product scope (single-unit-per-variant catalogs only) or an unaddressed gap — no test exercises two different `unit_id`s against one variant's balance.

---

## 6. LOT / Batch Tracking

**CONFIRMED, well-implemented.** `InventoryLot` (`app/Models/InventoryLot.php`) is a first-class table: `product_variant_id`, `warehouse_id`, `inventory_receipt_item_id`, `lot_number` (nullable, **no unique constraint** — uniqueness is enforced only in application code via a locked lookup), `expires_at`, `on_hand_quantity`, `reserved_quantity`. It is explicitly a *breakdown* of the aggregate `InventoryStock` balance by expiry/lot, never a replacement for it (doc comment, `InventoryLotService.php:27-29`).

- Multiple lots per SKU with independent quantities: **CONFIRMED** — no unique constraint prevents it; `availableLots()` returns a collection, not a single row.
- Lot creation: manual `lot_number` entry on the receiving line (`OperationLinesRepeater.php:125-129`), never auto-generated. An existing lot number **is reused/topped-up** across receipts when variant+warehouse+lot_number+expiry match (`InventoryLotService::receive()`, locked lookup-then-increment) — **but only on the unified `InventoryOperation` path**. The legacy `InventoryReceivingService::recordLot()` always inserts a new row, even for an identical key — an inconsistency between the two live receiving paths.
- Uniqueness scope: effectively per (variant, warehouse, lot_number, expiry) via application logic only — not product-global, not DB-enforced.
- A receipt can contain multiple lots for the same SKU (multiple lines, each naming its own lot).
- Transfers preserve lot identity as **two separate per-warehouse rows sharing the same `lot_number`** (not one row that moves) — consistent with `InventoryStock` itself being warehouse-scoped.
- Adjustments have **no lot-level field at all** — confirmed absent from every migration touching `inventory_adjustment_items`. Adjustments operate at the variant+warehouse aggregate level only.

---

## 7. Expiry Tracking

**CONFIRMED.** `expires_at` lives only on `InventoryLot` (mirrored transiently on `InventoryOperationLine.expires_at` for inbound lines that are still creating their lot). Two lots of the same SKU can and do carry different expiry dates — the lot lookup key itself includes `expires_at`.

- **Receiving an expired lot is blocked**: `ProductTypeGuard::assertInboundExpiry()` throws if the supplied date is in the past; the UI date-picker also enforces `minDate(today())`.
- **Delivering from an expired lot is blocked**, with a permission-gated override: `InventoryLotService::assertNotExpired()` throws unless the actor holds `InventoryPermission::ExpiredStockOverride`; using the override raises a critical alert and an audit log entry.
- **Picking is FEFO-suggested, not FEFO-enforced**: `InventoryLotService::availableLots()` orders candidates earliest-expiry-first and the UI pre-selects that lot as the default, but the operator can pick any other available (non-expired) lot from the dropdown — there is no automatic, silent allocation across lots. Grep for the literal strings "FIFO"/"FEFO" returns nothing; the behavior exists without that label.
- A background alert (`InventoryAlertService::syncExpiry()`) raises Warning/Critical alerts as a lot approaches or passes its expiry date — informational, not a hard gate on its own.

---

## 8. Serial Number Tracking

**CONFIRMED — individual rows, not a counter.** `SerializedInventoryUnit` is a full model, one row per physical unit: `serial_number` (DB-unique), `iot_number` (DB-unique, nullable — see §9), `status`, `warehouse_id`, `product_variant_id`, `inventory_receipt_item_id`. Status lifecycle (`SerializedInventoryUnitStatus`): `Pending, Available, InTransit, Delivered, AdjustedOut, Damaged, Disposed, Unknown`, with transitions driven by `InventoryOperationService` (receipt/dispatch/deliver/cancel) and `InventoryAdjustmentService` (in/out via exactly ±1-unit adjustments).

- **"Where is SN001 now?"** — directly answerable via `warehouse_id`, **with one confirmed inconsistency**: `Delivered` units keep their last `warehouse_id` (not cleared), while `AdjustedOut` units explicitly null it — the two "left custody" transitions behave differently, so `warehouse_id` alone is misleading for a delivered unit without also checking `status`.
- **"Which customer received it?"** — no direct `customer_id` column on the unit; must be derived by joining the unit → its operation line → the parent `InventoryOperation.customer_id`. No ready-made relation exists for this; it requires manual traversal.
- **Full history**: `SerializedInventoryTimelineService::events()` assembles a movement-history view from `InventoryMovement` rows — this is the system's actual "where has this been" mechanism, a derived query, not a stored location-history column.
- **Uniqueness**: real DB unique constraints on both `serial_number` and `iot_number`, reinforced by an application-level guard that also checks soft-deleted rows, plus per-line uniqueness constraints on adjustment/transfer item tables and an application check blocking the same serial appearing twice across live operations.
- **Can it appear in two warehouses at once?** No — one row, one `warehouse_id`, updated in place on every transition (not duplicated) for both the unified and legacy transfer paths.
- **Stock count vs. serial count**: both are updated inside the same enclosing `DB::transaction`, so they can't drift under normal operation — but no ongoing invariant check (serial count == on-hand quantity for a serialized variant) was found outside a one-time check at initial legacy receipt.

---

## 9. IoT / RFID / Barcode

- **IoT/RFID/NFC/BLE/Sensor**: **NOT IMPLEMENTED.** A repository-wide search found zero occurrences of any of these terms.
- **`iot_number`**: a column exists on `SerializedInventoryUnit` (unique, nullable) — despite the name, this is simply a second unique identifier field alongside `serial_number` (e.g., for a manufacturer's own device-registry ID), not an actual IoT integration, protocol, or device-communication feature. No IoT device/sensor/reader integration code exists anywhere.
- **Barcode**: a genuine, separate, unique, optional field on `ProductVariant` (§4) — distinct from SKU (product identity) and serial number (physical-unit identity). It functions as a scannable catalog identifier only; no scanning/hardware-integration code was found tied to it (it's a plain text field in forms/reports).
- **QR code**: not found anywhere in the codebase.

---

## 10. Receipts

**Two live, parallel implementations** — this is a critical scope note, not a minor detail:

1. **`InventoryOperation` (type=Receipt)** — the current/primary path, driven by `InventoryOperationService`.
2. **`InventoryReceipt`/`InventoryReceiptItem`** — a separate, still-fully-writable legacy model pair with its own routed Filament resource and its own service, `InventoryReceivingService`. It is actively used by the catalog-import feature (`CatalogImportApplicationService`), not dead code.

### Workflow (InventoryOperation path)

```
Draft (create, add lines)
  → markReady(): validates non-empty lines, locks variants, checks unit/quantity/serial
    rules, checks inbound expiry not in the past, checks no duplicate live serials,
    checks package↔warehouse match — no stock touched — stage → Ready
  → complete(): requires stage=Ready — receiveLines() creates/tops-up the lot,
    promotes any serial Pending→Available, calls InventoryBalanceService::receive()
    (THIS is the on-hand increment), records an InventoryMovement — stage → Done
    → fires InventoryOperationCompleted (advances linked Purchase Order if any)
```

- **`destination_warehouse_id` is required** for a Receipt (form-level + `OperationType::requiresDestinationWarehouse()`); **`supplier_id` is NOT required** (nullable at every layer).
- Lot/expiry/serial are entered directly on `InventoryOperationLine`.
- **Stock changes only at Ready→Done**, in one place, always the same code path (no other caller of `complete()` was found for Receipts) — an explicitly documented invariant, backed by a dedicated test.
- **Editing before Done**: fully allowed (`update()` policy = `isDraft()`), and safe by construction — since nothing is written to stock before Done, editing a Draft quantity (100→80) requires no correction.
- **Cancellation**: allowed pre-Done, blocked at Done (`cancel()` throws `immutable` if `stage===Done`). Since Receipt stock only ever changes at Done, and Done can't be cancelled, a completed Receipt has no reversal path via cancel.
- **Deletion**: policy-only guard (`delete()` = `isDraft()`; `forceDelete`/`deleteAny`/`forceDeleteAny` hard-coded `false`) — **AMBIGUOUS/caveat**: there is no model-level (`booted()`) guard preventing `$operation->delete()` if called directly outside the policy/Gate layer; the "undeletable once Done" guarantee is enforced only by the authorization layer, not defense-in-depth at the model.

### Legacy path (`InventoryReceipt`)

Confirm flow uses the same underlying `InventoryBalanceService::receive()` primitive but writes movements tagged `source_type='receipt'` (not `'inventory_operation'`), and is entirely separate from the `InventoryOperation` model until/unless a one-time backfiller copies it over (additive only, never re-touches stock).

---

## 11. Deliveries

Deliveries are `InventoryOperation` rows with `operation_type=Delivery`. `destination_warehouse_id` is not used (deliveries have no destination warehouse — goods leave the system); `source_warehouse_id` is required; `customer_id` is required (enforced at the Filament form/wizard layer only — **not** a DB NOT NULL constraint, and no `OperationType::requiresCustomer()` domain method exists, so a hypothetical future API/factory bypassing these specific forms would not be blocked).

- **Reservation, not `StockReservation`**: at Draft/Waiting→Ready, `reserveLines()` increments `InventoryStock.reserved_quantity` (and reserves against specific lots for batch-tracked variants). If stock is insufficient, the operation is held at **Waiting** instead of Ready — no error, no reservation made.
- **Deduction**: only at Ready→Done, via `fulfillReservationAndLeave()` → `InventoryBalanceService::transferOut()`, which itself re-checks `available_quantity >= quantity` under a row lock immediately before decrementing — this is the deepest, final gate against overselling, layered under three shallower checks (UI max-value, fulfillment-service pre-check, allocation-service pre-check).
- **Multi-lot delivery (LOT A=5, LOT B=10, deliver 8)**: recorded as **separate delivery lines per lot** (5 from A, 3 from B), each with its own `inventory_lot_id` — not collapsed into one total-quantity line. Same pattern for serialized products (one line per serial unit).
- **Cancellation**: pre-Done only, releases the reservation and lot hold; Done deliveries are immutable, with no reversal path.
- **Delivery documents** (payment receipt, packing list, invoice, etc., via a `DeliveryDocument` enum and Spatie media collections) are tracked as a completeness badge but are **not** a hard gate on `markReady()`/`complete()` — informational only.

---

## 12. Shipment — relationship to Delivery (critical audit point)

**Definitively resolved: Shipment is a pure 1:1 logistics/tracking wrapper around exactly one Delivery, with zero stock-mutation capability.**

- Cardinality: `shipments.inventory_operation_id` carries a DB `unique()` constraint — proves strict 1:1, not a grouping container.
- An exhaustive grep of `app/Services/Inventory/**` for `Shipment` returns nothing; `app/Services/Shipments/**` contains only tracking-number/status/media/confirmation logic (`confirmByAdmin/Customer/System` — each only writes `status`/`confirmed_by_*`/`confirmed_at`). No file in the Shipment layer references `InventoryStock`, `InventoryBalanceService`, or creates an `InventoryMovement`.
- Shipment creation happens once, immediately after a Delivery reaches **Ready** (`OrderFulfillmentService::createDeliveries()`), i.e. before any stock deduction — deduction still only happens later, at Done, exclusively through `InventoryOperationService::complete()`.
- **Conclusion: double-deduction is ruled out**, not merely "not found." Shipment status (`InTransit`→`Arrived`) is driven independently by admin/customer/system confirmation and has no path back into the balance service.

---

## 13. Internal Transfers

**Two implementations again**, but here the split is asymmetric: **`StockTransfer` (legacy) is fully writable but hidden from sidebar navigation** (absent from `AdminModuleRegistry`'s inventory-group item list, though its Filament resource still resolves by direct URL); **`InventoryOperation` (type=InternalTransfer) is the one linked from the UI** and is the only one of the two with cancellation support at all.

- **Warehouse-to-warehouse only** — confirmed consistent with the dropped `warehouse_locations` table; neither model has any location-level column.
- **Stock timing (`InventoryOperation` path)**: Draft — nothing; **Ready — reservation only** (`reserved_quantity` up, `on_hand_quantity` untouched); **InTransit — source `on_hand_quantity` decremented** (this is the point custody actually leaves, per the enum's own doc comment); **Done — destination `on_hand_quantity` incremented**. There is **no separate stored "in-transit" bucket** — it's a derived sum of operation lines whose parent is `stage=InTransit`, joined by destination warehouse.
- **Serial/lot identity across warehouses**: serials are the same row, `warehouse_id` updated in place (never duplicated) at each transition. Lots become **two independent per-warehouse rows sharing one `lot_number`** — e.g. LOT L001: A=100 → transfer 30 → A's row drops to 70, B gets a new/topped-up row at 30. The **legacy `StockTransfer` path has no lot-awareness at all** (`StockTransferService` never calls `InventoryLotService`).
- **Cancellation**: `InventoryOperation` supports it from any non-terminal stage, including a genuine reversal from InTransit (restores source on-hand, restores the lot, flips serials back to Available, writes a compensating movement). `StockTransfer` has **no cancel mechanism whatsoever** — once Dispatched, it can only proceed to Received; `TransferStatus` has no `Canceled` case.

---

## 14. Adjustments

**Confirmed: SET semantics, not delta.** The only fillable field on `InventoryAdjustmentItem` is `new_quantity` (the physical count); `old_quantity`/`difference` are computed server-side (`newQuantity - currentOnHand`) and then applied via `InventoryBalanceService::adjustTo()`, which writes the counted total directly as the new on-hand value.

- **Reason**: a required, free-text field (`text`, max 1000 chars, `NOT NULL`) — not a constrained enum/reference list.
- **Audit**: `created_by`/`updated_by` via `TracksBlameable`; no dedicated `approved_by`/`posted_by` column — the confirming actor is captured via `updated_by` plus a full activity-log entry (causer, before/after payload).
- **Lot**: no field at all on `inventory_adjustment_items` — adjustments are variant+warehouse aggregate only (optionally scoped to a `package_id`).
- **Serial**: optional, one unique serial per line, restricted to an **exactly ±1** difference — a serialized adjustment cannot represent an arbitrary multi-unit discrepancy in one line.
- **Missing-serial scenario (SN001, SN003 present; SN002 missing)**: the Adjustment mechanism can represent this only as one line naming SN002 with `new_quantity` one less than current, flipping it to `AdjustedOut` (not "lost"/"missing" — no such status exists in the enum). A separate, unrelated `InventoryDamageService` exists for marking a specific serial `Damaged`, but that is a different subsystem/UI, not reachable from the Adjustment resource. A passive alert (`InventoryAlertService::syncMissingDeviceIdentity()`) detects on-hand/serial-count mismatches automatically but doesn't resolve them.
- **Increase vs. Decrease vs. Damage**: increase/decrease share one code path (the sign just falls out of the count); Damage is an entirely separate service/subsystem (`InventoryDamageService`, its own `damaged_quantity` bucket, its own movement types).
- **Lifecycle**: `update()`/`delete()` both require Draft; confirmed adjustments are immutable and can never be un-confirmed; `forceDelete` is always denied (soft-delete only).
- **Ledger participation**: yes — every confirmed item writes an `InventoryMovement` (`source_type='adjustment'`), including a zero-quantity movement for an unchanged line — fully compatible with the ledger, distinguishable by `source_type`/`movement_type`. `Adjustment` has no case in the unified `OperationType` enum — it is a wholly separate mechanism from Receipt/Delivery/Transfer, despite sharing the same underlying ledger table.
- **Permissions**: `AdjustmentView`/`AdjustmentCreate`/`AdjustmentConfirm` are distinct permissions — an operator who can prepare a draft need not be able to confirm it (segregation of duties).

---

## 15. Inventory Ledger / Stock Movements

Every stock-mutating code path in the application funnels through **`InventoryBalanceService`**'s eight methods (`receive`, `transferOut`, `transferIn`, `adjustTo`, `reserve`, `releaseReservation`, `damage`, `recoverDamage`, `disposeDamage`) — confirmed to be the *sole* writer of `InventoryStock`'s balance columns (independently enforced by an architecture test using reflection, `tests/Feature/InventoryBalanceServiceTest.php`, and by `tests/Unit/ArchTest.php`, which bans Filament and Purchasing classes from touching `InventoryStock`/`InventoryMovement` directly).

Callers, each wrapping the balance write and its matching `InventoryMovement::forceCreate()` in one `DB::transaction`:

| Caller | Mutation | Ledger `source_type` |
|---|---|---|
| `InventoryOperationService` (receive/dispatch/complete/cancel) | receive / transferOut / reserve / releaseReservation | `inventory_operation` |
| `InventoryReceivingService` (legacy) | receive | `receipt` |
| `InventoryAdjustmentService` | adjustTo | `adjustment` |
| `StockTransferService` (legacy) | transferOut / transferIn | `transfer` |
| `InventoryDamageService` | damage / recoverDamage / disposeDamage | `stock_damage` |
| `ServiceRecordPartService` | transferOut / transferIn (spare parts) | (service consumption) |
| `ReservationService` | releaseReservation | — |

`InventoryMovement` rows are only ever created (`forceCreate`), never updated or deleted anywhere in the audited code — immutable by convention, not by a DB trigger/permission. No schema-level CHECK constraint or `unsigned` modifier prevents negative `on_hand_quantity`; the only guard is application-level, inside `InventoryBalanceService::persist()`, which throws before saving if any resulting value would be negative or inconsistent (`reserved+damaged > on_hand`).

---

## 16. Reservation / Allocation

**Two disconnected mechanisms exist, and only one is actually used.**

- The `StockReservation` model/table/policy/service is fully built (release/expire logic, Filament resource) but **nothing in live application code ever creates a row in it** — the only `StockReservation::create()` calls found are in a demo seeder and test factories.
- The **actual** reservation mechanism used by the Delivery/Transfer lifecycle is the `reserved_quantity` column on `InventoryStock` itself (plus a matching `reserved_quantity` on `InventoryLot` for batch-tracked variants), incremented at the Ready transition and decremented on cancellation or fulfillment.
- `available_quantity = on_hand_quantity - reserved_quantity - damaged_quantity` — a stored column, recomputed and persisted on every balance mutation, never computed live at read time.
- First-class inventory states, checked one by one: **Available** (implicit, via the quantity columns) — confirmed; **Reserved** — confirmed (`reserved_quantity`); **Allocated** — not implemented (no such term anywhere); **In Transit** — confirmed, but derived (summed from operation lines at stage=InTransit), not a stored bucket; **Damaged** — confirmed (`damaged_quantity` for bulk stock, a `Damaged` serial status for units); **Expired** — confirmed only as a lot attribute (`expires_at` comparison), not a stock-level status; **Quarantined / Blocked** — not implemented; **Consumed** — not implemented as a named state (closest analogues are `Delivered`/`Disposed`/`AdjustedOut` serial statuses).

---

## 17. Warehouse & Location Model

**Intentionally flat.** `Warehouse` (`name`, `code`, `address`, `latitude`, `longitude`, `is_active`) is the only physical-storage entity — `warehouse_locations` was fully dropped in a dedicated migration (`2026_07_28_042305_remove_warehouse_locations_from_inventory.php`), removing the column from every table that had referenced it (movements, receipt items, lots, serialized units, transfer items, adjustment items, operation lines, packages). No zone/shelf/bin hierarchy survives; a dedicated test (`WarehouseLocationTrackingTest`) explicitly asserts its absence.

A **`Package`**/`PackageType` concept (added shortly after the location drop) is the closest surviving sub-warehouse granularity: a physical container (box/pallet/crate) scoped to exactly one warehouse (`warehouse_id`, restrict-on-delete), attachable to receiving/transfer/delivery/adjustment lines via `package_id`, with a guard preventing its warehouse from silently changing once it's referenced by real transactions. It is a container reference, not a location — it cannot express "shelf 3, bin B," only "these units traveled in this specific box."

`InventoryStock` is unique per `(product_variant_id, warehouse_id)` — the same SKU independently exists in as many warehouses as it has stock rows, each with its own on-hand/reserved/available/damaged figures.

---

## 18. Returns / Reversals

**No functional return workflow exists — this is a confirmed gap, not merely absent-and-unverified.**

- No `CustomerReturn`/`SalesReturn`/`SupplierReturn`/`PurchaseReturn` model exists anywhere.
- A `MovementType::Return` enum case exists, and a dedicated **"Returns" Filament resource** (`app/Filament/Resources/Returns/ReturnResource.php`) is registered — but it is a **read-only view filtered to `movement_type='return'`**, whose index page immediately redirects into the generic Stock Movements list. No service anywhere in the codebase ever creates an `InventoryMovement` with `movement_type='return'` (confirmed by grep — only the two Filament display files reference the enum case, purely for badge coloring/filtering).
- **Conclusion: "Return" exists in the taxonomy and the UI navigation, but has no producing workflow.** A wrong Receipt or wrong Delivery today has no dedicated reversal document; the closest available correcting mechanism is a manual compensating **Adjustment** against the aggregate balance (no lot/serial nuance beyond what §14 supports), since neither a completed Receipt nor a completed Delivery can be cancelled or edited once Done (§10, §11).

---

## 19. Cancellation / Editing / Deletion Rules

| Operation | Draft | Post/Confirm | Edit After Post | Cancel | Delete | Stock Reversal |
|---|---|---|---|---|---|---|
| Receipt (`InventoryOperation`) | Editable, no stock effect | `complete()` → Done, stock +qty | **No** — blocked by policy + disabled form | Only pre-Done (never needed, since stock only changes at Done) | Only while Draft (soft) | N/A (never posted+cancelled together) |
| Receipt (legacy `InventoryReceipt`) | Editable | `confirm()`, stock +qty | AMBIGUOUS — not audited to same depth | AMBIGUOUS | AMBIGUOUS | AMBIGUOUS |
| Delivery (`InventoryOperation`) | Editable, reservation only at Ready | `complete()` → Done, stock −qty | **No** | Pre-Done only (releases reservation; no stock ever moved before Done) | Only while Draft (soft) | N/A once Done |
| Internal Transfer (`InventoryOperation`) | Editable | `dispatch()`→InTransit (source −qty), `complete()`→Done (dest +qty) | **No** | Pre-Done, including a genuine reversal from InTransit (restores source) | Only while Draft (soft) | **Yes**, explicit, from InTransit only |
| Internal Transfer (legacy `StockTransfer`) | Editable | dispatch (source −qty), receive (dest +qty) | No (Draft-only update) | **Not implemented at all** — no Canceled status exists | Only while Draft (soft) | None available |
| Shipment | N/A (auto-created) | Admin/Customer/System confirm → Arrived | Status only | N/A | AMBIGUOUS | N/A (no stock effect ever) |
| Adjustment | Editable | `confirm()`, stock = counted value | **No** — confirmed is immutable | **Not supported** — no un-confirm/void state | Draft-only (soft); `forceDelete` always denied | N/A |

Across every audited model, `forceDelete`/hard-delete is explicitly and unconditionally denied by policy. The one structural caveat repeated everywhere: "undeletable once posted" is enforced **only at the authorization/policy layer** — no model observed a `deleting`/`booted()` guard duplicating that protection at the Eloquent layer itself.

---

## 20. Concurrency & Transaction Safety

**Confirmed good practice on the primary path.** `InventoryBalanceService::transferOut()` (and every other guarded balance method) executes inside `DB::transaction()`, first acquiring a row lock via `SELECT ... FOR UPDATE` (`stockForUpdate()`) **before** checking `available_quantity`, and only then decrementing. Traced concurrency scenario: available=1, two simultaneous deliveries of 1 — the second transaction blocks on the lock until the first commits, then re-reads the now-current (zero) balance and correctly rejects. **No overselling is possible through this path.**

Caveats found, reported as observations:
- A pre-submission **preview** read (`InventoryOperationService::availableQuantity()`, used to populate "you have N available" in Filament forms) deliberately reads **without** a lock — informational only; the real gate still re-validates under lock at the actual mutation.
- `InventoryBalanceService` opens its own nested transaction per call; when invoked from inside an already-open outer transaction (every real caller does this), Laravel treats it as a savepoint — functionally atomic with the outer transaction, but its own 5-attempt deadlock-retry only replays the balance write, not the whole surrounding operation (e.g., the paired movement record), which is worth noting though not a proven bug.
- **No DB-level constraint** (no `unsigned`, no `CHECK`) independently prevents negative `on_hand_quantity` — the only protection is the application-level guard inside `persist()`.
- **No test in the suite exercises true concurrent access** (parallel processes/threads/forked requests). Existing "concurrency" tests simulate a race by sequentially re-invoking the same method twice within one test process — this proves the *state-machine* guard (e.g., "already processed") works, but does not empirically exercise the DB lock under real contention.

---

## 21. Permissions

Authorization is entirely policy-driven via a shared `ChecksInventoryPermissions` trait that default-denies any ability absent from a policy's permission map (this is how read-only ledgers — Movement, Stock, Lot, SerializedInventoryUnit — get "no write ability" for free). Key facts:

- **Segregation of duties is real**: creating vs. confirming a Receipt/Adjustment/Transfer are distinct permissions (`ReceiptCreate` vs `ReceiptConfirm`, etc.) — a preparer role need not have confirm rights.
- **Cost visibility is gated separately from quantity visibility**: `PricingView` is independent of `StockView`; report/table columns and a dedicated stock-value widget both hide cost figures from a viewer who lacks `PricingView`, even if they can see quantities.
- **No warehouse-manager/warehouse-staff role exists** — the only inventory-permission seeder assigns the entire 32-permission set directly to one admin user, not to a role; any additional user needs individual/manual permission grants.
- Every defined `InventoryPermission` enum case resolves to at least one enforcement point (a Policy class or a direct `Gate::authorize()`/`can()` call in a service) — none found completely unused.

---

## 22. Audit Trail

`created_by`/`updated_by` (via a shared `TracksBlameable` trait) exist on the operation/adjustment/transfer/receipt models; `InventoryOperation` additionally has `responsible_id`, `dispatched_at`/`completed_at`/`canceled_at`; `Shipment` has `confirmed_by_type`/`confirmed_by_id`/`confirmed_at` distinguishing admin/customer/system confirmation. Full "who/why" narrative detail (beyond created/updated) lives in a genuinely-wired **Spatie activity log** (`activity_log` table, replacing a dropped custom `audit_logs` table) — confirm/cancel/dispatch/receive/release/expired-override actions across every core service explicitly call `activity()->causedBy($actor)->log(...)` with before/after payloads. This is manual instrumentation per service method, not the `LogsActivity` Eloquent trait on the models themselves.

Answerable from schema alone: who received/delivered/adjusted/transferred (yes, via `created_by`/`responsible_id`/confirmation columns), when (yes), from/to which warehouse (yes), which lot/serial (yes, via FK columns on operation lines and movements). "Why" is only a free-text `notes`/`reason` field on the source document, not on the movement row itself — it must be looked up via the movement's `source_type`/`source_id` pointer.

---

## 23. Test Coverage

Coverage is strong for the unified `InventoryOperation` path and weak/absent for a specific handful of scenarios.

| Scenario | Status | Note |
|---|---|---|
| Receipt increases stock | Yes | Direct on-hand assertions in multiple files |
| Delivery decreases stock | Yes | Plain, lot-tracked, and serialized variants all covered |
| Cannot deliver unavailable stock | Yes | Held-at-Waiting path, allocation rejection, form-level clamp |
| Internal transfer preserves total inventory | Yes | Explicit `outTotal + inTotal = 0` assertion |
| Adjustment increases stock | Yes | Same test file as decrease |
| Adjustment decreases stock | Yes | |
| LOT tracking (multiple independent lots) | Partial | Top-up and named-draw covered; no test holds two distinct lots open simultaneously and moves them independently |
| Serial tracking | Yes | Full lifecycle across receipt/transfer/adjustment/damage |
| Expiry tracking (FEFO/FIFO) | **No** | Expiry validation is tested; automatic oldest-first *allocation* isn't (because it doesn't exist — picking is always operator-named) |
| Receipt cancellation + reversal | **No** | Done receipts are immutable by design; no test cancels a confirmed receipt because that path doesn't exist |
| Delivery cancellation + reversal | Yes (pre-Done only) | Consistent with immutability once Done |
| Concurrent delivery / race condition | **No** | Only sequential re-invocation is tested; no true parallel-process test exists |
| UOM/unit conversion correctness | **No** | Not tested because the feature does not exist |
| Serial-discrepancy handling (missing unit in count) | Partial | Only the reverse case (wrong-quantity-vs-named-serial rejection) is tested, not a physical-count-finds-fewer-serials reconciliation flow |

Test infrastructure: `RefreshDatabase` in effectively every test; a dedicated architecture test (`tests/Unit/ArchTest.php`) bans direct `InventoryStock`/`InventoryMovement` writes from Filament and Purchasing namespaces, keeping `InventoryBalanceService` the sole writer by construction; factories produce domain-realistic data (real UAE warehouse city names, type-driven variant states).

---

## 24. End-to-End Examples

**A — Normal material.** Receipt 100 → Delivery 25 → Adjustment −5. Traced through actual code: `on_hand` goes 0→100 (receive) →75 (transferOut) →70 (adjustTo with new_quantity=70, since adjustment is SET not delta — an operator must compute/enter 70 directly, not "−5"). **Result: 70**, matching the expected scenario, but note the adjustment step requires the operator to know and enter the *target* count, not a delta.

**B — LOT-controlled material.** LOT A=10 (exp 2027-01), LOT B=20 (exp 2027-09), deliver 15. The lot picker defaults to LOT A first (earliest expiry) but does not auto-split; an operator must build the delivery as two lines (e.g., 10 from A, 5 from B) — the system supports and correctly records this as two per-lot delivery lines with independent `inventory_lot_id`s, but does **not** compute the split automatically.

**C — Serialized device.** Receipt SN001/SN002/SN003 (Pending→Available). Deliver SN002 → status `Delivered`, `warehouse_id` **not cleared** (stays at last warehouse — a real, confirmed inconsistency vs. `AdjustedOut`, which does clear it). SN001/SN003 remain `Available` at the receiving warehouse. Aggregate on-hand decreases by 1.

**D — Internal Transfer.** Warehouse A=100, transfer 30 to B. After Draft: A=100, B=0 (nothing touched). After Ready: A=100 reserved 30, available 70; B=0 (still nothing moved). After InTransit (dispatch): **A's on-hand drops to 70**; B still shows 0 — the 30 units exist only as a derived "in-transit" query result (summed from the operation line), not a stored bucket anywhere. After Done (destination completes): **B's on-hand rises to 30**. Lot L001, if present, ends as two rows: A=70, B=30.

**E — Bulk material (25 kg → deliver 750 g).** **Not correctly supported as stated.** Because there is no unit-conversion mechanism (§5), the system cannot receive "25" under a "kg" unit and deduct "750" under a "g" unit against the same balance with correct scaling — both quantities would be treated as raw numbers against whatever single `unit_id` the variant carries. To make this scenario work today, the whole product's stock would have to be tracked in one consistent base unit (e.g., always grams: receive 25000, deliver 750), which depends entirely on operator discipline rather than any system-enforced conversion.

---

## 25. Potential Gaps / Risks

### Gap: No Unit-of-Measure conversion mechanism
**Evidence**: No `conversion_factor`/`base_unit_id`/`purchase_unit_id` field anywhere; `Unit` and `product_units` are flat labels (§5).
**Current behavior**: A line's raw numeric `quantity` is applied to the balance regardless of which `unit_id` is attached to that line.
**Expected WMS behavior**: Purchase-unit quantities are normalized to a base/stock unit via a stored ratio before touching on-hand balance.
**Risk**: Mixing units on receipts/deliveries for the same variant silently corrupts the balance; box-vs-piece and kg-vs-gram scenarios explicitly named in the brief cannot be modeled correctly today.
**Confidence**: High.
**Severity**: **CRITICAL** (for a domain that explicitly requires box/piece and kg/gram handling).

### Gap: Two live, parallel implementations for every core document type
**Evidence**: `InventoryReceipt`/`InventoryReceivingService` alongside `InventoryOperation`(Receipt); `StockTransfer`/`StockTransferService` alongside `InventoryOperation`(InternalTransfer) — both pairs independently writable today (§10, §13).
**Current behavior**: Both paths can post real stock movements for what is conceptually the same business event, through different code, with different lot-handling behavior (the legacy receipt path never de-duplicates same-lot receipts; the legacy transfer path has no lot-awareness at all, and no cancellation at all).
**Expected WMS behavior**: One authoritative implementation per document type in production use.
**Risk**: Divergent business rules (e.g. a transfer that can never be cancelled if entered via the legacy screen) and confusion for reviewers/auditors about which numbers are authoritative.
**Confidence**: High.
**Severity**: **HIGH**.

### Gap: No functional Return workflow
**Evidence**: `MovementType::Return` and a "Returns" Filament resource exist, but nothing ever creates a `return`-typed movement (§18).
**Current behavior**: A wrong Receipt or Delivery can only be corrected via a manual Adjustment against the aggregate balance (or, for a transfer, the InTransit-cancel reversal) — with no dedicated document, no automatic re-association with the original operation, and no lot/serial nuance for adjustments.
**Expected WMS behavior**: A Return document type that reverses a specific prior Receipt/Delivery with full traceability back to it.
**Risk**: Operators using ad-hoc Adjustments to fix mistakes lose the causal link back to the original wrong document, and lot/serial identity may not be correctable at all (adjustments carry no lot field).
**Confidence**: High.
**Severity**: **MEDIUM**.

### Gap: `StockReservation` is a fully-built but entirely dormant subsystem
**Evidence**: No production code path ever creates a row (§16).
**Current behavior**: The UI presents a "Stock Reservations" resource that will always be empty in real use; the actual reservation mechanism (an aggregate column) is invisible from that screen.
**Expected WMS behavior**: Either the reservation table is the live mechanism, or it doesn't exist as a user-facing resource.
**Risk**: Low direct data risk, but a real audit/reviewer trap — a screen exists whose absence of rows could be misread as "nothing is ever reserved," when reservations are in fact happening via a different mechanism entirely.
**Confidence**: High.
**Severity**: **LOW** (UX/architecture clarity, not a data-integrity issue).

### Gap: `Delivered` serialized units retain a stale `warehouse_id`
**Evidence**: `leaveSerializedUnits()` updates `status` but not `warehouse_id` on delivery; the equivalent `AdjustedOut` transition explicitly nulls it (§8).
**Current behavior**: A delivered unit's `warehouse_id` still points at the warehouse it left from, not "with the customer" — reading that column alone for a delivered unit is misleading.
**Expected WMS behavior**: A delivered/consumed unit should either null its warehouse or expose a clearly distinct "current location: customer" concept.
**Risk**: A naive report/query joining on `warehouse_id` without also filtering `status` could misreport where delivered devices "are."
**Confidence**: High.
**Severity**: **LOW**.

### Gap: No DB-level guard against negative on-hand quantity
**Evidence**: No `unsigned`/`CHECK` constraint on `inventory_stocks` columns; the only protection is inside `InventoryBalanceService::persist()` (§20).
**Current behavior**: Any write that bypassed the funnel service (none currently exist, enforced by an architecture test) could write a negative or inconsistent value directly.
**Expected WMS behavior**: Defense-in-depth via a DB constraint in addition to the application guard.
**Risk**: Low today given the architecture test's enforcement, but the protection is entirely convention-based, not schema-based — a future direct write (e.g. a raw migration data-fix, a new integration bypassing the service) would not be caught by the database itself.
**Confidence**: High.
**Severity**: **LOW**.

### Gap: No empirically-tested true concurrency
**Evidence**: All "concurrency" tests are sequential re-invocations within one process; no parallel-process/thread test exists (§20, §23).
**Current behavior**: The locking code (`lockForUpdate()` + transaction) is present and its logic is sound on inspection, but has never been exercised by an actual multi-connection race in the test suite.
**Expected WMS behavior**: At least one test using real parallel DB connections to empirically validate the lock.
**Risk**: A regression that silently removes/weakens the lock could pass the entire existing suite undetected.
**Confidence**: Medium (the code reads correctly; the absence of an empirical test is the gap, not a proven bug).
**Severity**: **MEDIUM**.

### Gap: Product/Unit combination has no anti-drift protection after stock exists
**Evidence**: `ProductObserver` locks `product_type` once stock history exists, but no equivalent guard was found for `ProductVariant.unit_id` (§5, §6, marked AMBIGUOUS by the audit).
**Current behavior**: A variant's base unit could potentially be changed on the Filament form even after it has stock history, with no re-scaling of existing balances.
**Expected WMS behavior**: Lock the stock/base unit once a variant has any stock history, mirroring the `product_type` lock.
**Risk**: Silent unit-label drift against an unchanged numeric balance.
**Confidence**: Low (not exhaustively verified against every observer/policy file — flagged ambiguous by the researching agent, not confirmed absent).
**Severity**: **INFORMATIONAL**.

### Gap: No production role model for inventory permissions
**Evidence**: The only permission seeder assigns all 32 `inventory.*` permissions directly to one admin user, not to a role (§21).
**Current behavior**: There is no "Warehouse Manager"/"Warehouse Staff" role scaffolded anywhere in seeders.
**Expected WMS behavior**: Role-based permission bundles reflecting real job functions.
**Risk**: Low for a system still early in rollout; becomes an operational risk once multiple real users need differentiated access.
**Confidence**: High.
**Severity**: **INFORMATIONAL**.

---

## 26. Ambiguities / Questions

1. Whether mixing two different `unit_id` values against operation lines for the *same* variant is an accepted, intentional scope limitation (single-unit-per-variant catalogs only) or an unaddressed gap — no test or code comment settles intent either way.
2. Whether any guard prevents changing `ProductVariant.unit_id` after the variant has stock history (not exhaustively verified across every observer/policy file).
3. Whether `InventoryReceipt`/`InventoryReceiptItem` (legacy receipts) and `StockTransfer` (legacy transfers) are slated for removal, or intended to remain a permanent parallel path for the catalog-import feature specifically — no roadmap artifact in the repository states this.
4. Whether the two-receiving-path lot-reuse inconsistency (unified path tops-up an existing lot; legacy path always inserts a new row for an identical lot key) is a known, accepted difference or an oversight.
5. Whether any code outside the audited services ever reads `SerializedInventoryUnit.warehouse_id` for a `Delivered` unit in a way that would be misled by the stale value (not exhaustively checked across every report/widget).
6. Whether production role/permission assignments (beyond the single seeded admin user) exist in an environment-specific seeder not reviewed in this pass.
7. Whether `InventoryPolicyAuthorizationTest`/`InventoryAdministrationResourceTest` (referenced by the permissions audit but not fully read) provide additional coverage for `WarehousePolicy::delete()`'s reference-check or `ShipmentPolicy`.

---

## 27. Files Reviewed

**Models**: `Product.php`, `ProductVariant.php`, `ProductAttribute.php`, `ProductAttributeValue.php`, `ProductVariantAttributeValue.php`, `Unit.php`, `Warehouse.php`, `InventoryStock.php`, `InventoryMovement.php`, `InventoryOperation.php`, `InventoryOperationLine.php`, `InventoryLot.php`, `SerializedInventoryUnit.php`, `InventoryAdjustment.php`, `InventoryAdjustmentItem.php`, `Shipment.php`, `StockReservation.php`, `Package.php`, `PackageType.php`, `InventoryReceipt.php`, `InventoryReceiptItem.php`, `StockTransfer.php`, `StockTransferItem.php`.

**Enums**: `OperationType.php`, `OperationStage.php`, `ProductType.php`, `SerializedInventoryUnitStatus.php`, `ShipmentStatus.php`, `ShipmentConfirmationSource.php`, `DeliveryType.php`, `DeliveryDocument.php`, `TransferStatus.php`, `MovementType.php`, `InventoryPermission.php`, `InventoryAlertType.php`, `InventoryAlertSeverity.php`.

**Services** (`app/Services/Inventory/`, `app/Services/Shipments/`): `InventoryBalanceService.php`, `InventoryOperationService.php`, `InventoryReceivingService.php`, `InventoryLotService.php`, `InventoryIdentityGuard.php`, `ProductTypeGuard.php`, `InventoryAdjustmentService.php`, `InventoryDamageService.php`, `StockTransferService.php`, `ReservationService.php`, `InventoryAlertService.php`, `SerializedInventoryTimelineService.php`, `InventoryOperationBackfiller.php`, `OperationBackfillReconciler.php`, `CatalogImportApplicationService.php`, `ShipmentService.php`, `ShipmentAttachmentSynchronizer.php`, `DeliveryDocumentSynchronizer.php`; `app/Services/Orders/OrderFulfillmentService.php`, `DeliveryWarehouseAllocationService.php`.

**Policies**: all `app/Policies/Inventory*.php`, `ShipmentPolicy.php`, `StockReservationPolicy.php`, `StockTransferPolicy.php`, `WarehousePolicy.php`, plus `Concerns/ChecksInventoryPermissions.php`.

**Migrations**: the full warehouse/inventory-relevant subset listed across §2–§18, including the location-drop, package-creation, shipment-creation, product-type/unit, and lot/serial migrations enumerated in-line above.

**Filament**: `InventoryOperations/**`, `InventoryReceipts/**`, `Transfers/**`, `Adjustments/**`, `Warehouses/**`, `StockLevels/**`, `StockMovements/**`, `StockReservations/**`, `InventoryLots/**`, `SerializedInventoryUnits/**`, `Returns/**`, `Products/**`, `ProductVariants/**`.

**Tests**: `tests/Feature/Inventory/*.php` (OperationLifecycleTest, OperationGuardsTest, OperationStockEffectTest, OperationInTransitTest, OperationPreviewTest, OperationBackfillReconciliationTest, ConfirmAdjustmentTest, ConfirmTransferTest, InventoryLotServiceTest, ExpiryMaterialOperationTest, GrainOperationTest, MachineOperationTest, OperationDeliveryNoteIntegrationTest, DeliveryDocumentSynchronizerTest, ShipmentStatusAndMediaTest), `tests/Feature/SerializedInventoryTrackingTest.php`, `tests/Feature/InventoryBalanceServiceTest.php`, `tests/Feature/InventoryDamageServiceTest.php`, `tests/Feature/InventoryReceivingServiceTest.php`, `tests/Feature/InventoryPolicyAuthorizationTest.php`, `tests/Feature/Purchasing/PurchaseOrderReceivingTest.php`, `PurchaseOrderOverReceiptTest.php`, `tests/Feature/CatalogImportServiceTest.php`, `tests/Feature/Sales/QuotationTouchesNoStockTest.php`, `tests/Feature/Filament/InventoryOperationResourceTest.php`, `TransferResourceTest.php`, `StockReservationResourceTest.php`, `tests/Unit/ArchTest.php`.

---

# Warehouse Behavior Snapshot For External Review

**Product Identity:** A `Product` groups one or more `ProductVariant` rows via configurable attributes (e.g. size/shade). All stock, pricing, and tracking behavior attaches to the variant, never the product directly.

**SKU:** Lives on the variant, manually entered (no auto-generation), unique at both the database and application layer. A separate, also-unique `barcode` field exists independently of SKU. No enforced uniqueness on the underlying attribute-value combination that defines a variant.

**Quantity Source of Truth:** A materialized per-(variant, warehouse) balance table (`InventoryStock`: on-hand / reserved / damaged / available), kept in step with an append-only signed-delta ledger (`InventoryMovement`) by one funnel service, inside DB transactions with row-level pessimistic locking. Reads always hit the materialized balance, never the ledger.

**Units of Measure:** No conversion-factor mechanism exists at all. A variant has exactly one base unit; a product may allow recording in several unit labels for order-entry purposes, but none of them carry a numeric ratio to each other or to the base unit. Box-vs-piece and kg-vs-gram scenarios as described cannot be represented correctly — this is the single most significant gap found.

**LOT Tracking:** Real, well-implemented. A lot is a per-(variant, warehouse, lot_number, expiry) breakdown of the aggregate balance, manually keyed on receipt, topped-up on repeat receipt of the same lot (on the current path only — the legacy receiving path does not do this de-duplication), split into independent per-warehouse rows on transfer. No lot field exists on Adjustments.

**Expiry:** Attached only to the lot (not to product/variant/serial). Receiving an already-expired date is blocked outright; delivering from an expired lot is blocked unless the actor holds a specific override permission (logged and alerted). Picking defaults to earliest-expiry-first in the UI but is always operator-confirmed, never automatic — FEFO-suggested, not FEFO-enforced. No FIFO/FEFO terminology appears in the code; the behavior exists without the label.

**Serial Tracking:** Real, one row per physical unit, full status lifecycle (Pending/Available/InTransit/Delivered/AdjustedOut/Damaged/Disposed), DB-unique serial and a second DB-unique "iot_number" identifier (not an actual IoT feature — just a second ID field). Current warehouse is directly queryable except for delivered units, whose warehouse field is stale (not cleared) rather than reflecting "with the customer." "Which customer received this serial" requires a manual join through the delivery operation; there is no direct column for it.

**Warehouses:** Flat entities only (name/code/address/geo-coordinates). No sub-warehouse location hierarchy exists — it was deliberately removed. A `Package` (physical container) concept is the closest surviving sub-warehouse granularity, scoped to exactly one warehouse.

**Locations:** Not implemented (removed).

**Receipt:** Two live, parallel implementations exist (a unified operation model and a separate legacy model+service, the latter still actively used by a catalog-import feature). On the unified path: Draft→Waiting→Ready→Done, with stock incrementing only at Done, through one exclusive code path. Editable and cancellable freely before Done; immutable, uncancellable, and undeletable after Done (enforced at the authorization layer only, not the model layer).

**Delivery:** Same unified lifecycle; reserves an aggregate quantity at Ready (not via the separate, unused `StockReservation` table), deducts only at Done with a final row-locked availability check as the last line of defense against overselling (backed by three shallower checks earlier in the flow). Splits across lots/serials are recorded as separate lines, fully traceable. Immutable after Done.

**Shipment:** A pure 1:1 tracking/confirmation wrapper around exactly one Delivery (enforced by a DB unique constraint on the link). Confirmed, exhaustively, to have zero code path capable of mutating stock — the double-deduction risk this audit was asked to check for is definitively ruled out.

**Internal Transfer:** Warehouse-to-warehouse only. On the unified/current path: reservation at Ready, source decrement at InTransit (the point custody actually leaves), destination increment at Done; genuine cancellation-with-reversal supported even from InTransit. A separate legacy transfer model is still fully writable and reachable by direct URL though hidden from the sidebar — it has no lot-awareness and no cancellation capability at all, an inconsistency worth resolving.

**Adjustment:** Set-quantity semantics (the operator enters the new counted total, not a delta) — a wholly separate mechanism from the unified operation lifecycle, though it writes to the same underlying ledger. Requires a free-text reason. No lot-level correction exists. A serial-level correction is limited to exactly one unit per line and has no "lost/missing" status — only "adjusted out." Immutable once confirmed, with a distinct confirm-permission separate from create-permission.

**Reservation:** A fully-built, entirely unused Filament-facing table/service exists (`StockReservation`) — real reservations are tracked instead via an aggregate `reserved_quantity` column, invisible from that screen. This is a genuine architectural inconsistency worth flagging.

**Returns:** Not implemented as a functional workflow. A "Returns" navigation entry and movement-type category exist in the UI/taxonomy, but nothing in the codebase ever produces a return movement — it is a defined-but-dead feature stub. Correcting a wrong Receipt or Delivery today has no dedicated document; only a manual, aggregate-level Adjustment is available.

**Stock Reversal:** Supported explicitly only for an Internal Transfer cancelled while InTransit. Receipts and Deliveries have no reversal path once Done — because they never write stock before Done, and cannot be cancelled or edited after Done.

**Concurrency Protection:** Real and correctly implemented on the audited path — pessimistic row locking inside a DB transaction, checked immediately before every balance write. The traced two-simultaneous-deliveries-against-one-unit scenario correctly rejects the loser. No DB-schema-level constraint backs this up independently (no CHECK, no unsigned column) — protection is entirely at the application layer, and no test in the suite empirically exercises true multi-connection concurrency (existing "concurrency" tests are sequential re-invocations).

**Audit Trail:** Created/updated-by fields plus a genuinely wired Spatie activity log (manually instrumented per service action, not via an Eloquent trait) capture who did what and when, down to lot/serial/warehouse level, for every core action. Free-text reason/notes exist per document but not per individual ledger row.

**Permissions:** Fine-grained, policy-driven, with genuine separation between "create/prepare" and "confirm/post" for every document type, and between viewing quantities and viewing cost/pricing data. No production role model exists yet — all permissions are currently seeded directly onto a single admin account.

**Major Known Risks:** (1) No unit-of-measure conversion — the single biggest gap for this business domain. (2) Two independently-writable implementations of Receipt and of Internal Transfer, with materially different rule sets (lot handling, cancellation) between them. (3) A dead "Returns" feature stub that could mislead a reviewer into thinking returns are handled. (4) An unused `StockReservation` screen that could equally mislead a reviewer about how reservation actually works. (5) Delivered serialized units retain a stale warehouse reference. (6) No DB-level negative-quantity guard, and no empirically-tested concurrency, despite sound-looking application-level locking code.

**Unknown / Requires Business Decision:** Whether the legacy Receipt/Transfer paths should be retired or are permanently needed (e.g., for catalog import); whether mixing multiple units per variant is in scope at all (which determines whether the UOM gap needs a conversion-factor feature or just a "single base unit only" policy); whether a real Return document type should be built, or whether Adjustment is considered sufficient; whether role-based permission bundles are needed before wider rollout.
