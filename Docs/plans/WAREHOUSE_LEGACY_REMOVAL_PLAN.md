# Warehouse Legacy Removal Plan

## Removal rule

The application is pre-production, but shared development data exists and all current migrations have run. Removal therefore means: migrate each live caller, prove functional and data reconciliation, remove the runtime surface, then drop database objects with a new forward migration. Do not edit or erase already-applied migration files unless a separately approved repository/schema-baseline reset makes that safe. Historical specs and ADRs are evidence; mark them superseded rather than deleting them.

Names in the **Replacement** column are proposed target components unless they are written as an existing current path. They are not claims that the target class or table already exists.

## Dependency trees

### Legacy receipt path

```text
CatalogImportApplicationService
  -> InventoryReceipt + InventoryReceiptItem
  -> InventoryReceivingService::confirm()
  -> InventoryBalanceService + InventoryLot + SerializedInventoryUnit + InventoryMovement

Secondary dependencies
  InventoryReceiptResource / ManageInventoryReceipts
  InventoryReceiptPolicy
  InventoryOperationBackfiller / OperationBackfillReconciler
  InventoryDemoSeeder, SupportDemoSeeder
  receipt factories, receiving tests, serial timeline and model relations
```

### Legacy transfer path

```text
TransferResource / Transfer form, pages, table, relation manager
  -> StockTransfer + StockTransferItem
  -> StockTransferService::dispatch()/receive()
  -> InventoryBalanceService + SerializedInventoryUnit + InventoryMovement

Secondary dependencies
  StockTransferPolicy, StockTransferObserver, TransferStatus, TransferData
  InventoryAlertService, InventoryPendingDocuments widget, alert table/command
  InventoryOperationBackfiller / OperationBackfillReconciler
  seeders, factories, resource/service/serial tests, historical specs
```

### Reservation mismatch

```text
InventoryOperationService::markReady()
  -> InventoryBalanceService::reserve()
  -> InventoryLotService::reserve()
  ! no StockReservation record

StockReservation resource
  -> ReservationService::release()
  -> InventoryBalanceService::releaseReservation()

Only InventoryDemoSeeder creates StockReservation records
```

## Component-by-component plan

