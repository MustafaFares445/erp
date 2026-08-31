# Warehouse Gap Remediation Plan

## Scope, evidence standard, and current baseline

This plan validates the material claims in `WAREHOUSE_MODULE_AUDIT.md` against the checkout at `a0be721` on `dev`. It does not modify application code. The working tree already contains an unrelated modified supplier-confirmation migration and the untracked audit; neither is part of this plan. Components called **proposed** below are target designs, not current classes or tables.

The canonical source requested by the brief, `IERP-SRS-PIM-001 v1.1`, is absent. The findings below use the accessible source hierarchy: `specs/014-inventory-erp-rework`, the earlier inventory specifications, the constitution, current schema/code/tests, and the audit as a diagnostic starting point. Any requirement that depends only on the missing SRS is explicitly identified as a stakeholder confirmation.

Focused verification on 2026-08-31 ran 124 warehouse-adjacent tests: 118 passed (585 assertions) and 6 failed. All six failures are in `tests/Feature/Purchasing/PurchaseOrderReceivingTest.php`, failing at `ProductTypeGuard::assertUnitAllowed()` because the test fixtures lack the newly introduced `product_units` pivot. This is an existing product-unit fixture baseline, not evidence against the proposed architecture. It must be fixed before a remediation phase claims a green regression suite.

## Confirmed gaps

| ID | Severity | Verified evidence | Desired behavior | Principal impact |
|---|---|---|---|---|
| GAP-01 | Critical | `Unit` has only name/symbol/decimal flag; `product_units` only has `is_default`; `InventoryOperationLine.quantity` is sent unchanged into `InventoryBalanceService`; `ProductTypeGuard::assertUnitAllowed()` verifies permission but no ratio. | One base UOM per variant; item-specific alternate-UOM factors; all inventory posted in base quantity. | Products, receipts, POs, orders, deliveries, imports, reports, price/cost semantics. |
| GAP-02 | Critical | `InventoryBalanceService` exposes public receive/transfer/reserve/adjust methods. Its callers separately create movements/lots/serial updates. `ServiceRecordPartService` consumes aggregate stock without lot or serial allocation. | A single public posting boundary atomically validates allocations, balances, custody, reservations, and movements. | Every stock-changing module. |
| GAP-03 | High | `InventoryReceivingService` confirms `InventoryReceipt`; `CatalogImportApplicationService::receiveGroup()` creates it. `StockTransferService` remains called by `TransferResource`. Both post stock independently of `InventoryOperationService`. | Only canonical receipt/delivery/transfer workflows post stock. | Catalog import, legacy resources, seeders, tests, navigation, permissions. |
| GAP-04 | Critical | `InventoryAdjustmentItem` has no `inventory_lot_id`. `InventoryAdjustmentService::applyItem()` changes an aggregate balance, while no code changes a lot. | A lot-tracked adjustment changes declared lot balances and aggregate balance in the same transaction; no aggregate-only shortcut. | Adjustments, lots, reports, stock integrity. |
| GAP-05 | High | `StockReservation` is created only by `InventoryDemoSeeder` in application/seed code. `InventoryOperationService::markReady()` reserves aggregate and lot quantities but creates no reservation record. | Source-linked reservations and allocations are authoritative; materialized reserved quantity reconciles to them. | Deliveries, transfers, sales orders, dashboard, expiry/lot allocation. |
| GAP-06 | High | `MovementType::Return` and `ReturnResource` exist, but no application service creates `MovementType::Return`; `specs/005-reservations-returns` calls the view interim/read-only. | Customer and supplier returns are inspected, traceable business documents with controlled inventory dispositions. | Sales delivery, purchasing receipt/PO, credit notes/refunds, serial/lot history. |
| GAP-07 | Medium | `InventoryOperationService::leaveSerializedUnits()` changes status only. Delivery marks `Delivered`, leaving current `warehouse_id` unchanged; only adjustment/disposal null it. | Current serial custody explicitly distinguishes warehouse, in-transit, customer, supplier, and disposed states. | Serial inventory, delivery, returns, maintenance, reports. |
| GAP-08 | High | `InventoryLot` is a per-warehouse row with no unique lot identity. Unified `InventoryLotService::receive()` finds/tops up a matching lot, but legacy `InventoryReceivingService::recordLot()` always inserts another. | Stable lot identity plus per-warehouse condition balances; one rule for all receipts. | Receipts, transfers, returns, expiry, recalls, reports. |
| GAP-09 | Medium | `inventory_stocks` has no database check for non-negative/compatible quantities. `InventoryBalanceService::persist()` provides application protection only. Tests only model sequential calls. | Enforced DB invariants where available plus genuine multi-connection concurrency tests. | Schema, posting engine, test runtime. |
| GAP-10 | High | `ProductObserver` locks `product_type` after history, while `ProductVariantObserver` only projects tracking flags and has no unit-history check. | Base UOM and tracking profile immutable after stock history, absent a controlled migration. | Catalog forms, imports, all historical documents/reports. |
| GAP-11 | Medium | `InventoryMovement` records only a signed `quantity`, current source text/id, optional lot/serial, and status. It has no transaction UOM conversion snapshot, condition, or before/after values. | Immutable movement records explain the exact posting basis and reconcile current balances. | Reporting, audit, UOM, returns, support. |
| GAP-12 | Medium | `InventoryOperation` has all-or-nothing transfer completion. The unified path has no dispatched vs received quantities or discrepancy model. | Transfer can receive actual quantity, record a disposition/reason for variance, and preserve lot/serial custody. | Transfers, stock conditions, audit, alerts. |
| GAP-13 | Medium | Current `ProductType` is only `Machine`, `ExpiryMaterial`, and `Grain`; all non-machine types are batch-tracked. | Commercial classification must not be the only vehicle for tracking policy. | Product catalogue, forms, validation, factories/imports. |
| GAP-14 | Informational | `InventoryPermissionSeeder` assigns inventory permissions directly to an admin. No warehouse role bundle is seeded. | Role bundles may be added after lifecycle permissions are stabilised. | Users, policies, onboarding. |

