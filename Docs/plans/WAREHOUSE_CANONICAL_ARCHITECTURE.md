# Warehouse Canonical Architecture

## Purpose and decision boundary

This is the target architecture for the pre-production Product, Warehouse, and Inventory module. It is a remediation design, not a description of the current code. It makes one inventory-posting path authoritative without forcing every business document into one God model.

The requested primary source, `IERP_Product_Inventory_Module_SRS_AR.docx` / `IERP-SRS-PIM-001 v1.1`, is not present in this checkout. The in-repository derivative specification, `specs/014-inventory-erp-rework/spec.md`, records that document as its requirements source. This plan therefore follows the accessible specifications, constitution, and implementation, and isolates the few unresolved product decisions in the final section. The missing SRS must be attached and reconciled before implementation begins.

### Verified current implementation map

| Component | Purpose today | Writes stock or custody? | Canonical candidate | Status / decision |
|---|---|---:|---|---|
| `app/Services/Inventory/InventoryBalanceService.php` | Locks and persists aggregate `(variant, warehouse)` quantities | Yes | Internal balance repository only | Refactor; it is currently too public and cannot guarantee a movement or allocation accompanies every write. |
| `InventoryOperation` + `InventoryOperationService` | Receipt, delivery, and internal-transfer lifecycle | Yes | Operational-document wrapper | Keep and refactor to invoke the canonical posting service. Do not add adjustments or returns to this model. |
| `InventoryLotService` | Lot receipt, reservation, consumption, and restoration | Yes | Lot allocation component | Refactor into the posting transaction; current rows are balances, not a stable lot identity. |
| `InventoryAdjustment` + `InventoryAdjustmentService` | Count-to quantity correction | Yes | Adjustment document | Keep and refactor to allocation-aware posting. |
| `InventoryDamageService` | Damage, recovery, disposal actions | Yes | Condition-change document/workflow | Refactor onto the same posting contract and add a source document/audit link. |
| `StockReservation` + `ReservationService` | A visible source-linked reservation record and release action | Only release changes aggregate | Reservation aggregate + allocation | Refactor and make authoritative; production code currently creates no reservation rows. |
| `InventoryReceipt` + `InventoryReceivingService` | Legacy receipt workflow | Yes | None | Migrate catalog import, then delete. |
| `StockTransfer` + `StockTransferService` | Legacy transfer workflow | Yes | None | Migrate all callers/routes, then delete. |
| `ServiceRecordPartService` | Maintenance spare-part consumption/reversal | Yes | External caller of posting contract | Refactor; it presently changes aggregate stock without required lot/serial allocations. |
| `Shipment` + `ShipmentService` | Delivery tracking/arrival confirmation | No | Shipment-only workflow | Keep. It must never post stock. |
| `PurchaseOrderReceivingService` | Creates a receipt operation against a purchase order | No | Receipt initiator | Keep; update it to create UOM-normalized receipt lines. |
| `CatalogImportApplicationService` | Groups inventory import rows and creates legacy receipts | Indirectly, yes | Receipt initiator | Refactor to create canonical receipt operations directly. |
| `InventoryOperationBackfiller` + `OperationBackfillReconciler` | One-time legacy copy/reconciliation | No | None | Delete only after the legacy tables, provenance columns, and migration window are retired. |

### Verified mutation graph

The audit statement that `InventoryBalanceService` is the only aggregate writer is materially true for application code, but incomplete as an architecture claim: callers also write lots, serial custody, reservations, and ledger rows themselves. The current post paths are:

```text
InventoryOperationService
  -> InventoryBalanceService (aggregate)
  -> InventoryLotService (lot aggregate / reservation)
  -> SerializedInventoryUnit status change
  -> InventoryMovement row
  -> InventoryOperationCompleted -> Purchasing received-quantity listener

InventoryReceivingService [legacy]
  -> InventoryBalanceService
  -> creates InventoryLot
  -> assigns serialized units
  -> InventoryMovement row
  <- CatalogImportApplicationService

StockTransferService [legacy]
  -> InventoryBalanceService
  -> serialized-unit custody transition
  -> InventoryMovement row

InventoryAdjustmentService
  -> InventoryBalanceService
  -> optional serialized-unit transition
  -> InventoryMovement row
  ! no lot adjustment path

InventoryDamageService
  -> InventoryBalanceService
  -> optional serialized-unit transition
  -> InventoryMovement row

ServiceRecordPartService
  -> InventoryBalanceService
  -> InventoryMovement row
  ! no lot or serial allocation path

ReservationService
  -> InventoryBalanceService::releaseReservation
  -> StockReservation status
  ! the aggregate is normally reserved directly by operations; no active record is created
```

