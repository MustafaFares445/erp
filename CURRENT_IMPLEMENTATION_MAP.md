# Current Implementation Map

**Generated:** 2026-09-03
**Branch:** `feat/cross-module-remediation` (`b29a49a`)
**Mode:** Read-only discovery. No code was modified.

---

## 0. Preface — source documents

The brief asked for `EXPECTED_BUSINESS_SCENARIOS.md` and `CROSS_MODULE_BUSINESS_FLOWS.md`
to be read first. **Neither file exists in this repository** (verified across the
whole tree, excluding `vendor/` and `node_modules/`). The nearest authoritative
substitutes were used as the "Expected" baseline:

| Substituted source | Role |
|---|---|
| `Docs/PRD.md` | Core features, user flows (§6), FR-001…FR-024, business rules (§9), out-of-scope (§11) |
| `Docs/SDD.md` | System design detail |
| `Docs/adr/0001…0011` | Per-module authorisation and explicit scope carve-outs |
| `specs/001…022/spec.md` | 22 Spec-Kit feature specs — the real per-feature acceptance criteria |
| `Docs/api/API_CONTRACT.md`, `Docs/database/ERD.md`, `Docs/diagrams/SEQUENCE_DIAGRAMS.md` | Contract-level expectations |

Where the PRD and an ADR disagree, the ADR wins — the PRD's §11 explicitly
defers to them.

### Evidence standard applied

Per the brief, a feature is scored **Complete** only when all four hold:

1. **Business rule** — an enforced invariant in a service/enum/policy, not just a column.
2. **Workflow** — a state machine or orchestrated action, not an ad-hoc write.
3. **UI/API exposure** — a Filament resource/page/action a user can actually reach.
4. **Tests** — a Pest test that exercises the behaviour.

`Partial` = one or two legs missing. `Missing` = no implementation.
`Incorrect` = implemented but inconsistent with the stated rule or with itself.
`Missing (by design)` = explicitly deferred by an ADR — recorded so it is not
mistaken for an accidental gap.

### Global architecture facts

| Fact | Evidence |
|---|---|
| **There is no HTTP API.** No `routes/api.php`, no Sanctum, no `JsonResource`, zero API Resources. | `routes/web.php` (7 routes: 3 public join-us, 6 authenticated media streams, 4 legacy redirects) |
| **One delivery surface: a single Filament v5 admin panel** at `/admin`. | `app/Providers/Filament/AdminPanelServiceProvider.php` |
| 65 Filament resources, 10 pages, 20 widgets | `app/Filament/**` |
| 100 models, 84 enums, 145 service classes, 63 policies, 159 migrations, 96 factories, 18 seeders | `app/**`, `database/**` |
| **316 Pest test files** | `tests/**` |
| Exactly **1 domain event** (`InventoryOperationCompleted`) with 2 synchronous listeners. All other cross-module coupling is direct service injection. | `app/Events/`, `app/Listeners/` |
| Audit trail is `spatie/laravel-activitylog`; `AuditLog` extends `Activity` | `app/Models/AuditLog.php`, migration `2026_08_10_060306_drop_audit_logs_table` |
| 3 scheduled commands only | `routes/console.php` |

---

## 1. Products & Catalog

### Entities

| Concept | Model | Table |
|---|---|---|
| Product | `Product` | `products` |
| Variant | `ProductVariant` | `product_variants` |
| Category / Brand / Unit | `ProductCategory`, `Brand`, `Unit` | `product_categories`, `brands`, `units` |
| Attributes | `ProductAttribute`, `ProductAttributeValue`, `ProductVariantAttributeValue` | 3 tables |
| Allowed UOMs | `ProductVariantUnit` (+ `product_units` pivot) | `product_variant_units`, `product_units` |
| Pricing tiers | `PricingTier`, `CustomerPricingTier`, `pricing_tier_products` | 3 tables |
| Price governance | `PriceHistory`, `PriceFloorOverride` | 2 tables |
| Supplier catalog links | `SupplierProductReference`, `SupplierProductSupport` | 2 tables |
| Excel import | `InventoryImportRun`, `InventoryImportItem` | 2 tables |

Relationships: `Product → variants (HasMany)`, `→ category/brand (BelongsTo)`,
`→ pricingTiers (BelongsToMany)`, `→ units (BelongsToMany)`,
`→ stocks/movements (HasManyThrough)`, `→ supplierProductReferences (HasManyThrough)`
(`app/Models/Product.php:59-158`).

### Business actions

Catalog CRUD; `syncUnits` / `addAllowedUnit`; variant UOM sync
(`ProductVariantUomService::sync`); price update, price-change-request
approve/reject/update, cost update from inventory, floor-override approve
(`ProductPricingService`); price resolution and floor assertion
(`PriceResolver::resolve/candidates/assertAtOrAboveFloor`); tier
save/syncProducts/syncCustomers/assignGeneralTier/activate/deactivate/delete/restore
(`PricingTierService`); Excel import begin → parse → confirm → apply
(`CatalogImportService`).

### Status lifecycles

```
ProductStatus:                Active | Inactive | ComingSoon
ProductType (fixes tracking): Machine (serials) | ExpiryMaterial (expiry) | Grain (batches)
PriceChangeRequestStatus:     Pending → Approved | Rejected
PricingTierType:              General | CustomerSpecific | ProductScoped
InventoryImportRunStatus:     Queued → Parsing → Ready | ReadyWithErrors | Invalid
                                     → Applying → Confirmed | ConfirmedWithErrors | Failed
InventoryImportItemStatus:    Valid | Invalid → Applying → Applied | Rejected
```

### Side effects

- Price update → `price_histories` row + activity `catalog.variant.price_updated`.
- Floor override → `price_floor_overrides` row + `catalog.variant.price_floor_overridden`.
- Import confirm → queues `ParseCatalogImport` / `ApplyCatalogImport`; writes products,
  variants, attribute values, supplier references, stock; raises `ImportError` /
  `DuplicateIdentity` alerts; logs `catalog.import.confirmed`.
- Product/variant/unit saves → 5 observers (`ProductObserver`, `ProductVariantObserver`,
  `ProductVariantUnitObserver`, `UnitObserver`, `PackageTypeObserver`) enforce
  tracking-flag/UOM invariants.

### Scenarios

**Scenario: Product with variants and variant attributes (FR-014)**
- Expected: products support variants and variant attributes.
- Actual: full model + Filament CRUD (`Products/Pages/ManageProducts|ViewProduct|EditProduct`,
  `ProductVariants/Pages/ManageProductVariantAttributeValues`), plus a consolidated
  `CatalogSetup` page for categories/brands/attributes/units (spec 012).
- Files: `app/Models/Product.php`, `ProductVariant.php`, `app/Filament/Resources/Products/**`,
  `app/Filament/Pages/CatalogSetup.php`
- Tests: `Feature/CatalogAdministrationResourceTest.php`, `Feature/Inventory/ProductRecordTabsTest.php`,
  `Feature/ProductUnitsTest.php`, `Feature/Inventory/ProductVariantUomTest.php`
- **Status: Complete**

**Scenario: Product type fixes tracking behaviour**
- Expected: serial / expiry / batch tracking is not independently editable (spec 014).
- Actual: `ProductType::trackingFlags()` derives all three flags; `ProductTypeGuard`
  asserts quantity wholeness, unit suitability, weight completeness, inbound expiry,
  serial coverage. Backfill migration `2026_08_03_150001`.
- Files: `app/Enums/ProductType.php`, `app/Services/Inventory/ProductTypeGuard.php`
- Tests: `Unit/ProductTypeTest.php`, `Feature/Inventory/ProductTypeCatalogTest.php`,
  `Feature/Inventory/MachineOperationTest.php`, `ExpiryMaterialOperationTest.php`, `GrainOperationTest.php`
- **Status: Complete**

**Scenario: Customer price resolution order, no stacking, floor enforced (PRD §9)**
- Expected: customer-specific tier → lowest eligible product-scoped tier → active
  general tier → base price; never stack; never below floor without logged admin approval.
- Actual: `PriceResolver` returns a `ResolvedPriceSource`
  (`CustomerSpecificTier|ProductScopedTier|GeneralTier|Base`) and
  `assertAtOrAboveFloor()` blocks the sale; override requires
  `InventoryPermission::PriceFloorApprove` and writes `price_floor_overrides`.
- Files: `app/Services/Inventory/PriceResolver.php`, `PricingTierDiscountCalculator.php`,
  `ProductPricingService.php`, `app/Enums/ResolvedPriceSource.php`
- Tests: `Feature/PricingTierPriceResolverTest.php`, `Unit/PricingTierDiscountCalculatorTest.php`,
  `Feature/ProductPricingServiceTest.php`, `Feature/Filament/PricingTierPricePreviewTest.php`,
  `Feature/Filament/PricingControlsResourceTest.php`
- **Status: Complete**

**Scenario: Excel catalog import with validation and staged apply (spec 008)**
- Expected: template download, parse, per-row validation report, confirm, apply, error report.
- Actual: 5-service pipeline (`CatalogImportService`, `…Validator`, `…CatalogService`,
  `…ApplicationService`, `…ReportService`) + 2 queued jobs; UI at
  `InventoryImportRuns/Pages/ManageInventoryImportRuns` with `download_template`,
  `download_rows`, `download_summary`.