### Claims found accurate, with limits

- **Shipment has no stock path.** `ShipmentService` delegates only to the `Shipment` model’s confirmation methods; neither references stock, movements, lots, serials, or the posting services. This property is retained as INV-11.
- **Aggregate locking is real.** `InventoryBalanceService` wraps each mutation in `DB::transaction(..., attempts: 5)` and locks its stock row. It is necessary but insufficient because allocations and ledger creation are separate public-service concerns and no true concurrent test was found.
- **The unified operation path protects lots on its own path.** It reserves, consumes, restores, and receives via `InventoryLotService`; it does not cure the legacy receipt path or adjustment gap.
- **Completed operation deletion/editing is policy-controlled.** This is a useful UI/authorization rule but the canonical model must also make posted corrections explicit in the workflow and database write boundary.

## Remediation work packages

### R-01 — UOM foundation and historical quantity contract

**Database**

- Extend `units` with a code, UOM family, decimal precision, and global-family conversion metadata where applicable.
- Replace product-level `product_units` as the inventory authority with `product_variant_units` containing `factor_to_base`, role flags, rounding increment, activity, and exactly one base UOM per variant.
- Add transaction quantity, transaction UOM, conversion snapshot, and base quantity to all stock-facing document lines: inventory operations, purchase orders, orders, quotations, adjustments, returns, and movements.
- Add variant history/controlled-migration markers. Do not infer a conversion factor from an existing unit label or `net_weight`.

**Services and UI**

- Add a normalizer owned by the posting boundary; remove UOM conversion from form callbacks and resource actions.
- Rework product/variant forms so the variant chooses base/purchase/sale/display UOMs and conversion factor; do not retain a product-level allowed-unit picker as a parallel contract.
- Rework PO receiving and its listener to compare received base quantity to ordered base quantity. Preserve transaction UOM for the commercial document.

**Tests**

- Exact 5 Box x 100 Piece and 25 kg -> Gram examples from the brief; invalid family, missing factor, precision and post-history base-unit-change cases.
- Repair all factories/seeders/import fixtures to create valid variant-UOM definitions; this also resolves the known `product_units` test baseline.