No runtime raw-SQL or `increment()` / `decrement()` path was found that writes `inventory_stocks` outside these application services. The control gap is that the public balance service can be invoked without a complete posting context; that is what the new boundary removes.

## Target architecture

```text
Business document
  InventoryOperation | InventoryAdjustment | InventoryReturn | ConditionChange
          |
          v
Document workflow service
  validates lifecycle, authorization, source document, transaction UOM
          |
          v
InventoryPostingService  [only public stock mutation entry point]
  validates allocations and locks all affected rows in deterministic order
          |
          +-- QuantityNormalizer       (transaction UOM -> immutable base quantity)
          +-- ReservationAllocator     (source-linked aggregate, lot, serial allocations)
          +-- TraceabilityAllocator    (lot / serial custody and condition transitions)
          +-- InventoryBalanceRepository [internal; materialized condition balances]
          +-- MovementRecorder         (immutable ledger rows)
          +-- ReconciliationGuard      (cross-grain invariants)
          |
          v
`inventory_stocks` + lot balances + serial current custody + reservations + movements
```

`InventoryPostingService` is the only service allowed to mutate a materialized balance, reservation allocation, lot balance, or serialized-unit custody. It receives a typed posting command containing a document source, actor, operation date, normalized base quantities, condition transition, and required allocations. It writes every affected row in one database transaction and locks stock, lot, serial, reservation, and document rows in a documented deterministic order.

`InventoryBalanceService` becomes an internal persistence collaborator, not an injectable cross-module API. A workflow may not call it and separately remember to create its movement. The posting service records the immutable movement as part of the same transaction or rolls everything back.

### Ownership and source of truth

| Concept | Owner / identity | Canonical source of truth | Stock effect |
|---|---|---|---|
| Product | Commercial catalog grouping | `products` | None directly. |
| Product variant | Stockable SKU; base UOM and tracking profile belong here | `product_variants` | Every balance is variant-scoped. |
| SKU / barcode / UDI | SKU identifies variant; barcode/GTIN/UDI are identifiers, not quantities | Variant identifier table or existing fields | None. Keep unique identifiers with explicit type. |
| Unit | Global named UOM in one physical family | `units` | None. |
| Variant UOM | A permitted transaction UOM and conversion to that variant's base UOM | new `product_variant_units` | Normalizes every document line. |
| Warehouse | Physical inventory custody boundary | `warehouses` | Balance dimension. |
| Location/bin | Not in current first release | None | Keep deliberately out of the balance key until approved. Packages remain containers, never balances. |
| Stock balance | `(variant, warehouse, condition)` materialized base quantity | refactored `inventory_stocks` | Current quantity read model. |
| Lot | Stable physical lot identity plus warehouse/condition lot balance | `inventory_lots` + `inventory_lot_balances` | Required for a lot-tracked variant. |
| Serial | One physical unit and its current custody/condition | `serialized_inventory_units` | Required for a serial-tracked variant. |
| Reservation | Source-linked commitment and allocations | renamed/refactored `inventory_reservations` + allocations | Reduces saleable availability, not physical on-hand. |
| Movement | Immutable posting record at the changed stock grain | `inventory_movements` | Audit/reconciliation ledger; never the main read model. |
| Receipt / delivery / transfer | Operational document types only | `inventory_operations` | Invoke the posting service at custody transitions. |
| Adjustment | A count/correction document | `inventory_adjustments` | Invokes allocation-aware posting. |
| Customer / supplier return | Separate inspected return document | new `inventory_returns` | Invokes an inbound or outbound posting after disposition. |
| Shipment | Logistics confirmation of a delivery | `shipments` | Never posts stock. |

### Product classification and tracking

Keep commercial categorisation on `Product`, but stop using the three current `ProductType` cases as the sole inventory-tracking model. `Machine`, `ExpiryMaterial`, and `Grain` cannot represent ordinary countable products, a serialised lot, or a non-expiring consumable without encoding business rules indirectly.

Move these inventory controls to a variant-owned immutable-after-history tracking profile:

| Setting | Allowed values | Rule |
|---|---|---|
| Base UOM | exactly one active variant UOM | All materialized quantities use it. |
| Quantity precision | decimal scale allowed by base UOM | Normalize and round only with the variant-UOM rule. |
| Lot tracking | off / required | Required means every stock-affecting allocation names a lot. |
| Expiry | off / required-on-receipt | Requires lot tracking and records expiry on the immutable lot identity. |
| Serial tracking | off / required | Required means one serialized unit per base quantity of one; a serial may optionally be associated with a lot. |
| Specific outbound allocation | suggested FEFO / mandatory explicit | Recommended default: explicit operator allocation, with FEFO suggestion for expiry-controlled lots. |

The existing `ProductObserver` already blocks a `product_type` change after stock history; the target must add the equivalent domain guard for a variant's base UOM and tracking profile. The guard is enforced in the write service and observer/policy, not only by a disabled Filament field.

## Canonical UOM model

### Design

The base UOM belongs to `ProductVariant`, because stock, price, tracking, and every current balance are variant-grained. A product may offer defaults for new variants, but it must not be the authority for a variant's conversion.

`units` becomes the global vocabulary with `code`, `name`, `symbol`, `family` (`count`, `mass`, `volume`, `length`, and so on), precision, and optional global factor to a family reference unit. `product_variant_units` is the authority for an item’s usable units:

| Field | Purpose |
|---|---|
| `product_variant_id`, `unit_id` | Unique permitted pair. |
| `is_base`, `is_purchase`, `is_sale`, `is_display` | Role flags; exactly one base row. |
| `factor_to_base` | Positive decimal conversion factor. |
| `rounding_increment` | Smallest valid transaction increment in this UOM. |
| `is_active` | Retire a UOM without rewriting history. |
| `effective_from`, `retired_at` | Auditability; a posted line keeps its snapshot. |

Examples: `Piece` is a variant's base UOM and `Box.factor_to_base = 100`; `Gram` is base and `Kilogram.factor_to_base = 1000`; `Container.factor_to_base = 25000` for that material. A global Mass family can propose kg-to-g factors, but it still creates or validates a variant-UOM row. `Box -> Piece` is always item-specific. A conversion across families is rejected unless a variant-specific conversion explicitly exists; it is never inferred from a label.

Every quantity-bearing document line stores both its user-entered and normalized values:

```text
transaction_quantity + transaction_unit_id + conversion_factor_snapshot
    -> base_quantity
```

The posting service consumes only `base_quantity`. It never branches on Box, Kilogram, or a form label. Price/cost documents retain the transaction UOM and quantity for commercial meaning, while receipt/delivery/transfer progress and stock reconciliation compare base quantities.

The system must reject a conversion that produces a quantity outside the base UOM's precision; it must not silently truncate. Use fixed decimal arithmetic and a documented scale (recommended `decimal(20,6)` for conversion and base quantity; display at the UOM's configured scale), not binary floating-point for persistence.