- Tests: `Feature/CatalogImportServiceTest.php`, `Feature/Filament/InventoryImportRunResourceTest.php`,
  `Feature/Inventory/ProductTypeCatalogImportTest.php`
- **Status: Complete**

---

## 2. Inventory

The largest module — 14 specs (001–012, 014) and 56 test files under `tests/Feature/Inventory/`
plus 45 at `tests/Feature/` root.

### Entities

| Concept | Model | Table |
|---|---|---|
| Warehouse | `Warehouse` | `warehouses` |
| Stock (source of truth: `product_variant_id + warehouse_id`) | `InventoryStock` | `inventory_stocks` |
| Ledger | `InventoryMovement` | `inventory_movements` |
| Condition split | `InventoryConditionBalance` | `inventory_condition_balances` |
| Operation (receipt/delivery/transfer) | `InventoryOperation`, `InventoryOperationLine` | 2 tables |
| Adjustment | `InventoryAdjustment`, `InventoryAdjustmentItem` | 2 tables |
| Correction (reversal) | `InventoryCorrection`, `InventoryCorrectionLine` | 2 tables |
| Return | `InventoryReturn`, `InventoryReturnLine` | 2 tables |
| Reservation | `InventoryReservation`, `InventoryReservationAllocation` | 2 tables |
| Lot / expiry | `InventoryLot`, `InventoryLotBalance` | 2 tables |
| Serialized unit | `SerializedInventoryUnit` | `serialized_inventory_units` |
| Package | `Package`, `PackageType` | 2 tables |
| Alert | `InventoryAlert` | `inventory_alerts` |
| Export / settings | `InventoryExport`, `InventorySetting` | 2 tables |
| Shipment | `Shipment` | `shipments` |

### Business actions

`InventoryOperationService`: `markReady`, `dispatch`, `complete`, `receiveTransfer`,
`cancel`, `previewEffect`, `availableQuantity`.
`InventoryBalanceService` (the only writer of stock rows): `receive`, `transferOut`,
`transferIn`, `adjustTo`, `reserve`, `releaseReservation`, `damage`, `recoverDamage`,
`disposeDamage`, `stockForUpdate`, `applyLockedDeltas`.
`InventoryPostingService`: `post`, `postMany` (idempotent — see
`2026_08_31_030521_add_posting_idempotency_to_inventory_movements_table`).
Plus `InventoryAdjustmentService`, `InventoryCorrectionService`, `InventoryReturnService`,
`InventoryReservationService`, `InventoryDamageService`, `InventoryLotService`,
`InventoryLotReconciliationService`, `InventoryAlertService`, `InventoryExportService`,
`InventoryReportService`, `SerializedInventoryTimelineService`, `InventoryIdentityGuard`,
`QuantityNormalizer`.

### Status lifecycles

```
OperationStage (guarded per OperationType):
  Draft ──► Waiting ──► Ready ──► InTransit ──► PartiallyReceived ──► Done
     └──────────────────┴──────────┴────────────┴──────────────────► Canceled
  (Receipt/Delivery skip InTransit; only InternalTransfer uses dispatch → receiveTransfer)

AdjustmentStatus:            Draft → Confirmed              (no `pending` — deliberate, spec 003)
InventoryCorrectionStatus:   Draft → Posted | Cancelled
InventoryReturnStatus:       Draft → Ready → Posted | Cancelled
ReservationStatus:           Active → Consumed | Released | Expired
StockCondition:              Saleable | Quarantine | Damaged | Disposed
SerializedInventoryUnitStatus: Pending | Available | InTransit | Delivered |
                               ReturnedToSupplier | AdjustedOut | Consumed | Damaged | Disposed | Unknown
SerializedCustodyType:       Warehouse | InTransit | Customer | Supplier | Maintenance | Disposed | Unknown
ShipmentStatus:              InTransit → Arrived
MovementType (11):           Sale, Return, Adjustment, Correction, Transfer, Reservation,
                             Receipt, Damage, DamageRecovery, Disposal, ServiceConsumption
```

### Side effects

Operation `complete()` (`InventoryOperationService.php:153-197`), inside one transaction:
1. Locks operation, lines, variants, warehouses (id order — deadlock-safe).
2. Type guards (serial coverage, expiry capture, whole-quantity, weight).
3. Consumes reservations (`consumeOperation`).
4. Posts movements via `InventoryPostingService` → updates `inventory_stocks`,
   `inventory_condition_balances`, `inventory_lot_balances`.
5. Advances `serialized_inventory_units` status + custody.
6. Snapshots line quantities and UOM conversion factors.
7. Syncs `DeliveryDocumentSynchronizer` media for deliveries.
8. Dispatches `InventoryOperationCompleted` **synchronously, in-transaction**.
9. Two listeners run: purchase-order receipt advance + sales-procurement refresh.

### Scenarios

**Scenario: Every stock-changing operation creates an inventory movement (PRD §9)**
- Expected: no stock change without a ledger row.
- Actual: `InventoryBalanceService` is the sole stock writer and is only reachable through
  `InventoryPostingService`. 9 callers, all services: Adjustment, Correction, Damage, Lot,
  Operation, Reservation, Return, ServiceRecordPart. An `ArchTest` + domain contract test
  enforce the boundary.
- Files: `app/Services/Inventory/InventoryPostingService.php`, `InventoryBalanceService.php`
- Tests: `Feature/InventoryDomainContractTest.php`, `Feature/Inventory/InventoryPostingServiceTest.php`,
  `OperationStockEffectTest.php`, `InventoryBalanceConcurrencyTest.php`,
  `InventoryOperationConcurrencyTest.php`, `Unit/ArchTest.php`
- **Status: Complete**

**Scenario: Internal transfer with in-transit custody and partial receipt (spec 004)**
- Expected: source loses custody at dispatch; destination gains on receipt; partial receipt
  supported; discrepancy dispositioned.
- Actual: `dispatch()` → `transferOut` + `InTransit`; `receiveTransfer()` → `transferIn`,
  stays `PartiallyReceived` until filled; `TransferDiscrepancyDisposition`
  (`Shortage|Damaged|Cancelled`); `syncTransferDiscrepancy` raises alerts.
  Cancel after dispatch is blocked from restoring source stock (custody already lost).
- Files: `app/Services/Inventory/InventoryOperationService.php:119-380`
- Tests: `Feature/Inventory/OperationInTransitTest.php`, `CanonicalTransferReceiptTest.php`,
  `Feature/Filament/TransferResourceTest.php`, `Feature/CanonicalTransferReceiptTest.php`
- **Status: Complete**

**Scenario: Non-destructive correction of a posted receipt (spec 014 / PRD §9)**
- Expected: confirmed inventory documents are corrected, never deleted.
- Actual: `InventoryCorrectionService` creates a linked reversal document
  (`Draft → Posted | Cancelled`) writing `MovementType::Correction`.
- **However `InventoryCorrectionType` has exactly one case: `Receipt`.** Deliveries,
  transfers and adjustments have no correction path — adjustments instead expose
  `InventoryAdjustmentService::createCorrection()`, a separate mechanism.
- Files: `app/Enums/InventoryCorrectionType.php`, `app/Services/Inventory/InventoryCorrectionService.php`,
  migrations `2026_09_01_001000`, `2026_09_01_001100`
- Tests: `Feature/Inventory/InventoryCorrectionServiceTest.php`, `Feature/Filament/InventoryCorrectionResourceTest.php`
- **Status: Partial** — receipt corrections complete; other document types uncovered.

**Scenario: Reservation lifecycle — release and expiry**
- Expected: `ReservationStatus` supports `Active → Consumed | Released | Expired`; permission
  `inventory.reservation.release` exists for a manual release.
- Actual: only the automatic path runs. `reserveOperation` / `consumeOperation` /
  `releaseOperation` are called by `InventoryOperationService` (lines 102, 451, 841, 921).
  - `InventoryReservationService::expire()` has **no production caller** — the only
    invocation in the repo is `tests/Feature/Inventory/InventoryReservationServiceTest.php:87`.
    No scheduled command reconciles expiry (`routes/console.php` schedules only
    `inventory:alerts:reconcile`, `inventory:shipments:auto-arrive`, `support:sla:reconcile`).
  - `InventoryReservationService::release()` (single-reservation manual release) has no
    production caller either.
  - `InventoryPermission::ReservationRelease` is referenced **0 times** outside its own enum.
  - `InventoryReservationResource` is read-only: table columns only, no actions, and its
    only page is `ListInventoryReservations`.
- Files: `app/Services/Inventory/InventoryReservationService.php`,
  `app/Filament/Resources/InventoryReservations/InventoryReservationResource.php`,
  `app/Enums/ReservationStatus.php`, `app/Enums/InventoryPermission.php:32`
- Tests: `Feature/Inventory/InventoryReservationServiceTest.php`,
  `Feature/Filament/InventoryReservationResourceTest.php`, `Feature/CanonicalInventoryReservationsMigrationTest.php`
