# Inventory Testing

---
status: canonical
owner: inventory
last_verified: 2026-10-08
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
- lots, FEFO/expiry buckets and serialized inventory;
- Sales Order reservation provenance and tracked-identity assignment;
- physical counts and variance handling;
- returns, corrections and condition changes;
- purchase inbound receiving integration;
- replenishment;
- package balance invariants;
- UOM normalization/product types;
- permissions and workspace navigation (`InventoryNavigationTest`: seven-destination sidebar, deep links, permission-aware tabs);
- reports/exports/reconciliation;
- typed-list columns after Livewire view changes and the permission-aware transfer creation link (`ListTableExperienceInventoryTest`);
- submit controls on the non-wizard transfer form in English/Arabic, alongside existing creation/state guards (`InventoryOperationResourceTest`);
- legacy inventory removal guards.

Representative tests include `OperationLifecycleTest`, `OperationStockEffectTest`, `InventoryPostingServiceTest`, `InventoryOperationConcurrencyTest`, `PhysicalCountFlowTest`, `InventoryReturnServiceTest`, `InventoryCorrectionServiceTest`, and `PurchaseMultiWarehouseInboundEndToEndTest`.

Any stock-mutation change should run the focused workflow tests plus canonical posting/concurrency coverage.