External reference: Microsoft Business Central uses an item base UOM for storage and a per-item quantity-per-UOM factor for alternates, which supports this split between global vocabulary and item-specific conversion. It also treats tracking numbers as information that must follow incoming and outgoing transactions. See [item units of measure](https://learn.microsoft.com/en-us/dynamics365/business-central/application/base-application/table/microsoft.inventory.item.item-unit-of-measure), [units of measure setup](https://learn.microsoft.com/en-gb/dynamics365/business-central/inventory-how-setup-units-of-measure), and [item tracking](https://learn.microsoft.com/en-us/dynamics365/business-central/inventory-how-setup-item-tracking). This informs the design only; IERP’s specifications remain authoritative.

### Document-line consequences

`InventoryOperationLine`, `PurchaseOrderLine`, `OrderLine`, and `QuotationLine` need transaction UOM and base-quantity snapshot fields. `AdvancePurchaseOrderOnOperationCompleted` must reconcile a receipt to a purchase-order line in base quantity, rather than the current `(variant_id, unit_id)` key. Quotation conversion must preserve the quoted UOM instead of always reading the variant's current `unit_id`.

## Stock conditions and reservations

Replace the current independent `on_hand_quantity`, `reserved_quantity`, `damaged_quantity`, and stored `available_quantity` columns with condition rows in `inventory_stocks`:

```text
unique (product_variant_id, warehouse_id, stock_condition)
stock_condition in: saleable, quarantine, damaged
on_hand_base_quantity >= 0
reserved_base_quantity >= 0 only for saleable
available saleable quantity = saleable.on_hand - saleable.reserved
```

`available` is derived at read time or a generated database value; it is not independently persisted. Total physical on-hand is the sum of Saleable, Quarantine, and Damaged. Expired is a derived eligibility state of a lot: expired stock remains physical stock in its condition but cannot be saleable/allocatable unless the explicitly audited expiry-override policy permits it. `in_transit` is not a destination on-hand row: it is a derived quantity from dispatched-but-unreceived transfer allocations.

Make `StockReservation` authoritative by migrating it to `InventoryReservation`, with `InventoryReservationAllocation` children. An active reservation contains source morph, variant, warehouse, saleable base quantity, status (`active`, `released`, `consumed`, `expired`), timestamps, and user/actor. Allocation children name a lot and/or serial where applicable. The only aggregate reservation is the sum of active reservation allocations:

```text
inventory_stocks.saleable.reserved_base_quantity
  = SUM(active reservation allocations for variant + warehouse + saleable condition)
  = SUM(active lot reservation allocations at the same grain)
```

At `InventoryOperation::markReady`, create the reservation and allocations first, then update the materialized reserved quantities through the posting service. On delivery/transfer dispatch, consume that reservation; on cancel/release/expiry, release it once. Never let a UI action create a balance-only reservation.

## Lot, serial, and custody model

`inventory_lots` becomes a stable identity: variant, normalized lot number, supplier/manufacturer fields when available, immutable expiry date, and receipt-origin trace. `inventory_lot_balances` owns the `(lot, warehouse, condition)` base quantity and reserved quantity. The current `InventoryLot` row combines identity and balance, which prevents a transfer from preserving one lot identity at both warehouses cleanly.

A lot-tracked receipt creates or finds the exact stable lot identity under a unique normalized key. A delivery, transfer, adjustment, reservation, return, damage, or maintenance consumption must carry allocation rows whose totals equal the line's base quantity. An adjustment has no aggregate-only escape hatch for a lot-tracked variant.

`serialized_inventory_units` remains one row per physical serial, unique by serial number. Add current-custody fields rather than leaving an old warehouse pointer after delivery:

| Field | Example values |
|---|---|
| `custody_type` | `warehouse`, `in_transit`, `customer`, `supplier`, `disposed`, `unknown` |
| `custody_reference_type` / `custody_reference_id` | Warehouse, transfer, delivery, return, supplier-return document |
| `warehouse_id` | Required only when custody is `warehouse`; null otherwise. |
| `stock_condition` | saleable, quarantine, damaged, disposed. |
| `inventory_lot_id` | Optional when a serial also belongs to a lot. |

The current-custody row is read optimisation; immutable movement/allocation history proves how it got there. Delivery therefore changes a serial from `warehouse` to `customer` and references the delivery/customer, rather than merely setting `Delivered` while retaining `warehouse_id`.

## Posting behavior

| Operation | Document | Reservation effect | On-hand effect | Lot / serial effect | Movement | Reversible? |
|---|---|---|---|---|---|---|
| Receipt | `InventoryOperation: Receipt` | None | Destination condition increases at post | Creates/receives declared lot and serial custody | Inbound | Compensating receipt reversal before downstream use; otherwise correction document. |
| Delivery | `InventoryOperation: Delivery` | Consume at dispatch/post | Source saleable decreases | Deplete allocated lot; serial -> customer custody | Outbound | Customer return, never destructive edit. |
| Transfer dispatch | `InventoryOperation: InternalTransfer` | Consume source reservation | Source saleable decreases | Lot/serial -> in-transit custody | Transfer-out | Cancel remaining dispatched quantity with compensating source inbound. |
| Transfer receive | same transfer | None | Destination condition increases by actually received | Lot/serial -> destination warehouse | Transfer-in | Correct by documented discrepancy/reversal. |
| Customer return | `InventoryReturn: customer` | None | Inbound to inspected disposition | Must match original delivery allocation | Return-in | Supplier return, damage/disposal, or adjustment. |
| Supplier return | `InventoryReturn: supplier` | Reserve then consume | Outbound from approved condition | Preserve lot/serial identity | Return-out | Compensating supplier-return correction only. |
| Count adjustment | `InventoryAdjustment` | Reconcile/refuse conflicting reservations | Delta at the declared allocation grain | Required lot or serial count line when tracked | Adjustment | New adjustment only. |
| Damage / recovery / disposal | Condition-change document | Release/reject active allocation per rule | Saleable -> damaged/quarantine, reverse, or disposal removes physical stock | Same allocation requirement | Condition movement | New compensating condition document. |
| Reservation / release / consume | `InventoryReservation` | Create / release / consume | No physical on-hand change | Allocates/release lots and serials | Reservation audit movement or immutable reservation event | State transitions only. |

### Operational lifecycles

```text
Receipt / Delivery
Draft -> Ready (reservation only for outbound) -> Posted
                                                  | immutable
                                                  +-> correction / return document

Internal transfer
Draft -> Ready (source reservation) -> Dispatched -> PartiallyReceived* -> Received
                                     \-> Canceled (release)
Dispatched -> CancelRemaining (compensating source inbound) or record shortage/disposition

Customer return
Draft -> Authorized -> ReceivedForInspection -> Inspected
        -> Saleable | Quarantine | Damaged | ReturnToSupplier
```

`PartiallyReceived` is recommended because the business brief requires an explicit dispatched-versus-received discrepancy. A transfer line records dispatched base quantity, received base quantity, and any shortage/damage disposition; destination stock increases only by received quantity. This recommendation requires owner approval because the old operation lifecycle has no partial-receipt state.

## Returns, corrections, and movements

Create `InventoryReturn` and `InventoryReturnLine` instead of treating a financial credit note or an adjustment as a stock return. Customer-return lines must reference an original delivery line/allocation and reject a serial not delivered to that customer, a duplicate serial return, and a quantity above the delivered allocation. The inspection disposition posts stock; the return request alone does not.

Supplier returns are separate direction/type values with an optional original receipt/purchase-order allocation. They do not create a bill, refund, credit note, or accounting journal. Financial documents may reference an inventory return through an optional relation, while inventory posting remains independent.

Expand the immutable `InventoryMovement` schema to record: source document morph, document line/allocation, `transaction_quantity`, `transaction_unit_id`, `conversion_factor_snapshot`, `base_quantity_delta`, stock condition before/after, base balance before/after, reservation before/after when relevant, lot, serial, package, actor, effective date, reason, and reversal reference. A movement is append-only; a correction creates a separate reversing/compensating movement. `InventoryMovement` remains the reconciliation ledger, while `inventory_stocks` remains the current materialized read model.

## Database integrity and concurrency

The target schema must enforce what it can:

- unique stock balance grain and unique lot-balance grain;
- unique normalized serial globally; unique normalized lot number per variant;
- foreign keys from document allocation and movement rows to tracked entities;
- non-negative base quantities; `reserved <= saleable on_hand`; no reserved quantity in non-saleable conditions;
- a transfer source warehouse distinct from destination warehouse;
- exactly one base UOM per variant and unique variant/UOM row;
- unique active serial allocation and source/line idempotency key where retries are possible.

Use database `CHECK` constraints when the deployed MySQL version enforces them; verify that capability in the implementation phase. If a required invariant cannot be expressed as a portable check, protect it in the canonical posting transaction and add a reconciliation query/test rather than trusting the form layer. All posting transactions lock rows in a stable order: document, variant, stock-condition balances by ID, lots/balances by ID, serials by ID, then active reservations by ID. The implementation test suite must use independent database connections/processes for delivery and reservation races; sequential re-invocation is not a concurrency test.

## System invariants

1. **INV-01 — Single balance engine.** Only `InventoryPostingService` may change materialized stock, lot balances, reservation allocations, or serial custody.
2. **INV-02 — Base quantity only.** Every quantity at the balance layer is normalized to the variant base UOM from an immutable document snapshot.
3. **INV-03 — Current stock grain.** `inventory_stocks` is the sole materialized current balance at `(variant, warehouse, condition)`.
4. **INV-04 — Ledger completeness.** Every committed physical or condition quantity change has an immutable movement created in the same transaction.
5. **INV-05 — No negative availability.** Saleable available quantity is never below zero; neither lot nor serial allocations may exceed their eligible supply.
6. **INV-06 — Reservation reconciliation.** Active aggregate reservations equal active reservation allocations and the materialized reserved quantity at every affected grain.
7. **INV-07 — Lot reconciliation.** Sum of lot balances equals the corresponding aggregate condition balance for every lot-tracked variant/warehouse/condition.
8. **INV-08 — Serial reconciliation.** A serial represents one physical base unit, has one current custody, and eligible serial count reconciles with serial-tracked stock.
9. **INV-09 — Explicit tracked allocation.** Every lot-/serial-tracked outbound, return, adjustment, damage, and maintenance posting names eligible allocations whose sum equals its base quantity.
10. **INV-10 — Immutable posting.** Posted documents and movements are never edited or deleted to correct stock; a linked compensating document is required.
11. **INV-11 — Shipment separation.** Shipment status changes never create a stock movement or alter a balance.
12. **INV-12 — No legacy mutation path.** No receipt, transfer, import, maintenance, or UI path remains able to bypass the canonical posting service.
13. **INV-13 — UOM/history stability.** A variant base UOM, tracking profile, and a lot’s expiry identity cannot change after stock history; controlled migration is a separate approved operation.
14. **INV-14 — Financial decoupling.** Inventory returns and corrections never implicitly create financial credits, refunds, or journal entries.

## Cross-module impact

| Change | Inventory | Products | Purchasing | Sales | Shipment | Maintenance | Accounting | Reports / UI |
|---|---|---|---|---|---|---|---|---|
| Variant UOM conversion | Base quantities and conversions | Base/alternate UOM setup | PO quantity received/cost UOM | Quote/order/delivery UOM | Labels only | Parts consumption UOM | Cost snapshot semantics only; no automatic COGS | Display transaction and base quantities. |
| Canonical posting service | All stock writers | Tracking validation | PO receipt completion listener | Delivery allocation | No stock change | Parts consumption/reversal | Preserve current no-automatic-inventory-posting boundary | Update resources, exports, and architecture test. |
| Reservation allocations | Availability and locking | Lot/serial eligibility | Future inbound promise only | Orders/deliveries own reservation source | Read-only status | Do not consume reserved stock without allocation | None | Replace dormant screen with real operational view. |
| Lot/serial identity | Allocation/reconciliation | Tracking profile | Receipt trace | Delivery and customer return trace | No stock mutation | Require allocations | None | Recall/expiry/serial-custody reports. |
| Returns | Inbound/outbound movements | Eligibility | Supplier return origin | Customer delivery/optional credit link | Shipment remains separate | Potential returned machine service history | Optional links, no posting coupling | Return/inspection/disposition pages and reports. |
| Stock conditions | Condition balances | Conditions shown per variant | Receiving condition | Deliver only saleable | None | Damage/recovery | None until valuation is specified | Availability, expiry, quarantine, damage reports. |

## Acceptance-test matrix

| Area | Minimum acceptance coverage |
|---|---|
| UOM | 5 Boxes x 100 Pieces posts 500 Pieces; deliver 7 Pieces leaves 493. Receive 25 kg into Gram base posts 25,000 g; deliver 750 g leaves 24,250 g. Reject cross-family/no-factor and rounding violations. |
| Lot / expiry | Multiple lots reconcile to aggregate; transfer preserves lot identity; return preserves identity; expired lot is ineligible unless audited override; FEFO is suggested by default. |
| Serial | Reject duplicate serial; receive, transfer, deliver, return, and reject duplicate return; assert customer/in-transit custody and no stale warehouse. |
| Reservations | Create source-linked reservation; partial consumption; release, expiry, idempotency, lot/serial allocation, and aggregate reconciliation. |
| Adjustment | Count non-tracked, lot-tracked, serial-tracked, and lot+serial variants; reject a result that would break a lot/aggregate or serial/aggregate invariant. |
| Transfer | Dispatch, partial receive, shortage/damage disposition, cancellation of unreceived remainder, concurrent dispatch/receive, and lot/serial preservation. |
| Returns | Customer saleable/quarantine/damaged/supplier-return dispositions; duplicate/over-return rejection; no implicit credit note/refund. |
| Architecture | Assert no use of legacy services/models/tables after removal, no direct balance write outside posting infrastructure, and Shipment does not use inventory posting classes. |
| Concurrency | Two independent processes competing for one available unit or one serial/reservation: exactly one succeeds, all balance and allocation invariants still reconcile. |