- **Status: Partial** — `Expired` is unreachable in production; manual release has a
  permission and a service method but no UI and no caller.

**Scenario: Damaged stock, recovery, disposal (spec 010)**
- Expected: mark damaged, recover to saleable, dispose permanently; alerts.
- Actual: `InventoryDamageService::damage/recover/dispose` writes
  `Damage`/`DamageRecovery`/`Disposal` movements, shifts `StockCondition`, updates serialized
  unit status. UI: `StockLevels/Actions/StockDamageActions`.
- Tests: `Feature/InventoryDamageServiceTest.php`, `Feature/Filament/StockLevelResourceTest.php`
- **Status: Complete**

**Scenario: Lot / expiry tracking with FEFO reservation and expired-stock release (spec 009)**
- Expected: lots, balances, expiry alerts, blocked sale of expired stock with override.
- Actual: `InventoryLotService` (`receive`, `receiveTransfer`, `consume`, `assertReservable`,
  `restore`, `availableLots`); `InventoryLotReconciliationService::inspect`;
  `InventoryAlertType::Expiry` and `ExpiredStockReleased`; permission `ExpiredStockOverride`
  (2 references); `inventory:alerts:reconcile` daily; `ReconcileInventoryLotsCommand`.
- Tests: `Feature/Inventory/InventoryLotServiceTest.php`, `InventoryLotReconciliationTest.php`,
  `Feature/Filament/InventoryLotResourceTest.php`, `Feature/Inventory/ExpiryMaterialOperationTest.php`
- **Status: Complete**

**Scenario: Serialized device tracking with custody timeline (spec 009)**
- Actual: 10-state status × 7 custody types; `SerializedInventoryTimelineService::events`;
  duplicate-identity guard (`InventoryIdentityGuard::ensureSkuAvailable/ensureSerialAvailable/ensureIotAvailable`);
  `MissingDeviceIdentity` / `DuplicateIdentity` alerts.
- Tests: `Feature/SerializedInventoryTrackingTest.php`, `Feature/Inventory/MachineOperationTest.php`
- **Status: Complete**

**Scenario: Warehouse locations / bin-level tracking**
- Expected: PRD/ERD reference `warehouse_locations`; migration `2026_07_22_000005` creates it
  and `2026_07_25_120000` adds `warehouse_location_id` to tracking tables.
- Actual: **removed.** `2026_07_28_042305_remove_warehouse_locations_from_inventory` drops the
  table and columns. No `WarehouseLocation` model exists. Location granularity was replaced by
  `Package` / `PackageType`.
- Files: migrations `2026_07_22_000005`, `2026_07_25_120000`, `2026_07_28_042305`
- Tests: `Feature/Inventory/WarehouseLocationTrackingTest.php` (asserts the removal),
  `LegacyInventoryRemovalTest.php`
- **Status: Missing (by design, spec 012/014)** — documentation in `Docs/database/ERD.md`
  may still describe it.

**Scenario: Inventory reports and exports (specs 006, 011)**
- Actual: 12 `InventoryReportType` cases and 9 `InventoryExportType` cases; queued
  `GenerateInventoryExport`; `InventoryReportService` with `canView`/`authorizeView`;
  UI `InventoryReports/Pages/ManageInventoryReports`.
- Tests: `Feature/InventoryReportServiceTest.php`, `InventoryExportServiceTest.php`,
  `InventoryReportResourceTest.php`, `Feature/PricingTierReportTest.php`
- **Status: Complete**

---

## 3. Sales

### Entities

`Quotation` + `QuotationLine`; `Order` + `OrderLine`; `Invoice` + `InvoiceLine` +
`InvoiceConfirmation`; `CreditNote` + `CreditNoteLine`; `PaymentTerm`; `SalesSetting`;
`SalesProcurementRequirement`; `SalesOpportunity` (shared with Employees);
`CustomerDeliveryAddress`; `Shipment`.

### Business actions

`QuotationService`: `create`, `createFromOpportunity`, `update`, `updateLines`, `send`, `recordDecision`.
`QuotationConversionService::convert` → `Order`.
`OrderFulfillmentService`: `suggest`, `suggestForOrder`, `availability`, `validateFulfillment`,
`routePreviews`, `create`, `prepareExisting`.
`InvoiceService`: `createFromDelivery`, `createStandalone`, `issue`, `send`.
`InvoiceConfirmationService::confirm`; `InvoiceBalanceService::status/syncInvoice/syncOrder`.
`CreditNoteService`: `addLine`, `removeLine`, `confirm`, `reverse`.
`SalesProcurementService`: `detectShortages`, `requestSupplierConfirmation`, `createPurchaseOrder`,
`refreshFromPurchaseOrder`.
`PaymentTermService`; `DocumentNumberGenerator::next`; `LineTotalCalculator`.

### Status lifecycles

```
QuotationStatus: Draft → Sent → Accepted → ConvertedToDelivery
                            └─► Rejected | Expired
                  any non-terminal ──► Cancelled

CreditNoteStatus: Draft → Confirmed → Reversed
                     └─► Cancelled

OrderPaymentStatus: Unpaid → PartiallyPaid → Paid   (derived, never hand-written)

Invoice status: RAW STRING — 'draft' → 'issued' → 'sent'
                          → 'customer_received' | 'employee_confirmed_received'
```

### Side effects

- Quotation `send` → status + `sent_at`; **no stock effect** (asserted by
  `QuotationTouchesNoStockTest`).
- `convert()` → creates `Order` (`SO-` number), copies aggregated lines with UOM snapshots,
  sets quotation `ConvertedToDelivery` + `converted_order_id`; rejects double conversion.
- `OrderFulfillmentService::create` → creates `Order` + `OrderLine`s + one `InventoryOperation`
  (`Delivery`) **per warehouse**, `Shipment` rows, destination address snapshot, delivery-type
  resolution (`Inner`/`Outer` via `UaeBoundary`), route preview via `RoadRouteFetcher`;
  locks warehouses; logs `sales.order.fulfillment_prepared`.
- Delivery `complete()` → stock out, **no ledger posting** (COGS out of scope per ADR 0008).
- `Invoice::issue()` → `InvoicePostingService::post` → journal entry (AR debit / revenue credit /
  deferred tax credit); logs `sales.invoice.issued`.
- `GenerateInvoiceDocument` (dompdf, `pdf.invoice` view) → media; `SendInvoiceEmail` + `InvoiceMail`.
- `CreditNoteService::confirm` → `CreditNotePostingService::post` → reversing journal entry,
  bumps `invoices.credited_amount`, re-syncs balance; `GenerateCreditNoteDocument` PDF.
- Shortage detection → `sales_procurement_requirements` + `sales.order.procurement_required`;
  fulfilment via the `InventoryOperationCompleted` listener → `sales.order.procurement_fulfilled`.

### Scenarios

**Scenario: Quotation → Delivery Note → Invoice → Payment (FR-008)**
- Expected: the full four-step chain.
- Actual: implemented end to end, with `Order` inserted as the commercial spine between
  quotation and delivery (delivery notes are `InventoryOperation` rows of type `Delivery`,
  surfaced read-only at `DeliveryNotes/Pages/ListDeliveryNotes|ViewDeliveryNote`).
- Files: `app/Services/Sales/QuotationConversionService.php`, `app/Services/Orders/OrderFulfillmentService.php`,
  `app/Services/Sales/InvoiceService.php`, `app/Services/Payments/PaymentService.php`
- Tests: `Feature/Sales/QuotationConversionTest.php`, `QuotationConversionAggregationTest.php`,
  `Feature/Filament/CreateOrderWizardTest.php`, `Feature/DeliveryWizardTest.php`,
  `Feature/AccountingDocumentResourcesTest.php`, `Feature/SalesDashboardResourcesTest.php`
- **Status: Complete**

**Scenario: Delivery notes affect stock but do not recognise tax (FR-009)**
- Actual: delivery completion posts `MovementType::Sale` movements and nothing to the ledger;
  tax lives only on the invoice and is recognised only at payment.
- Tests: `Feature/Sales/QuotationTouchesNoStockTest.php`, `Feature/Accounting/NoAutomaticPostingTest.php`,
  `Feature/Inventory/OperationDeliveryNoteIntegrationTest.php`
- **Status: Complete**

**Scenario: Customer accepts or rejects a quotation (PRD §6.1 step 2, §6.2)**
- Expected (PRD): the customer performs the action.
- Actual: `QuotationDecision` (`Accepted|Rejected`) is recorded **by an admin or employee**
  through the `record_decision` Filament action. There is no customer-facing link, portal or API.
- Files: `app/Filament/Resources/Quotations/Actions/QuotationActions.php`,
  `app/Services/Sales/QuotationService.php` (`recordDecision`)
- Tests: `Feature/Sales/QuotationLifecycleTest.php`, `QuotationResourceTest.php`
- **Status: Complete as re-scoped** by ADR 0008 (customer channel explicitly out of scope);
  **Missing** against PRD §6.2 as literally written.

**Scenario: Invoice receipt confirmation with signature (PRD core features)**
- Actual: `InvoiceConfirmationService::confirm` validates type against a hard-coded array
  `['customer_received', 'employee_confirmed_received']`, requires the invoice to be issued,
  stores an optional signature in the `invoice-confirmation-signature` media collection,
  and writes the type back into `invoices.status`.