### R-02 — Canonical posting contract

**Database and service**

- Introduce a typed posting command/line/allocation contract and proposed `InventoryPostingService`.
- Restrict the current `InventoryBalanceService` to internal persistence implementation. It must no longer be an injectable module API.
- Require one transaction to update aggregate condition balance, lot balance, serial custody, reservation allocation, immutable movement, audit event, and document status.
- Add a source-line idempotency key or unique posting record so retrying a server action cannot post the same document twice.

**Consumers to migrate**

`InventoryOperationService`, `InventoryAdjustmentService`, `InventoryDamageService`, `ReservationService`, `ServiceRecordPartService`, and the receiving/transfer replacements. `ShipmentService` must stay outside this contract.

**Tests**

- Architecture rule: no class outside `App\\Services\\Inventory` posting infrastructure can directly use `InventoryStock`, `InventoryLot`, `SerializedInventoryUnit`, or `InventoryMovement` for a mutation.
- Transaction rollback tests for failures at every companion mutation.

### R-03 — Lot/serial-safe flows and stock conditions

**Database**

- Split stable lot identity from per-warehouse/condition lot balance; choose and enforce a normalized lot uniqueness key.
- Add condition dimension to stock and lot balances; eliminate independent stored `available_quantity`.
- Add serial current-custody type/reference/condition and optional lot identity.
- Extend adjustment lines with lot allocation and introduce allocation rows where one document line can select multiple lots/serials.

**Behavior**

- A lot/serial-tracked document cannot be marked ready or posted until selected allocation totals equal its normalized base quantity.
- Delivery sets a serial to customer custody and its delivery/customer source; transfer sets it to transfer custody; receipt assigns warehouse custody.
- Damage/recovery/disposal and maintenance consumption must use the same allocation checks as operations. The current maintenance path is not exempt merely because its source is Support.

**Tests**

- Aggregate/lot and aggregate/serial reconciliation after each receipt, transfer, delivery, adjustment, return, damage, disposal, and maintenance action.

### R-04 — Reservations and allocation visibility

**Database**

- Rename/rework `stock_reservations` to `inventory_reservations`; create allocation children for lot/serial quantities; use active/released/consumed/expired status and source relation indexes.
- Materialize reserved base quantity only as the guarded sum of active allocations.

**Behavior/UI**

- `markReady` creates real reservation rows for outbound documents. Delivery/transfer consume them; cancellation/release/expiry transitions them once.
- Replace the currently seed-only Stock Reservations resource with a real read-only reservation/allocations view and a policy-gated release action. Do not expose create/edit.

**Tests**

- Source linkage, release idempotency, partial consume, expiry, lot/serial exclusivity, and aggregate reconciliation.

### R-05 — Returns and corrections

**Database**

- Add `inventory_returns`, `inventory_return_lines`, inspection/disposition data, original-source allocation links, and optional financial reference fields.
- Do not add returns to the `inventory_operations` type enum or make an accounting table the stock source.

**Behavior/UI**

- Customer return validates the original delivery allocation. Inspection posts Saleable, Quarantine, Damaged, or links to a later supplier-return workflow.
- Supplier return validates receipt/PO origin and moves allocated stock out only at post.
- A credit note, refund, bill, or supplier payment may link to a return; it is never auto-created by inventory.

**Tests**

- Serial/customer provenance, duplicate/over-return, lot allocation, all dispositions, and financial decoupling.

### R-06 — Transfer discrepancy and immutable corrections

**Database/service**

- Add dispatched, received, outstanding base quantities and discrepancy/disposition records to transfer lines or dedicated transfer allocations.
- Refactor completed receipt/delivery/transfer corrections into compensating postings linked to the original movement, rather than status rewrites or a generic adjustment.

**Tests**

- Partial receipt, short/damaged receipt, cancel remaining in-transit quantity, no double movement, lot/serial custody, and reconciliation.

### R-07 — Ledger, constraints, and reconciliation gates

**Database**

- Enrich movements with UOM snapshot, base delta, condition, before/after values, document/line/allocation link, actor/effective date, reason, and reversal relation.
- Add unique keys and checked/non-negative invariants listed in the canonical architecture. Assess actual MySQL check-constraint enforcement first.