| Current component | Current callers / consumers verified | Replacement | Migration step | Safe deletion point | Tests to replace or remove |
|---|---|---|---|---|---|
| `app/Models/InventoryReceipt.php` | `InventoryReceivingService`, `CatalogImportApplicationService`, `InventoryReceiptResource`, backfiller/reconciler, serial timeline, models/seeders/tests. | `InventoryOperation` receipt plus canonical posting. | Catalog import emits canonical receipt operation and allocations; migrate seed/test fixtures; reconcile any retained development data. | Catalog-import, UI, seed, and test references are zero; receipt data reconciles; the legacy table is dropped. | `InventoryReceivingServiceTest`, legacy resource tests, receipt-specific factory tests become operation/import tests. |
| `app/Models/InventoryReceiptItem.php` | Same receipt tree; `InventoryLot.receiptItem`, `InventoryMovement.receiptItem`, serialized-unit receipt relation. | Canonical receipt operation line/allocation and origin reference. | Preserve import/receipt provenance on canonical operation and movement rather than a legacy FK. | All current relation consumers query canonical origin fields; zero code references. | Receipt item / serial tracking tests migrate to operation allocation tests. |
| `app/Services/Inventory/InventoryReceivingService.php` | `CatalogImportApplicationService`; service and receiving tests. | Proposed canonical receipt workflow + proposed `InventoryPostingService`. | Move validation, serial/lot creation, cost snapshot, and movement behavior into canonical receipt post. | Catalog import posts a canonical receipt successfully and old service has no callers. | Replace its direct behavior tests with operation/import tests. |
| `app/Data/Inventory/ReceiptMovementContext.php` | Legacy receiving service only. | Typed canonical posting context. | Introduce a posting command used by all document workflows. | Removed together with legacy receiving service. | Covered by posting contract tests. |
| `app/Filament/Resources/InventoryReceipts/**` | Registered in `AdminPanelServiceProvider`; resource/page tests; legacy routes. | Receipt views/actions on `InventoryOperationResource`. | Verify canonical receipt lists, forms, history and permissions; provide a temporary redirect only during the migration release if a UI URL must be retained. | No route/registry/translation/policy reference. | Replace resource tests with operation-receipt view/action tests. |
| `app/Policies/InventoryReceiptPolicy.php` and receipt permission labels | Resource and authorization tests. | Operation receipt policy/abilities. | Map permissions at the policy boundary; avoid silently widening access. | Canonical receipt authorization tests pass and `rg` has zero runtime references. | `InventoryPolicyAuthorizationTest` receipt cases move to operation policy. |
| `inventory_receipts`, `inventory_receipt_items` tables and live constraints | Legacy models/services/import/backfill/data migrations. | `inventory_operations`, lines, allocations, movement origin. | Forward migration copies only approved retained development data, validates counts/quantities/origins, then drops foreign keys/tables. | Reconciliation command is clean and data reset/mapping approved. | Migration/reconciliation tests plus `migrate:fresh --seed` only in an approved test environment. |
| `app/Models/StockTransfer.php`, `StockTransferItem.php` | Transfer resource/pages/table/relation manager, service, policy/observer, alerts, widget, backfiller/reconciler, tests/seeders. | Internal-transfer operation with canonical transfer allocations. | Migrate every legacy transfer UI and seed consumer; add discrepancy/partial-receipt contract to canonical transfer before redirecting/removing. | Operations transfer passes all lifecycle/discrepancy/custody tests and every runtime caller is migrated. | Remove `ConfirmTransferTest` / `TransferResourceTest` legacy cases after equivalent operation tests exist. |
| `app/Services/Inventory/StockTransferService.php` | `TransferResource` actions/pages; service tests. | Transfer workflow through canonical posting service. | Port dispatch, receive, serial custody, packages, alert/event behavior, cancellation and new discrepancy model. | No route/resource/command/service call remains. | Transfer lifecycle, serial and concurrency tests target canonical transfer only. |
| `app/Filament/Resources/Transfers/**` | Registered resources, navigation, direct route, resource tests. | `InventoryOperationResource` internal-transfer list and actions. | Verify every form/table/infolist/action has an operation equivalent; migrate links in widgets and alerts. | Route, navigation, translation, policy, and test search is zero. | Canonical operations tests replace resource tests. |
| `app/Observers/StockTransferObserver.php`, `TransferStatus`, `TransferData`, `StockTransferPolicy` | Legacy model/resource/service/tests. | Operation-stage workflow and canonical transfer data contract. | Ensure alert and UI state calculations query operation transfer state first. | Removed together with final legacy transfer model. | Policy/enum tests migrate to operation lifecycle. |
| `stock_transfers`, `stock_transfer_items` tables | Legacy runtime, backfill, reports/widgets, migrations. | Canonical operation transfer rows plus allocation/discrepancy records. | Reconcile source/destination, line, serial, and package provenance before an approved forward drop. | Legacy runtime and provenance columns gone; reset/mapping gate complete. | Schema/migration/reconciliation coverage. |
| `InventoryOperationBackfiller`, `OperationBackfillReconciler` | Backfill migration, tests, docs/ADR references. | One-time canonical migration/reconciler, if retained data exists. | Run, archive its report in release evidence, and migrate only needed current data. | The old tables are dropped and no `legacy_receipt_id`/`legacy_transfer_id` data remains. | `OperationBackfillReconciliationTest` is replaced by canonical reset/migration reconciliation tests. |
| `inventory_operations.legacy_receipt_id`, `.legacy_transfer_id` | Backfiller/reconciler/model migration/tests. | None; optional immutable `migration_origin` only during approved data migration. | Remove application references first; drop columns in the same cleanup stage as legacy tables. | Clean reconciliation and database reset/mapping sign-off. | Remove backfill field assertions only after proof is stored. |
| `app/Models/StockReservation.php`, `ReservationService`, `StockReservationResource` | Seeder creates records; resource releases; policy, model, Factory, tests. Operations hold aggregate reservation without records. | `InventoryReservation` + allocation model and workflow. | Rename/rebuild table and model; make `markReady`, transfer, delivery, cancel and expiry create/consume/release the record. Update UI to a real monitoring surface. | Never delete until reservations are authoritative and reconcile against stock. | Replace seed-only resource tests with source/lot/serial allocation tests. |
| `app/Filament/Resources/Returns/**` | Registered placeholder read-only return view; `MovementType::Return` has no writer. | Customer/supplier `InventoryReturn` workflow/resource. | Build domain and posting path first, then present the resource over actual documents; retain `MovementType::Return` as active. | Placeholder page is replaced only after real return workflow passes. | Replace read-only filtered ledger tests with return lifecycle tests. |
| Product-level `product_units` selection | `Product::units()`, `ProductForm`, `OperationLinesRepeater`, `ProductTypeGuard`, product-unit migration/backfill, factories/seeders/tests. | Variant-level UOM conversion relation. | Migrate each variant's base UOM and explicitly configure each alternate factor; repair factories first. | No code validates or reads product-level allowed units; a data reset/mapping is approved. | Replace `ProductUnitsTest` with variant-UOM conversion/immutability tests. |
| Public `InventoryBalanceService` API | Operation, adjustment, damage, reservation, legacy, maintenance and seed callers. | Internal balance repository behind `InventoryPostingService`. | Migrate consumers one at a time, then reduce public surface/visibility and prohibit new calls by architecture test. | All mutations carry a posting context and direct service calls are zero outside posting infrastructure. | Update balance service tests into posting service and repository unit tests. |
| Current `ProductType` tracking projection / `track_*` flags | Product and variant observers, forms, `ProductTypeGuard`, import/factories/tests. | Variant tracking profile separated from commercial classification. | Backfill/mapping only with explicit decision; preserve a transitional read adapter for one phase at most. | Profile is source of truth, current flags and old guard branches no longer have callers. | Product type catalog tests become tracking-profile contract tests. |