- **The `InvoiceConfirmationType` enum that should hold these two values is an empty stub**
  (`enum InvoiceConfirmationType { // }`), and `invoice_confirmations.confirmation_type` is a
  `string(30)` column with no enum cast.
- Files: `app/Services/Sales/InvoiceConfirmationService.php`,
  `app/Enums/InvoiceConfirmationType.php`, migration `2026_08_24_143224`
- Tests: `Feature/AccountingDocumentResourcesTest.php`, `Feature/SalesDashboardResourcesTest.php`
- **Status: Partial** — behaviour works; the type system does not model it.

**Scenario: Invoice / payment status modelled as enums**
- Expected: consistency with the rest of the codebase, where every lifecycle is a backed enum
  with `canTransitionTo()`.
- Actual: **`app/Enums/InvoiceStatus.php` and `app/Enums/PaymentStatus.php` are empty stubs**
  (`enum InvoiceStatus { // }`) with **zero references anywhere** in `app/`, `database/`, or
  `tests/`. Invoice status is a raw string compared with `in_array($locked->status, ['issued','sent'], true)`
  and `$this->status === 'draft'`; payment status likewise.
- Files: `app/Enums/InvoiceStatus.php`, `app/Enums/PaymentStatus.php`,
  `app/Models/Invoice.php:76`, `app/Services/Sales/InvoiceConfirmationService.php:50`
- Tests: none reference these enums.
- **Status: Incorrect** — three dead enum files advertising lifecycles that are implemented
  as untyped strings. No transition guard exists for invoices, unlike quotations, credit notes,
  purchase orders, tickets and operations.

**Scenario: Invoice export to CSV/Excel (FR-006)**
- Expected: invoices exportable to CSV/Excel.
- Actual: **no export action on any sales resource.** Grepping for CSV/export across
  `Invoices/`, `Payments/`, `Quotations/`, `CreditNotes/` returns nothing. Export exists only for
  Accounts Payable, Employee Reports, Financial Reports, Inventory Import Runs, Inventory Exports
  and Purchasing Reports.
- **Status: Missing**

**Scenario: Credit note reverses an invoice non-destructively (FR-007)**
- Actual: full lifecycle with 5 `CreditNoteReason` categories
  (`SalesReturn|PricingAdjustment|TaxAdjustment|CommercialDiscount|Other`), posting service,
  PDF, and Filament actions (`create_credit_note`, `confirm`, `reverse`).
- Files: `app/Services/Sales/CreditNoteService.php`, `CreditNotePostingService.php`,
  `app/Filament/Resources/CreditNotes/Actions/CreditNoteActions.php`
- Tests: `Feature/Sales/CreditNoteLifecycleTest.php`, `Feature/Filament/CreditNoteResourceTest.php`
- **Status: Complete**

**Scenario: Credit note returns goods to inventory**
- Expected: a sales-return credit note would restock.
- Actual: no inventory movement from a credit note. Goods returns are a separate document
  (`InventoryReturn` of type `Customer`), not linked to the credit note.
- **Status: Missing (by design, ADR 0008)** — "goods-return inventory movements from a credit
  note" is explicitly out of scope. Note the operational consequence: a `SalesReturn` credit note
  and its physical return must be raised as two unlinked documents.

---

## 4. Payments

### Entities
`Payment`, `PaymentAllocation`, `PaymentMethod`, `ManualPaymentRecord`, `TaxRecognitionEntry`.

### Business actions
`PaymentService`: `createDraft`, `post`, `reverse`.
`PaymentAllocationService`: `allocate`, `restore`.
`PaymentPostingService::post`; `TaxRecognitionService`: `recognise`, `reverseForPayment`.
UI actions: `record_payment`, `post_payment`, `reverse_payment`.

### Status lifecycle
Raw string on `payments.status` (`draft` → `posted` → `reversed`); no enum
(`PaymentStatus` is the empty stub above).

### Side effects
`post()` → allocations to invoices → `InvoiceBalanceService::syncInvoice/syncOrder` (drives
`OrderPaymentStatus`) → `PaymentPostingService` journal entry (cash/bank debit, AR credit) →
`TaxRecognitionService::recognise` per allocation → `tax_recognition_entries` row + second
journal entry (deferred tax debit, tax payable credit). Logs `sales.payment.created|posted|reversed`.
`reverse()` unwinds all of it, including `reverseForPayment`.

### Scenarios

**Scenario: Manual dashboard payments with admin-defined methods (FR-013)**
- Actual: `PaymentMethod` CRUD resource, `ManualPaymentRecord`, full draft→post→reverse.
- Tests: `Feature/AccountingDocumentResourcesTest.php`, `Feature/SalesDashboardResourcesTest.php`
- **Status: Complete**

**Scenario: Tax recognised only on collection, proportionally for partial payment (FR-010, FR-011)**
- Actual: `TaxRecognitionService::recognise` computes
  `min(remaining, allocation/total × taxTotal)` and recognises the full remainder when the
  allocation settles the claim (`total_amount − credited_amount`), preventing rounding drift on
  the final instalment.
- Files: `app/Services/Payments/TaxRecognitionService.php:26-90`
- Tests: `Feature/Accounting/NoAutomaticPostingTest.php`, `Feature/AccountingDocumentResourcesTest.php`,
  `Feature/Accounting/JournalPostingServiceTest.php`
- **Status: Complete**

**Scenario: Stripe online payments (FR-012)**
- Actual: **no Stripe integration.** The only occurrence of the word in `app/` is a comment in
  `app/Services/Support/TicketPaymentService.php:19` stating that no Stripe integration exists.
  No `stripe/stripe-php` in `composer.json`, no webhook route.
- **Status: Missing (by design, ADR 0008)** — "Stripe, its webhook, or any online payment
  channel … the manual channel is the only one built."

**Scenario: Ticket payments flow into Payments / accounting**
- Actual: `TicketPaymentService` writes only `ticket_payment_links` and `tickets`. No `Payment`,
  no journal entry, no tax entry.
- **Status: Missing (by design, ADR 0008 / spec 016 D4)**

---

## 5. Tax

### Entities
`TaxRecognitionEntry` (`tax_recognition_entries`), extended by
`2026_08_24_143228_add_sales_payment_references_to_tax_recognition_entries_table`.

### Business actions
Write path: `TaxRecognitionService::recognise` / `reverseForPayment` (sales output tax),
`RefundService::unrecogniseTaxWhenRequired` (refund un-recognition),
`AccountingDocumentService` (purchase input tax on bill/expense approval).
Read path: `TaxResource` — a **list-only, read-only register** (single page `ListTaxes`,
no create/edit/delete, filters on `direction`).

### Status lifecycle
None — entries are immutable facts, reversed by compensating rows.

### Scenarios

**Scenario: Single configurable default tax rate rather than a rate catalogue**
- Expected (ADR 0008): a `tax_definitions` catalogue is replaced by one configurable default.
- Actual: `SalesSetting` holds the default rate, applied by `LineTotalCalculator::defaultTax`.
- Tests: `Feature/Sales/SalesSettingTest.php`, `Unit/LineTotalCalculatorTest.php`
- **Status: Complete as re-scoped**

**Scenario: Tax definitions administration screen**
- Actual: navigation declares `admin.resources.tax_definitions` pointing at
  `App\Filament\Resources\TaxDefinitions\TaxDefinitionResource` — **a class that does not exist.**
  `AdminModuleRegistry::resolveLink()` guards with `class_exists()`, so the entry renders as a
  `ModulePlaceholder` rather than a broken link.
- Files: `app/Filament/AdminModuleRegistry.php:78,274`, `app/Filament/Pages/ModulePlaceholder.php`
- Tests: `Unit/AdminModuleRegistryTest.php`
- **Status: Missing (declared placeholder)**

**Scenario: Tax register directory hygiene**
- Actual: `app/Filament/Resources/TaxRecognitionEntries/` contains an **empty `Pages/`
  directory and no `Resource.php`** — a leftover of the rename to `Taxes/TaxResource`.
  `app/Filament/Resources/InventoryExports/` likewise has only `Schemas/` and no resource class.
- **Status: Incorrect** (dead directories; harmless but misleading)

---

## 6. Accounting

### Entities
`AccountType` (5 seeded rows, one per `AccountElement`), `ChartAccount`, `FiscalPeriod`,
`JournalEntry` + `JournalEntryLine`, `Bill` + `BillLine`, `Expense`, `SupplierPayment` +
`SupplierPaymentAllocation`, `Refund`, `TaxRecognitionEntry`.

