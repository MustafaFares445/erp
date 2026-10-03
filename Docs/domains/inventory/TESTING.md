# Inventory Testing

---
status: canonical
owner: inventory
last_verified: 2026-10-02
verified_against: tests/Feature/Inventory
---

## Test Location

`tests/Feature/Inventory/`

## Critical Regression Areas

The suite covers:

- operation lifecycle, stock effects, cancellation and in-transit transfers;
- reservation creation/release/expiry;
- canonical posting and allocation;
- concurrency around balances and operations;
- lots, expiry and serialized inventory;
- physical counts and variance handling;
- returns, corrections and condition changes;
- purchase inbound receiving integration;
- replenishment;
- package balance invariants;
- UOM normalization/product types;
- permissions and workspace navigation (`InventoryNavigationTest`: seven-destination sidebar, deep links, permission-aware tabs);
- reports/exports/reconciliation;
- legacy inventory removal guards.

Representative tests include `OperationLifecycleTest`, `OperationStockEffectTest`, `InventoryPostingServiceTest`, `InventoryOperationConcurrencyTest`, `PhysicalCountFlowTest`, `InventoryReturnServiceTest`, `InventoryCorrectionServiceTest`, and `PurchaseMultiWarehouseInboundEndToEndTest`.

Any stock-mutation change should run the focused workflow tests plus canonical posting/concurrency coverage.