## Supporting surfaces that must be audited in each removal PR

These are not optional cleanup:

- `app/Providers/Filament/AdminPanelServiceProvider.php` and `app/Filament/AdminModuleRegistry.php` registrations;
- routes, direct URLs, navigation labels, `lang/en/admin.php`, and Arabic translations;
- policies, `InventoryPermission` cases, permission seeders, and authorization tests;
- `InventoryDemoSeeder`, `DentalCatalogSeeder`, `SupportDemoSeeder`, factories, import result DTOs, and demo assertions;
- `InventoryAlertService`, `ReconcileInventoryAlertsCommand`, dashboards/widgets, exports, stock reports, and serialized timeline/report queries;
- purchasing receipt listener and models, order fulfilment, quotation conversion, maintenance consumption, and shipment confirmation;
- PHPStan architecture rules in `tests/Unit/ArchTest.php` and the documentation/specification references classified below.

## Documentation classification

| Documentation kind | Treatment |
|---|---|
| Current contracts / README / implementation plans that instruct users where to act | Update in the same PR when the runtime surface changes. |
| Feature specifications, research, ADRs, and completed task files | Preserve as historical record. Add a short “Superseded by canonical warehouse remediation” note only where a reader could mistake it for current design. Do not rewrite history or falsify its original delivery status. |
| Generated ERD / architecture diagrams | Regenerate or update when schema is final; validate all paths and names. |

## Deletion protocol for every component

1. Identify all production callers with `rg`, plus factories, seeders, tests, resources, policies, routes, translations, docs, commands, jobs, events, and listeners.
2. Add or update canonical behavior tests and migration/reconciliation proof before changing a caller.
3. Move one caller category at a time; run its focused tests and static analysis.
4. Run a zero-reference search scoped to runtime code before deleting a class/resource/policy/enum.
5. Drop database constraints/tables/columns only in a separate forward migration after the caller search and data gate pass.
6. Remove or update tests only when their replacement asserts the same or stronger behavior; no quality gate may be weakened to enable deletion.
7. At the end of the phase, run `git diff --check`, the narrow Pest suite, Pint for changed PHP, PHPStan for changed paths, and the relevant architecture test.

## Explicit non-removals

- `Shipment`, `ShipmentService`, and shipment attachments stay; they are logistics-only and are not duplicate stock code.
- `PurchaseOrderReceivingService` stays as a Purchasing-owned initiator. It must not post stock itself.
- `InventoryAdjustment` stays as a distinct count/correction document, but its implementation changes.
- `InventoryOperation` stays limited to receipt, delivery, and internal transfer. Do not append return, damage, or reservation fields to it.
- Existing historical migration files are retained as repository history unless an owner-approved schema baseline reset explicitly replaces them.