### Business actions
`ChartOfAccountService`: `create`, `update`, `delete` (guards: `AccountHierarchyCycle`,
`AccountNotDeletable`, `AccountNotPostable`).
`FiscalPeriodService`: `forDate`, `create`, `update`, `delete`, `close`, `reopen`
(guards: `OverlappingFiscalPeriod`, `NoFiscalPeriodForDate`, `PeriodNotDeletable`, `ClosedFiscalPeriod`).
`JournalPostingService`: `draft`, `post`, `postNew`, `reverse`
(guards: `UnbalancedJournalEntry`, `InvalidJournalEntryLine`, `PostedEntryIsImmutable`, `EntryAlreadyReversed`).
`AccountBalanceService`: `balanceFor`, `balancesForAll`, `ledgerFor`, `runningBalances`.
`FinancialReportService`: `trialBalance`, `generalLedger`, `profitAndLoss`, `balanceSheet`, `postingRegister`.
`AccountsPayableService`: `summary`, `toCsv`, `aging`, `supplierDetail`.
`AccountingDocumentService`: `recordBill`, `recordExpense`, `recordSupplierPayment`, `issueInvoice`,
`approveBill`, `approveExpense`, `payExpense`, `paySupplierPayment`, `cancelBill`, `cancelExpense`,
`cancelSupplierPayment`, `approveRefund`.
`RefundService`: `availableCreditMinor`, `approve`, `pay`, `cancel`.

### Status lifecycles

```
JournalEntryStatus (enum):  Draft → Posted      (posted is immutable; correct by reversal only)
AccountElement:             Asset | Liability | Equity | Income | Expense → NormalBalance
FiscalPeriod:               open ⇄ closed        (close / reopen)

Bill status            (RAW STRING): draft → approved → partially_paid → paid | cancelled
Expense status         (RAW STRING): draft → approved → paid | cancelled
SupplierPayment status (RAW STRING): draft → paid | cancelled
Refund status          (RAW STRING): draft → approved → paid | cancelled
```

### Side effects
Bill approve → balanced journal entry (expense/inventory debit, AP credit, input tax) +
`journal_entry_id` back-reference + status `approved`.
Expense approve → journal entry; pay → second entry against the method's posting account.
Supplier payment pay → journal entry + per-bill allocation with remaining-balance and
same-supplier guards + bill status recompute.
Refund approve → available-credit check against confirmed credit notes and paid invoices,
maker≠checker enforcement (`'The user who recorded a refund cannot approve it.'`);
pay → journal entry + proportional tax un-recognition.

### Scenarios

**Scenario: Chart of accounts hierarchy with postable leaves (spec 018)**
- Actual: `AccountTree` support object (`displayOrder`, `rollUp`, `selfAndDescendantIds`,
  `signOf`, `depthOf`); cycle detection; non-postable parents; delete blocked when posted-to.
  UI: full CRUD + `Ledger` relation manager.
- Tests: `Feature/Accounting/ChartOfAccountServiceTest.php`, `AccountTreeTest.php`,
  `AccountBalanceServiceTest.php`, `AccountingSchemaTest.php`
- **Status: Complete**

**Scenario: Posted journal entries are immutable and corrected by reversal (PRD §9)**
- Actual: `PostedEntryIsImmutable` thrown on any mutation; `reverse()` creates a mirrored
  entry and marks the original reversed (`EntryAlreadyReversed` blocks doubles);
  posting into a closed period throws `ClosedFiscalPeriod`.
- Tests: `Feature/Accounting/PostedEntryImmutabilityTest.php`, `JournalReversalTest.php`,
  `FiscalPeriodServiceTest.php`, `JournalPostingServiceTest.php`
- **Status: Complete**

**Scenario: Exactly the approved documents post to the ledger (ADR 0007 + ADR 0008 + ADR 0011)**
- Expected: no automatic posting except invoice issuance, payment collection with tax
  recognition, credit-note confirmation (ADR 0008), plus four named payables callers (ADR 0011).
- Actual: `JournalPostingService` has exactly 6 service-layer callers —
  `InvoicePostingService`, `PaymentPostingService`, `TaxRecognitionService`,
  `CreditNotePostingService`, `AccountingDocumentService`, `RefundService` — plus the manual
  `JournalEntryActions` UI path.
- Tests: `Feature/Accounting/NoAutomaticPostingTest.php` (guards the invariant),
  `Unit/Policies/AccountingPolicyTest.php`
- **Status: Complete**

**Scenario: Financial reports — trial balance, GL, P&L, balance sheet, posting register (spec 020)**
- Actual: 5 `FinancialReportType` cases, read-only page `FinancialReports/Pages/ViewFinancialReports`
  with per-report CSV export actions, fiscal-period scoping.
- Tests: `Feature/Accounting/FinancialReportServiceTest.php`, `FinancialReportResourceTest.php`,
  `FinancialReportReadOnlyTest.php`, `FinancialReportExportTest.php`
- **Status: Complete**

**Scenario: Accounts Receivable subledger (spec 021)**
- Actual: `AccountsReceivable/Pages/ListAccountsReceivable` — a computed read-only surface over
  invoices/payments/credit notes. No aging buckets, no CSV export, no per-customer statement
  (contrast `AccountsPayableService::aging()` + `toCsv()` + `supplierDetail()` on the payables side).
- Files: `app/Filament/Resources/AccountsReceivable/**`
- Tests: `Feature/Accounting/AccountingResourceTest.php`
- **Status: Partial** — asymmetric with AP; ADR 0008 called AR a placeholder, ADR 0010 promoted it,
  but it did not reach AP's depth.

**Scenario: Accounts Payable with aging and supplier detail (spec 022 / ADR 0011)**
- Actual: `AccountsPayableService` (summary, aging, supplier detail, `payableControlAccountMinor`),
  CSV export, advisory purchase-order matching in `recordBill`.
- Tests: `Feature/Accounting/PayablesLifecycleTest.php`, `AccountingResourceTest.php`
- **Status: Complete**

**Scenario: Accounting document statuses modelled consistently**
- Expected: same enum-with-transition-guard discipline as the rest of the codebase.
- Actual: `bills`, `expenses`, `supplier_payments`, `refunds` and `invoices` all use
  `string(30)` status columns compared as literals (`$document->status !== 'approved'`,
  `whereNotIn('status', ['cancelled'])`, `'status' => 'partially_paid'`). No enum, no cast,
  no `canTransitionTo()`. Contrast `JournalEntryStatus`, `CreditNoteStatus`,
  `PurchaseOrderStatus`, `TicketStatus`, `OperationStage`, all of which are backed enums with
  explicit transition matrices.
- Files: `app/Services/Accounting/AccountingDocumentService.php:106-410`,
  `app/Services/Accounting/RefundService.php:36-232`, migrations `2026_08_26_100000:56`,
  `2026_08_24_133512:24`
- Tests: lifecycles are covered behaviourally (`PayablesLifecycleTest`), but no test pins the
  legal transition set.
- **Status: Incorrect** — the rules are enforced, but scattered across string literals in
  service methods instead of centralised in the type system. Illegal transitions are prevented
  only where a guard clause happens to exist.

**Scenario: Year-end close, multi-currency, COGS / inventory valuation, budgets, bank reconciliation**
- **Status: Missing (by design, ADR 0007 §11)** — all explicitly out of scope.

---

## 7. Purchasing

### Entities
`PurchaseOrder` + `PurchaseOrderLine`; `SupplierConfirmation` + `SupplierConfirmationItem`;
`Supplier`; `SupplierProductReference`; `SupplierProductSupport`; `PurchaseSetting`.

### Business actions
`PurchaseOrderService`: `createDraft`, `updateDraft`, `addLine`, `updateLine`, `removeLine`,
`recomputeTotal`, `assertEditable`.
`PurchaseOrderApprovalService`: `submit`, `approve`, `reject`, `send`, `close`, `cancel`,
`qualifiesForAutoApproval`.
`PurchaseOrderReceivingService::initiate` (creates an `InventoryOperation` receipt).
`SupplierConfirmationService`: `record`, `recordItems`, `answer`, `answerItems`.
`SupplierCostWritebackService::apply`; `SupplierSupportResolver::eligibleSupplierIds`;
`PurchasingReportService`: `openCommitments`, `receivingPerformance`, `costVariance`.
`PurchaseOrderNumberGenerator::next`.

### Status lifecycles

```
PurchaseOrderStatus:
  Draft ──► PendingApproval ──► Approved ──► Sent ──► PartiallyReceived ──► Received ──► Closed
    ▲             │
    └── Rejected ─┘   (reject returns the order to Draft — see PurchaseOrderApprovalService:115)
  any pre-receipt ──► Cancelled

SupplierConfirmationStatus: Pending → Partial → Confirmed | Rejected
```

### Side effects
`submit()` → auto-approves when under the `PurchaseSetting` threshold, else `PendingApproval`;
rejects a line-less order (`InvalidPurchaseOrderLine::noLines`).
`approve()` → `SelfApprovalRejected` unless the actor is a System Admin.
`cancel()` → `PurchaseOrderNotCancellable::hasCompletedReceipt` once anything is received.
Receipt completion → the `AdvancePurchaseOrderOnOperationCompleted` listener, inside the
inventory transaction: locks lines in id order, rejects over-receipt under a row lock
(`OverReceiptRejected`), advances `received_base_quantity` / `quantity_received` /
`last_received_unit_cost`, moves the order to `PartiallyReceived` or `Received`, logs
`purchasing.order.received`, then `SupplierCostWritebackService::apply` updates supplier
references and variant cost.
All transitions audit via a private `audit()` helper →
`purchasing.order.approved|sent|closed|cancelled|received`, `purchasing.confirmation.answered`.