**Operational gates**

- Add reconciliation commands that report—not mutate—stock/lot/serial/reservation mismatch. They must be run after each migration and before legacy deletion.
- Add true parallel connection/process tests for stock and reservation races; test only with a database engine that honours row locks.

## Cross-module implementation impact

| Module | Current dependency | Required remediation |
|---|---|---|
| Product catalog | `ProductForm` owns product-wide permitted units; `ProductVariant.unit_id` is base-by-convention. | Variant-UOM relation and immutable tracking profile; migrate factories, imports, forms, policies, observer tests, and product reports. |
| Purchasing | `PurchaseOrderLine` keys received quantities by variant + unit; `PurchaseOrderReceivingService` correctly opens an operation. | Store/compare base quantity, keep PO transaction UOM and cost; change `AdvancePurchaseOrderOnOperationCompleted` aggregation. |
| Catalog import | `CatalogImportApplicationService` creates `InventoryReceipt` and delegates to `InventoryReceivingService`. | Create canonical receipt operation/line/allocation directly; preserve import item result links to the new document. |
| Sales / orders | Quotation conversion looks up the current variant `unit_id`; fulfillment creates delivery operations and reserves aggregate/lot only. | Preserve selected sales UOM and base snapshot; create canonical reservation allocations; validate delivery allocations/custody. |
| Delivery / shipment | Delivery is an `InventoryOperation`; shipment has a one-to-one delivery link and only confirmation. | Keep delivery as posting source and Shipment non-posting; show shared trace links without duplicate deduction. |
| Maintenance | `ServiceRecordPartService` calls balance receive/out and writes movement. | Require UOM-normalized, lot/serial allocation-backed consumption/reversal through the posting service. |
| Accounting | Current documents do not derive inventory valuation or COGS; bills reference PO receipt evidence. | Retain no-automatic-posting boundary. Expose immutable stock documents as references only; a valuation/COGS specification is separate work. |
| Reporting / alerts | Reads current stock/lot/movement columns and legacy transfer state in places. | Query condition balances, base/transaction values, allocations, actual in-transit transfer quantities, expiry and custody views. |
| Authorization / navigation | Legacy Receipt/Transfer/Return/Reservation resources and permissions remain registered. | Migrate users to canonical surfaces; remove legacy policies/permissions/translations only after zero-reference proof. |

## Schema and development-data strategy

The current shared development database has 100+ applied, incremental migrations; legacy tables and their later unified replacements are both present. Do **not** edit historical migrations or reset that database silently.

The recommended strategy is a controlled pre-production reset after the UOM and final data model are approved:

1. Export any developer-owned reference/demo data that must survive and list it for owner approval.
2. Complete the forward migrations and migration/reconciliation commands in an isolated branch/database first.
3. Obtain explicit approval for **DEVELOPMENT DATABASE RESET REQUIRED**. A reset is recommended because existing rows have no authoritative alternate-UOM factor from which base quantities can be reconstructed safely.
4. Reset only the named development environment, run the canonical seeders, and verify migrations, inventory reconciliation, smoke workflows, and the full test gate.
5. After the reset, remove retired tables and code through normal forward migrations. Do not retain adapters merely to make unknown old development data appear compatible.

If a reset is not approved, implementation is not allowed to guess conversions. It needs a signed per-variant conversion mapping and a data-migration/reconciliation plan for every existing stock, lot, reservation, document line, and movement row. That is a materially higher-risk alternative.

## Dependency order

```text
Business decisions + primary SRS
        -> UOM/tracking schema and fixtures
        -> canonical posting contract + architecture tests
        -> lots/serials/conditions/reservations
        -> receipt/import migration
        -> transfer migration + discrepancy
        -> adjustment/maintenance migration
        -> returns/corrections
        -> ledger/constraint/reconciliation gates
        -> legacy deletion and reset/reseed acceptance
```

No legacy table, resource, policy, enum, seeder, test, or migration-provenance field becomes removable before the migratory caller and reconciliation stages succeed.