### Scenarios

**Scenario: PO approval with threshold auto-approval and no self-approval (spec 017)**
- Files: `app/Services/Purchasing/PurchaseOrderApprovalService.php:44-262`
- Tests: `Feature/Purchasing/PurchaseOrderApprovalTest.php`, `PurchaseOrderDraftTest.php`,
  `PurchaseOrderImmutabilityTest.php`, `PurchasePermissionTest.php`, `PurchasingGuardBranchTest.php`
- **Status: Complete**

**Scenario: Receiving against a PO posts stock and rejects over-receipt concurrently (FR-041)**
- Actual: as above; the listener is deliberately synchronous and in-transaction — a throw rolls
  back the stock movement too. Documented at `app/Listeners/AdvancePurchaseOrderOnOperationCompleted.php:19-38`.
- Tests: `Feature/Purchasing/PurchaseOrderReceivingTest.php`, `PurchaseOrderOverReceiptTest.php`,
  `Feature/Inventory/CanonicalReceiptPostingTest.php`, `InventoryOperationConcurrencyTest.php`
- **Status: Complete**

**Scenario: Supplier confirmations recorded manually by admin (PRD §9)**
- Actual: header-level `record`/`answer` plus item-level `recordItems`/`answerItems` with a
  `Partial` status added by `2026_08_26_102404`. UI:
  `SupplierConfirmations/Pages/ManageSupplierConfirmations` + `SupplierConfirmationActions`.
- Tests: `Feature/Purchasing/SupplierConfirmationTest.php`, `SupplierConfirmationItemTest.php`
- **Status: Complete**

**Scenario: Purchasing creates accounting artefacts (bills, AP, journal entries)**
- Actual: none. Verified — `JournalPostingService` has no Purchasing caller.
- **Status: Missing (by design, ADR 0006)**; the Accounting-side equivalent exists via ADR 0011
  (`AccountingDocumentService::recordBill` reads PO references advisorily).

**Scenario: Supplier returns / debit notes, landed cost, FIFO or moving-average recalculation,
requisitions and RFQs, reorder-point purchasing, outbound PO email/EDI**
- **Status: Missing (by design, ADR 0006 §11)**
- Note: `InventoryReturnType::Supplier` exists on the **inventory** side
  (`InventoryReturnService::createSupplierReturn`), so physical supplier returns are possible
  without any commercial debit note.

---

## 8. CRM

### Entities
`CustomerProfile` (`customer_profiles`), `CustomerDeliveryAddress`, `CustomerPricingTier`,
`PricingTier`, `PriceHistory`, `PriceFloorOverride`, `User` (`UserType::Customer`).

### Business actions
`CustomerOnboardingService::register` (creates `User` + `CustomerProfile` + generated customer
code + documents); `CustomerDocumentSynchronizer::sync`; `PricingTierService` (8 methods);
`PriceResolver` preview; `DashboardRoleAssignmentService::assign`.
UI: `Customers` full CRUD, `PricingTiers/Pages/ManagePricingTiers` with `manageProducts`,
`manageCustomers`, `editDiscount`, `assignGeneralTier`, `activate`, `deactivate`, `restore`,
`previewPrice`; public `join-us` form (`JoinUsController`).

### Status lifecycle
`CustomerProfile`: soft-delete + restore only (`customer.created|deleted|restored`).
`PricingTierVisibility`: `Public | Restricted`. `PricingTierDiscountType`: `Percentage | Fixed`.

### Side effects
Customer create → `CustomerProfileObserver` (code generation, user linkage) + activity log.
Public join-us → `CustomerOnboardingService::register` inside a transaction.
Tier changes → `price_histories`, `pricing.tier.general.assigned`, `pricing.tier.restored`.

### Scenarios

**Scenario: Dashboard-only customer management with documents and delivery addresses (spec 013)**
- Tests: `Feature/CustomerProfileResourceTest.php`, `CustomerOnboardingServiceTest.php`,
  `CustomerDocumentSynchronizerTest.php`, `CustomerDocumentAdminUploadTest.php`,
  `CustomerProfileObserverTest.php`, `Feature/JoinUsRegistrationTest.php`
- **Status: Complete**

**Scenario: Product-scoped pricing tiers with deterministic tie-breaking (spec 013)**
- Actual: equal product-scoped results resolve by lowest pricing-tier id; `PricingTierQueryCountTest`
  pins the query budget.
- Tests: `Feature/PricingTierServiceTest.php`, `PricingTierModelTest.php`,
  `PricingTierRelationshipServiceTest.php`, `Feature/Filament/PricingTierProductScopeResourceTest.php`,
  `PricingTierQueryCountTest.php`
- **Status: Complete**

**Scenario: CRM leads, interactions, campaigns, recipients and responses (FR-022)**
- Expected: PRD lists "CRM and Marketing — track leads, interactions, campaigns, recipients,
  and responses" as a core feature.
- Actual: **no model, table, service, resource or test for any of the five concepts.** Verified
  by name scan across `app/Models` and the created-table list. The CRM module implemented by
  spec 013 is customers + pricing tiers only.
- **Status: Missing**

**Scenario: Customer 360 view (orders, invoices, tickets, visits from the customer record)**
- Actual: `CustomerProfile` declares only two relations — `user()` and `deliveryAddresses()`
  (`app/Models/CustomerProfile.php:73-84`). It has no `orders()`, `invoices()`, `quotations()`,
  `tickets()`, `payments()` or `visits()` relation, even though every one of those tables carries
  a `customer_id`. The inverse relations exist on the other side (`Order::customer()`,
  `Invoice::customer()`), so the data is reachable but not from the customer record, and
  `ViewCustomer` has no relation managers.
- Files: `app/Models/CustomerProfile.php`, `app/Filament/Resources/Customers/**`
- **Status: Partial** — customer administration is complete; a consolidated customer history
  surface does not exist.

---

## 9. Employees

The most heavily tested module: 55 files in `tests/Feature/Employees/`.

### Entities
`EmployeeProfile`, `SalesPlan`, `PlanTask`, `TaskStatusLog`, `CustomerVisit`, `VisitGpsLog`,
`EmployeeVoiceNote`, `VoiceNoteTranscription`, `AiKeywordRule`, `SalesOpportunity`,
`EmployeePerformanceScore`, `EmployeeSalaryCalculation`, `BonusSuggestion`, `EmployeeReportExport`.

### Business actions
`EmployeeOnboardingService::onboard`; `EmployeeAccessService`: `enable`, `disable`, `archive`, `restore`.
`SalesPlanService`: `create`, `update`, `transition`, `delete`, `restore`;
`SalesPlanDuplicationService::duplicate` (UI `copyToMonth`).
`PlanTaskService`: `create`, `update`, `transition`.
`VisitReviewService::updateReviewNote`.
`VoiceNoteIntakeService::intake` → `TranscribeVoiceNoteJob` → `VoiceNoteTranscriber`
(`OpenAiWhisperTranscriber` | `FakeVoiceNoteTranscriber`) → `KeywordDetectionService::detect`.
`OpportunityReviewService`: `approve`, `reject`.
`PerformanceScoringService`: `calculate`, `scoreForPlan`.
`SalaryCalculationService::calculate`; `SalaryRecalculationService`: `recalculate`, `confirm`.
`BonusApprovalService`: `approve`, `reject`.
`EmployeeReportService` + `EmployeeReportExportService`: `request`, `generate`, `download`.

### Status lifecycles

```
SalesPlanStatus:            Draft → Active → Paused → Completed → Archived
PlanTaskStatus:             Pending → InProgress → Completed | Cancelled
VisitStatus:                Planned → InProgress → Completed | Missed
VoiceNoteStatus:            Pending → Processing → Transcribed | Failed
TranscriptionStatus:        Pending → Succeeded | Failed
SalesOpportunityStatus:     Draft → Approved | Rejected
SalaryCalculationStatus:    Draft → PendingConfirmation → Confirmed → Superseded
SalaryCalculationMode:      PerformanceOnly | BasePlusPerformance
BonusSuggestionStatus:      Pending → Approved | Rejected
TranscriptionConfidenceSource: ProviderReported | DerivedFromLogProb | Unavailable
```

### Side effects
Plan transition → `plan.transitioned` + active-plan uniqueness constraint.
Task transition → `task_status_logs` row + `task.transitioned`.
Voice note create → `EmployeeVoiceNoteObserver` → `TranscribeVoiceNoteJob` →
`voice_note_transcriptions` (with confidence provenance) → `KeywordDetectionService` →
`sales_opportunities` draft. Playback via signed route `admin.voice-notes.media.play`.
Salary recalculation → supersedes the prior calculation (`salary.superseded`) and queues
`NotifyAdminOfSalaryRecalculation` (`salary.recalculation_notified`).

### Scenarios

**Scenario: Monthly plans, tasks and copy-to-month (FR-018, spec 015)**
- Tests: `Feature/Employees/SalesPlanLifecycleTest.php`, `SalesPlanInvariantsTest.php`,
  `SalesPlanActivePlanConstraintTest.php`, `SalesPlanDuplicationTest.php`,
  `PlanTaskCompletionTest.php`, `PlanTaskValidationTest.php`, `TaskStatusLogTest.php`,
  `Feature/Filament/MonthlyPlanTasksRelationManagerTest.php`, `MonthlyPlanProgressBarsTest.php`
- **Status: Complete**

**Scenario: AI voice note → transcription → keyword detection → reviewable opportunity draft (FR-020, FR-021)**
- Actual: full pipeline with a swappable transcriber, per-note language, confidence provenance,
  tenant isolation, and admin review actions. AI failure does not block the visit (PRD §9) —
  the job records `VoiceNoteStatus::Failed`.
- Tests: `Feature/Employees/VoiceNoteIntakeServiceTest.php`, `TranscribeVoiceNoteJobTest.php`,
  `OpenAiWhisperTranscriberTest.php`, `FakeVoiceNoteTranscriberTest.php`,
  `KeywordDetectionServiceTest.php`, `VoiceNoteConfidenceTest.php`, `VoiceNoteLanguageTest.php`,
  `VoiceNoteTranscriptionIsolationTest.php`, `SalesOpportunityTest.php`
- **Status: Complete**

**Scenario: Visit check-in/out with GPS capture by the employee (FR-019, PRD §6.3)**
- Expected (PRD): the employee records check-in/out and sends GPS from a mobile app.
- Actual: `CustomerVisit` + `VisitGpsLog` + duration derivation + GPS trail map exist and are
  **reviewed** in the dashboard. Editing a visit was deliberately removed
  (`Feature/Employees/VisitEditRemovedTest.php`); `Visits` has only `ListVisits`/`ViewVisit`.
  There is no capture channel — no `/api/employee`, no mobile app.
- Files: `app/Models/CustomerVisit.php`, `VisitGpsLog.php`,
  `app/Services/Employees/VisitReviewService.php`, `app/Filament/Resources/Visits/**`
- Tests: `Feature/Employees/CustomerVisitDurationTest.php`, `VisitGpsLogTest.php`,
  `VisitReviewAuditTest.php`, `Feature/Filament/VisitGpsTrailMapTest.php`
- **Status: Partial** — review side complete; capture side **Missing (by design, ADR 0003)**.

**Scenario: Performance and salary calculation with base-salary option (PRD §9)**
- Actual: configurable factors, `use_base_salary` defaults to false, supersede-on-recalculate,
  maker/confirmer split (`SalaryCalculate` vs `SalaryConfirm` permissions), bonus suggestions.
- Tests: `Unit/SalaryCalculationServiceTest.php`, `Unit/PerformanceScoringServiceTest.php`,
  `Feature/Employees/SalaryRecalculationServiceTest.php`, `SalaryAuditTest.php`,
  `Feature/Filament/BonusSuggestionsRelationManagerTest.php`
- **Status: Complete**

**Scenario: Employee-facing API / mobile app / attendance capture**
- **Status: Missing (by design, ADR 0003 / PRD §11)**

---

## 10. Support

### Entities
`Ticket`, `TicketAssignment`, `TicketMessage`, `TicketPaymentLink`, `SlaPolicy`.

### Business actions
`TicketIntakeService`: `create`, `update`.
`TicketLifecycleService`: `transition`, `assign`, `unassign`.
`TicketMessageService::post`; `TicketAttachmentSynchronizer::sync`.
`TicketPaymentService`: `createForTicket`, `settle`, `cancelForTicket`.
`SlaService`: `onTicketLive`, `onWaitingCustomer`, `onResumeFromWaiting`, `onPriorityChanged`,
`refreshBreachFlags`.
`SupportReportService`: `workload`, `sla`, `maintenance`.

### Status lifecycle

```
TicketStatus (explicit transition matrix, app/Enums/TicketStatus.php:29-40):

  Pending ────────────┐
  PendingPayment ─────┼──► Live ──► Assigned ──► InProgress ──► Resolved ──► Closed
                      │              ▲   ▲          │  ▲            │
                      │              └───┘          ▼  │            └──► InProgress (reopen)
                      │            (unassign)  WaitingCustomer
                      └────────────────────────────────────────────► Cancelled
  Closed, Cancelled: terminal

TicketPriority:      Low | Normal | High | Urgent
TicketType:          SoftwareIssue | HardwareIssue | GeneralSupport | MaintenanceRequest
PaymentLinkStatus:   Pending → Settled | Cancelled
```

### Side effects
`transition()` blocks resolution while any non-terminal maintenance record is open
(`TicketLifecycleService.php:49-51`); writes SLA timestamps; logs
`support.ticket.status_changed|assigned|unassigned|priority_changed|message_posted`.
Payment settle → `support.payment_link.settled`; rejection → `support.payment_link.settlement_rejected`.
`support:sla:reconcile` runs every five minutes (`ReconcileSlaBreachesCommand`).

### Scenarios

**Scenario: 9-state ticket lifecycle with guarded transitions (spec 016)**
- Tests: `Feature/Support/TicketLifecycleTest.php`, `TicketIntakeTest.php`, `TicketPolicyTest.php`,
  `Unit/Enums/TicketStatusTest.php`
- **Status: Complete**

**Scenario: SLA policies with first-response / resolution targets and breach reconciliation**
- Tests: `Feature/Support/SlaTest.php`, `app/Console/Commands/ReconcileSlaBreachesCommand.php`
- **Status: Complete**

**Scenario: Chargeable ticket held until payment, then released**
- Actual: `PendingPayment` → settle → `Live`; no accounting/tax artefact by design.
- Tests: `Feature/Support/TicketPaymentTest.php`
- **Status: Complete** (scope-limited by ADR 0008; see §4)

**Scenario: Customer opens tickets and maintenance requests directly (FR-017, PRD §6.2)**
- Actual: created by admin/employee in the dashboard only. No customer channel.
- **Status: Missing (by design, ADR 0004)**

---

## 11. Maintenance

### Entities
`MaintenanceRecord` (`maintenance_records`), `MaintenanceTask` (`maintenance_tasks`),
`ServiceRecordPart` (`service_record_parts`).

### Business actions
`MaintenanceRecordService`: `createFromTicket`, `createStandalone`, `update`, `transition`.
`ServiceRecordService`: `create`, `update`, `transition`.
`ServiceRecordPartService`: `consume`, `reverse`.
UI: `MaintenanceRequests` full CRUD + `ServiceRecords` relation manager +
`raiseMaintenanceRequest`, `addServiceRecord`, `consumePart` actions;
`ServiceRecords/RelationManagers/ConsumedParts`.

### Status lifecycle

```
MaintenanceStatus: Open → InProgress → Closed | Cancelled     (shared by records and tasks)
WarrantyStatus:    Covered | Expired | Unknown                (derived at creation)
```

### Side effects
`createFromTicket` → links the record to the ticket and blocks ticket resolution until closed.
`resolveEquipmentAndWarranty()` validates the serialized unit and derives warranty status,
throwing `ValidationException` on mismatch.
`ServiceRecordPartService::consume` → **`MovementType::ServiceConsumption` inventory movement**
via `InventoryPostingService`, with tracking allocation
(`2026_08_31_143000_add_tracking_allocation_to_service_record_parts`);
`reverse()` posts the compensating movement. Logs `support.service_record_part.consumed|reversed`.

### Scenarios

**Scenario: Maintenance record raised from a ticket or standalone, with warranty derivation**
- Tests: `Feature/Support/MaintenanceRequestTest.php`, `SupportModelRelationsTest.php`
- **Status: Complete**

**Scenario: Service record consumes spare parts and decrements stock**
- Actual: the fifth cross-module inventory write path; reversible.
- Tests: `Feature/Support/ServiceRecordPartTest.php`, `ServiceRecordTest.php`
- **Status: Complete**

**Scenario: Preventive / scheduled maintenance (recurring plans, due-date reminders)**
- Actual: `MaintenanceTask` is a checklist under a record, not a schedule. No recurrence field,
  no scheduled command, no reminder.
- **Status: Missing** — not in any spec; noted as an unstated gap against "Track maintenance
  records and tasks for sold products/equipment".

---

## 12. Cross-module flows

**Flow: Quotation → Order → Delivery → Invoice → Payment → Tax → General Ledger**

```
Quotation (Draft→Sent→Accepted)
  └─ QuotationConversionService::convert
       └─ Order (SO-*) + OrderLines with UOM snapshots
            └─ OrderFulfillmentService::create
                 ├─ InventoryOperation(Delivery) per warehouse   [Draft→Ready→Done]
                 ├─ Shipment per warehouse                        [InTransit→Arrived]
                 └─ inventory_movements (Sale)  ← no GL posting
                      └─ InvoiceService::createFromDelivery → Invoice (draft)
                           └─ issue() → InvoicePostingService → JournalEntry #1
                                        (AR Dr / Revenue Cr / Deferred tax Cr)
                                └─ GenerateInvoiceDocument (PDF) → SendInvoiceEmail
                                     └─ PaymentService::post
                                          ├─ PaymentAllocationService → invoice/order balances
                                          │    └─ OrderPaymentStatus Unpaid→PartiallyPaid→Paid
                                          ├─ PaymentPostingService → JournalEntry #2 (Cash Dr / AR Cr)
                                          └─ TaxRecognitionService → tax_recognition_entries
                                               └─ JournalEntry #3 (Deferred tax Dr / Tax payable Cr)
```
- **Status: Complete.** Tests: `Feature/AccountingDocumentResourcesTest.php`,
  `Feature/SalesDashboardResourcesTest.php`, `Feature/Sales/QuotationConversionTest.php`,
  `Feature/Accounting/NoAutomaticPostingTest.php`

**Flow: Purchase order → receipt → stock + PO advance + cost writeback**

```
PurchaseOrder (Draft→PendingApproval→Approved→Sent)
  └─ PurchaseOrderReceivingService::initiate → InventoryOperation(Receipt, source=PurchaseOrder)
       └─ InventoryOperationService::complete   [one transaction]
            ├─ inventory_movements (Receipt) + stock/lot/condition balances
            ├─ serialized units → Available @ Warehouse
            └─ event InventoryOperationCompleted  (synchronous, in-transaction)
                 ├─ AdvancePurchaseOrderOnOperationCompleted
                 │    ├─ over-receipt guard under row lock → OverReceiptRejected (rolls back stock)
                 │    ├─ received_base_quantity / quantity_received / last_received_unit_cost
                 │    ├─ status → PartiallyReceived | Received
                 │    └─ SupplierCostWritebackService → supplier refs + variant cost
                 └─ AdvanceSalesProcurementOnOperationCompleted
                      └─ SalesProcurementService::refreshFromPurchaseOrder
                           └─ sales_procurement_requirements → sales.order.procurement_fulfilled
```
- **Status: Complete.** This is the only event-driven seam in the codebase, and the only place
  where two modules react to one fact. Tests: `Feature/Purchasing/PurchaseOrderReceivingTest.php`,
  `PurchaseOrderOverReceiptTest.php`, `Feature/Inventory/CanonicalReceiptPostingTest.php`

**Flow: Sales shortage → supplier confirmation → purchase order → fulfilment**
```
Order lines exceed available stock
  └─ SalesProcurementService::detectShortages        (UI action `detect_procurement`)
       ├─ sales_procurement_requirements + log sales.order.procurement_required
       ├─ requestSupplierConfirmation → SupplierConfirmation (Pending)
       │    (eligible suppliers via SupplierSupportResolver / supplier_product_supports)
       └─ createPurchaseOrder → PurchaseOrder + log sales.order.purchase_order_created
            └─ … receipt flow above … → requirement fulfilled
```
- **Status: Complete.** Migration `2026_09_02_150200`, `2026_09_02_150000` (order-line provenance
  on operation lines). Tests: `Feature/Purchasing/SupplierProductSupportTest.php`,
  `SupplierConfirmationItemTest.php`, `Feature/SalesDashboardResourcesTest.php`

**Flow: Credit note → customer refund**
```
Invoice (paid) → CreditNote (Draft→Confirmed) → JournalEntry (reversal), invoices.credited_amount++
  └─ Refund (draft) → RefundService::approve  [available-credit check, maker ≠ checker]
       └─ pay() → JournalEntry + proportional tax un-recognition
```
- **Status: Complete.** Tests: `Feature/Sales/CreditNoteLifecycleTest.php`,
  `Feature/Accounting/PayablesLifecycleTest.php`, `AccountingResourceTest.php`
- Gap: no inventory restock leg (see §3).

**Flow: Service record → inventory consumption**
`ServiceRecordPartService::consume` → `MovementType::ServiceConsumption`. **Complete.**

**Flow: Delivery → shipment → arrival**
`ShipmentService::confirmByAdmin | confirmByCustomer | confirmBySystem`,
`ShipmentConfirmationSource` (`Customer|AdminUser|System`), `inventory:shipments:auto-arrive`
hourly via `AutoArriveShipmentsCommand`, `eligibleForAutomaticArrival`. **Complete.**
Note `confirmByCustomer` exists but has no customer-facing entry point — it is invoked by an
admin recording the customer's confirmation.

**Flow: Reminders, invoice notices, task notifications (FR-023 notifications half)**
- Actual: `app/Notifications/` **does not exist**. The only outbound mail is `InvoiceMail` via
  `SendInvoiceEmail`. The only other notification is `NotifyAdminOfSalaryRecalculation`
  (an in-app/queued job). No payment reminders, no overdue-invoice notices, no task-assignment
  notifications, no scheduled reminder command.
- **Status: Partial** — invoice delivery only.

---

## 13. Systemic findings

Ordered by how much they would surprise someone reading the docs.

1. **No API surface at all.** `Docs/api/API_CONTRACT.md` (276 lines) describes endpoints that do
   not exist. `dedoc/scramble` (an OpenAPI generator) is a production dependency in
   `composer.json` with nothing to document. Every PRD flow for customers (§6.2) and employees
   (§6.3) is unreachable — both actor types exist as `UserType` cases but have no channel.
   This is consistent with ADRs 0003 and 0008; it is inconsistent with the PRD and the API contract doc.

2. **Three empty stub enums** — `InvoiceStatus`, `PaymentStatus`, `InvoiceConfirmationType` — with
   zero references. The lifecycles they name are implemented as raw strings. Invoices and payments
   are the only major documents in the system without a transition matrix.

3. **Two status modelling conventions coexist.** Enum-with-`canTransitionTo()`:
   quotations, credit notes, purchase orders, tickets, maintenance, operations, adjustments,
   corrections, returns, reservations, plans, tasks, visits, voice notes, salary, journal entries.
   Raw string compared inline: invoices, payments, bills, expenses, supplier payments, refunds —
   i.e. **every accounting/billing document**.

4. **Four navigation entries point at classes that do not exist**: `TaxDefinitionResource`,
   `DocumentTemplateResource`, `OperationalReportResource`, `Pages\Settings`
   (`app/Filament/AdminModuleRegistry.php:28,46,78`,`255,274-277`). `resolveLink()` guards with
   `class_exists()` and falls through to `ModulePlaceholder`, so this is a deliberate
   "declared, not built" pattern — but the `use` statements make it look like a broken build.

5. **Reservation expiry is unreachable.** `ReservationStatus::Expired` can only be produced by
   `InventoryReservationService::expire()`, whose only caller in the entire repository is a test.
   No scheduler entry exists. Manual release has the same problem: a service method, a permission
   (`inventory.reservation.release`, **0 app references**), and no UI action.

6. **Four permissions are declared but never checked**: `InventoryPermission::ReservationRelease`,
   and `AuditView` on `SalesPermission`, `AccountingPermission`, `EmployeePermission`.
   (`PurchasePermission::AuditView` and `CrmPermission::AuditView` *are* used.)

7. **Audit coverage is real but uneven.** 79 distinct activity-log event names, and a `withProperties`
   convention carrying `source_channel` + `ip_address`. But there is **no activity log for
   inventory operation completion** — the single most consequential act in the system logs only
   `inventory.operation.canceled`. Stock arrival/departure is traceable through
   `inventory_movements`, not the audit trail.

8. **Two dead resource directories**: `app/Filament/Resources/TaxRecognitionEntries/Pages/`
   (empty) and `app/Filament/Resources/InventoryExports/` (a `Schemas/` folder with no resource
   class). Both are rename leftovers.

9. **CRM is half the PRD's CRM.** Customers and pricing tiers are thorough; leads, interactions,
   campaigns, recipients and responses (FR-022) have no code at all. And `CustomerProfile` has
   only 2 relations, so no customer history surface exists despite every transaction table
   carrying `customer_id`.

10. **Documentation drift on removed features.** `warehouse_locations` and the original
    `audit_logs` table were created and then dropped; `stock_transfers`, `stock_reservations`,
    `inventory_receipts` were dropped in the spec-014 canonicalisation.
    `Docs/database/ERD.md` and `Docs/api/API_CONTRACT.md` were not verified against these removals
    in this pass, but the migration record shows the schema moved on. Tests exist that *assert the
    removal* (`LegacyInventoryRemovalTest`, `WarehouseLocationTrackingTest`,
    `ProductSubscriptionRemovalTest`, `VisitEditRemovedTest`), which is good discipline.

11. **Quality gates are enforced in code, not prose.** `Unit/ArchTest.php`,
    `Feature/InventoryDomainContractTest.php`, `Feature/Accounting/NoAutomaticPostingTest.php`,
    `Unit/AdminModuleRegistryTest.php`, `Feature/Filament/CoverageSurfaceTest.php`,
    `Unit/Coverage/PrimitiveCoverageTest.php`, per-module `CrossModulePermissionLeakTest` (×5),
    `phpstan-baseline.neon` (5.1 KB and shrinking per `.ai/feature-development` rule 7).
    The `InventoryOperationCompleted` docblocks explain *why* the listener is synchronous — this
    codebase documents its invariants at the point of enforcement.

---

## 14. Verification note

This map is derived from static reading of the source tree at `b29a49a`: models, migrations,
enums, services, events, listeners, jobs, observers, policies, Filament resources/pages/widgets,
routes, seeders, and the test file inventory. **The test suite was not executed during this
analysis**, so "Tests:" lines record that coverage exists and what it targets, not that it
currently passes. Run `composer test` to confirm.
