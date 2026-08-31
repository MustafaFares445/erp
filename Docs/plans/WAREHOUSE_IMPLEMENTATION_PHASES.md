# Warehouse Implementation Phases

## Execution rules

Each phase is independently reviewable and must retain a clean boundary: explicit-path staging, targeted Pest coverage, Pint for changed PHP, PHPStan for changed paths, architecture tests, and `git diff --check`. No phase may introduce a compatibility shim without a stated deletion phase. No phase may modify posted stock history destructively. New names such as `InventoryPostingService` are proposed target components until their phase creates them.

The current verification baseline is not green: six purchase-order receiving tests fail because their product fixtures lack `product_units` pivots. Phase 0/1 must repair the affected fixtures before later phase results are used as release evidence.

## Phase 0 — Canonical decisions, inventory contract, and safety net

| Item | Plan |
|---|---|
| Objective | Obtain the missing primary SRS, record owner decisions, publish the posting-contract interfaces, and establish tests that prevent additional stock-mutation paths. |
| Why now | Schema and caller work cannot safely start while UOM, return, transfer, reset, and FEFO decisions are unsettled. |
| Likely components | `Docs/plans/*`, `tests/Unit/ArchTest.php`, inventory service tests, `InventoryBalanceService`, mutation-caller search evidence. |
| Database impact | None. |
| Business behavior | No change. |
| Dependencies | Required Business Decisions below, especially the primary SRS and reset authorization. |
| Migration from current behavior | Inventory remains unchanged; document approved exception list for any temporary direct writer. |
| Tests | Repair the product-unit fixture baseline; architecture test identifies permitted current writers and forbids new direct writes; baseline the parallel-process test harness. |
| Acceptance criteria | Owner decisions recorded; 100% of known mutation paths listed; targeted baseline is green or every pre-existing failure is isolated and documented. |
| Legacy removable | None. |
| Risk / complexity | Low / Medium. |

## Phase 1 — Variant UOM foundation

| Item | Plan |
|---|---|
| Objective | Make base and alternate UOMs variant-owned, conversion-capable, precise, and immutable after stock history. |
| Why now | Every later stock change needs normalized base quantities; converting later would reinterpret historical balances. |
| Likely components | `Unit`, `Product`, `ProductVariant`, `ProductTypeGuard` successor, observers, product/variant forms, `product_units` migration path, factories/seeders/import validation, product unit tests. |
| Database impact | Add UOM family/precision fields and `product_variant_units`; add base-UOM/tracking-history guard; plan data-reset or signed mapping. Do not drop the old pivot yet. |
| Business behavior | Users define base, purchase, sale, and display UOMs per variant and explicit factors; no cross-family or fractional-invalid transaction is allowed. |
| Dependencies | Phase 0 UOM approval and development-data decision. |
| Migration from current behavior | Seed each variant’s existing `unit_id` as base UOM. Map alternates only from an approved factor list; do not infer Box or Container ratios. |
| Tests | Box/Piece, kg/Gram, Container, precision, invalid factor/family, base-unit immutability, product-unit factory/seeder/import repair. |
| Acceptance criteria | A line can normalize all required examples exactly; a stock-history variant cannot change base UOM; all fixture paths create valid variant UOM definitions. |
| Legacy removable | None; product-level unit picker remains read-only/transition-only until all callers migrate. |
| Risk / complexity | High / High. |

## Phase 2 — Canonical stock posting contract

| Item | Plan |
|---|---|
| Objective | Introduce `InventoryPostingService` and make the low-level balance writer internal to it. |
| Why now | The current public balance API allows a caller to change aggregate stock before/without coherent lot, serial, reservation, and ledger semantics. |
| Likely components | New typed posting command/allocation data objects; `InventoryPostingService`; refactored `InventoryBalanceService`; movement recorder; architecture test; current balance/lot/serial models. |
| Database impact | Add posting idempotency and movement source-line fields if needed before the first consumer migration. |
| Business behavior | No user-visible new workflow; existing migrated consumer behavior must be identical at this stage. |
| Dependencies | Phase 1 normalized UOM contract. |
| Migration from current behavior | Migrate one low-risk action first (damage or adjustment with non-tracked stock) and compare old/new movement and balance outcomes. |
| Tests | Atomic rollback, deterministic lock ordering, duplicate post/retry, no direct balance write outside posting infrastructure. |
| Acceptance criteria | A posting creates its balance and immutable movement or commits neither; the architecture test rejects a new direct writer. |
| Legacy removable | None. |
| Risk / complexity | High / High. |

## Phase 3 — Canonical receipt and catalog-import migration

| Item | Plan |
|---|---|
| Objective | Make receipt operations and catalog import use the canonical receipt workflow; retire legacy receipt writes. |
| Why now | Legacy receipt is a live, independent stock mutation path and handles lots differently from unified receipt. |
| Likely components | `InventoryOperationService`, receipt form/lines, `CatalogImportApplicationService`, `PurchaseOrderReceivingService`, `AdvancePurchaseOrderOnOperationCompleted`, `InventoryReceivingService`, import result DTOs, receipt resources/tests/seeders. |
| Database impact | Add receipt transaction/base quantity and allocation/origin columns; retain legacy receipt tables temporarily only for approved data migration. |
| Business behavior | Manual, PO, and import receipts create one canonical receipt document, normalize quantity, require needed lot/serial data, and post atomically. |
| Dependencies | Phases 1–2. |
| Migration from current behavior | Port catalog import first in a feature branch; compare imported balances/movements/lots/serials against expected canonical data. Update PO received quantity in base units. |
| Tests | Manual receipt, PO partial receipt/over-receipt, import grouped receipt, UOM, lot reuse, serial coverage, transaction rollback. |
| Acceptance criteria | No production path calls `InventoryReceivingService`; catalog import and PO receiving pass focused suites; received base quantity reconciles to PO. |
| Legacy removable | `InventoryReceivingService`, `ReceiptMovementContext`, receipt resource/policy become removable after data gate, but models/tables remain until Phase 10. |
| Risk / complexity | High / High. |

## Phase 4 — Canonical transfer and discrepancy workflow

| Item | Plan |
|---|---|
| Objective | Replace legacy transfers with canonical transfer posting, explicit actual receipt, and discrepancy disposition. |
| Why now | The legacy transfer resource/service is still writable and lacks lot awareness, cancellation, and the target discrepancy contract. |
| Likely components | `InventoryOperationService`, operation stages/line/allocation schema, transfer forms/actions/tables, `StockTransferService`, `StockTransfer` models/policy/observer/data, alerts/widgets/commands, packages/serials. |
| Database impact | Add dispatched/received base quantities and discrepancy/disposition fields or transfer allocation rows; indexes for active in-transit query. |
| Business behavior | Source decreases only when dispatched; destination increases only by confirmed actual receipt; unreceived quantity is explicitly cancelled/restored or recorded as shortage/damage. |
| Dependencies | Phases 1–3 and decision on partial transfer receiving. |
| Migration from current behavior | Move all TransferResource actions and alert/widget queries to operation transfers. Reconcile existing draft/dispatched/received legacy data before retiring direct URLs. |
| Tests | Dispatch, receive, partial receive, discrepancy, cancel remaining, lot/serial custody, package movement, simultaneous transfer races. |
| Acceptance criteria | `StockTransferService` has no runtime caller; every active transfer has canonical source/destination and allocation evidence; Shipment remains uninvolved. |
| Legacy removable | Transfer resource/pages, `StockTransferService`, policy/observer/data/enum become removable after Phase 10 data cleanup. |
| Risk / complexity | High / High. |

## Phase 5 — Authoritative reservations and allocations

| Item | Plan |
|---|---|
| Objective | Turn source-linked reservation allocations into the one reservation truth and reconcile aggregate reserved balance to them. |
| Why now | Delivery/transfer currently reserve invisible aggregates; the visible table is seed-only and operationally misleading. |
| Likely components | `StockReservation`, `ReservationService`, `InventoryOperationService::markReady/cancel`, order fulfillment, delivery wizard, reservation resource/policy/factory/seeder, lot service. |
| Database impact | Rename/rebuild as `inventory_reservations` with status/timestamps/source and allocation children; enforce unique active serial allocation and reservation indexes. |
| Business behavior | Ready outbound documents create source-linked reservations; consume/release/expire exactly once; stock availability states why quantity is committed. |
| Dependencies | Phases 1–2; canonical delivery and transfer allocation surface. |
| Migration from current behavior | Existing demo records may be reseeded. Do not synthesize unknown historical source links; reset or map them explicitly. |
| Tests | Create, partial consume, release, expiry, repeat release, lot/serial allocation, aggregate reconciliation and concurrent reservation. |
| Acceptance criteria | `markReady` creates reservation rows; `reserved_base_quantity` equals active allocations in every test/reconciliation report. |
| Legacy removable | Old seed-only semantics and old StockReservation model/resource names are removable only after the new surface owns all operations. |
| Risk / complexity | High / High. |

## Phase 6 — Lot/serial-safe adjustments and condition changes

| Item | Plan |
|---|---|
| Objective | Make adjustments, damage, recovery, disposal, and maintenance consumption allocation-aware and condition-safe. |
| Why now | Current adjustment can make aggregate and lot quantities disagree; maintenance can consume tracked stock without allocation. |
| Likely components | `InventoryAdjustmentService`, adjustment models/forms, `InventoryDamageService`, stock damage actions, `ServiceRecordPartService`, `InventoryLotService`, serialized timeline, condition reports/alerts. |
| Database impact | Condition balance dimension, lot balance table, adjustment allocation/line fields, serial custody/condition, movement condition/before-after values. |
| Business behavior | Counted-vs-system semantics remain, but a tracked item must count lot/serial identity. Damage moves condition, recovery reverses condition, disposal removes physical stock explicitly. |
| Dependencies | Phases 1–2 and allocation schema from Phase 5. |
| Migration from current behavior | Keep historical adjustments immutable; do not rewrite them. New corrections use canonical allocation lines. Use reset/mapping for old balance inconsistencies. |
| Tests | Aggregate/lot/serial reconciliation, full/partial count scope, serial in/out/custody, damage/recovery/disposal, maintenance consume/reverse, reservations conflict. |
| Acceptance criteria | No tracked adjustment or maintenance write can complete without matching allocations; no test can create aggregate/lot divergence. |
| Legacy removable | Direct BalanceService calls from Damage, Adjustment, Reservation, and Support are removed as each consumer moves to posting service. |
| Risk / complexity | High / High. |

## Phase 7 — Returns and stock-condition lifecycle

| Item | Plan |
|---|---|
| Objective | Build customer and supplier return documents, inspection/disposition, and real return movements. |
| Why now | The existing Return resource and movement enum are a dead/read-only stub, not an operational correction path. |
| Likely components | New return models/services/resources/policies; delivery operation relations; PO/receipt references; serial/lot allocation; `ReturnResource`; `MovementType::Return`; financial document references. |
| Database impact | `inventory_returns`, lines, disposition/inspection data, original allocation links, optional credit/refund/bill link, return movement origins. |
| Business behavior | Customer returns validate what was delivered and route stock to Saleable, Quarantine, Damaged, or supplier return. Supplier return sends tracked goods out; no financial document is created implicitly. |
| Dependencies | Phases 1–2, 5–6, and supplier-return first-release decision. |
| Migration from current behavior | Replace the placeholder return resource only after real document posting succeeds; do not fabricate old return records from a filtered movement view. |
| Tests | Return a delivered lot/serial, duplicate/over-return rejection, all dispositions, supplier return, customer custody, financial decoupling. |
| Acceptance criteria | `MovementType::Return` has exactly canonical return writer(s); UI cannot treat an adjustment or credit note as a stock return. |
| Legacy removable | Placeholder `Returns` page implementation is removed/replaced; the enum is kept and activated. |
| Risk / complexity | High / High. |

## Phase 8 — Reversal, correction, and transfer reconciliation

| Item | Plan |
|---|---|
| Objective | Standardise compensating corrections and make transfer shortages/reversal auditable. |
| Why now | Posted receipt/delivery documents are immutable but lack a dedicated general correction path; transfers require an outcome for unreceived quantity. |
| Likely components | Posting service reversal contract, operation workflows, return/adjustment links, transfer discrepancy records, movements, policies and reports. |
| Database impact | Reversal/compensating-movement references, correction document reference, discrepancy reason/disposition. |
| Business behavior | Users never edit a posted stock document. They create an authorized linked correction, return, or cancellation of still-in-transit quantity. |
| Dependencies | Phases 2–7. |
| Migration from current behavior | Keep previous done documents immutable; attach correction only prospectively unless data reset makes historical reseeding intentional. |
| Tests | Incorrect receipt correction, delivery correction through customer return, transfer cancel/shortage, idempotency, linked audit history. |
| Acceptance criteria | Every post-commit change is a new document/movement with an original reference; no policy/action enables destructive history rewrite. |
| Legacy removable | Ad-hoc correction paths and dead reversal comments/tests are removable once equivalent document flows are live. |
| Risk / complexity | Medium / High. |

## Phase 9 — Ledger enrichment, database constraints, and reports

| Item | Plan |
|---|---|
| Objective | Make movement history sufficient for traceability/reconciliation and move reporting to condition/base/transaction-aware data. |
| Why now | A posting engine needs observability; the current movement record cannot explain UOM, condition, or before/after state. |
| Likely components | `InventoryMovement`, movement/stock/lot/serial report resources, `InventoryReportService`, exports, dashboards/widgets, alerts, timeline service, migrations. |
| Database impact | UOM/base snapshot, condition, before/after, allocation/reversal references, checks/indexes; validate MySQL check support before relying on it. |
| Business behavior | Operators see transaction UOM and normalized quantity, current condition, lot/serial trace, and source documents without changing stock. |
| Dependencies | Canonical posting consumers from Phases 2–8. |
| Migration from current behavior | Historical ledger remains historical; use nullable/annotated legacy fields or reseed after approved reset, never invent exact conversion history. |
| Tests | Report/filter/export data integrity, trace chain, expiry/condition availability, constraint failure, reconciliation command no-write behavior. |
| Acceptance criteria | Every new posting produces complete movement context; read models reconcile to materialized balances; no report queries legacy transfer/receipt state. |
| Legacy removable | Legacy report and alert branches may be removed when their queries return canonical equivalents. |
| Risk / complexity | Medium / High. |

## Phase 10 — Delete legacy/dead implementation

| Item | Plan |
|---|---|
| Objective | Remove all migrated legacy receipt/transfer/backfill/placeholder reservation surfaces and their database objects. |
| Why now | Keeping duplicate writers after migration recreates the architectural risk this program addresses. |
| Likely components | Exact items in `WAREHOUSE_LEGACY_REMOVAL_PLAN.md`, registration/routes/policies/permissions/translations/seeders/factories/tests/docs. |
| Database impact | Forward drop migrations for legacy receipt/transfer tables, foreign keys, `legacy_*` operation provenance, old product-unit pivot after all callers move; no historical migration rewrites. |
| Business behavior | Only canonical menus/routes/workflows remain. Old direct URLs either have a short, explicitly scheduled redirect or return a clear retired-route response. |
| Dependencies | Phases 3–9, clean reconciliation report, approved reset/mapping. |
| Migration from current behavior | Run the component deletion protocol per item; separately archive data-migration evidence. |
| Tests | Zero-reference architecture tests; fresh migration/seed; navigation/routes; removed-resource/policy absence; canonical smoke suite. |
| Acceptance criteria | `rg` finds no runtime references to legacy writer classes/models; dropped tables are absent; no compatibility writer remains. |
| Legacy removable | All receipt/transfer/backfill items explicitly listed as safe in the removal plan. |
| Risk / complexity | High / High. |

## Phase 11 — Cross-module regression and authorization hardening

| Item | Plan |
|---|---|
| Objective | Close all consumer regressions in Purchasing, Sales, Shipment, Maintenance, Accounting references, imports, reports, permissions, and seed data. |
| Why now | The warehouse boundary is only coherent when all producers/consumers use its quantity and traceability contracts. |
| Likely components | PO, quotation/order lines, fulfillment/delivery, shipment, service records, financial links, resource registry, policies, permission seeders, demo seeders, test fixtures. |
| Database impact | Only narrow follow-up indexes/foreign keys discovered by integration tests; no new parallel model. |
| Business behavior | Correct UOM display/progress, stock reservation/consumption, trace links, and least-privilege actions across modules. |
| Dependencies | Phases 1–10. |
| Migration from current behavior | Replace fixtures and import payloads with canonical UOM/tracking data; do not reintroduce direct stock calls to satisfy a test. |
| Tests | Full affected feature suites, policy/permission boundaries, seed idempotency, architecture constraints, no automatic accounting posting. |
| Acceptance criteria | All direct consumers pass with canonical documents and no downstream module assumes variant unit equals every transaction unit. |
| Legacy removable | Remaining transitional translations, redirects, permission aliases, and fixture adapters. |
| Risk / complexity | Medium / High. |

## Phase 12 — Reconciliation, concurrency, reset, and release acceptance

| Item | Plan |
|---|---|
| Objective | Prove data integrity, true concurrency safety, development reset/reseed, and end-to-end acceptance before the module is considered launch-ready. |
| Why now | This is the evidence gate after all writers are consolidated and legacy objects are gone. |
| Likely components | Reconciliation command/report, all canonical inventory feature tests, migrations/seeders, architecture test, CI test commands and performance checks. |
| Database impact | Execute only the explicitly approved named development reset; verify clean schema and seed. |
| Business behavior | Full receipt -> reserve -> deliver -> return/correct -> report lifecycle is demonstrably traceable and non-destructive. |
| Dependencies | All prior phases and reset authorization. |
| Migration from current behavior | Export named data, reset, migrate, seed, reconcile, and archive outcome. If reset is declined, execute the owner-approved mapping instead. |
| Tests | Full Pest/Composer gate, Pint, PHPStan, real multi-connection delivery/reservation races, migration fresh/seed in test environment, manual Filament smoke flows. |
| Acceptance criteria | Zero reconciliation discrepancies; exactly one winner in concurrency tests; zero legacy runtime references; full quality gate passes; owner signs off on acceptance scenarios. |
| Legacy removable | None; this is the final evidence gate. |
| Risk / complexity | High / High. |

# Recommended Canonical Warehouse Architecture

IERP should retain `InventoryOperation` only for Receipts, Deliveries, and Internal Transfers, but route every stock-changing workflow through one `InventoryPostingService`. This is not a God model: adjustments, condition changes, and customer/supplier returns remain separate business documents. They share a typed posting contract that validates UOM conversion, allocations, stock condition, reservation, lot/serial custody, immutable movements, and row locks in one transaction.

The balance layer stores only normalized variant-base quantities, materialised at `(variant, warehouse, condition)`. It never receives a “box” or “kilogram” label. Variant UOM rows convert transaction quantities to base quantities and snapshot that conversion onto every commercial and stock document line. Product-level allowed-unit selection is replaced because it supplies no conversion semantics and cannot protect historical stock.

Lots are stable physical identities with per-warehouse/condition balances. Serials are one physical unit with explicit current custody. Reservations become real source-linked records with lot/serial allocation children whose active totals reconcile to materialised reserved quantity. This makes availability explainable and prevents the present invisible-reservation inconsistency.

Posted movements are append-only evidence, not the primary read model. Corrections are new linked documents/movements. Shipment remains logistics-only and never posts inventory. Receipts, import, transfers, maintenance consumption, adjustments, returns, and damage all become clients of the same posting boundary; no legacy direct stock writer survives.

# Delete / Keep / Refactor Matrix

| Component | Decision | Replacement | Reason |
|---|---|---|---|
| `InventoryOperation` | KEEP / REFACTOR | Canonical receipt, delivery, transfer workflow | Existing cross-module integration is useful; keep its scope narrow. |
| `InventoryPostingService` | ADD | Single public posting boundary | Enforces one stock behavior. |
| `InventoryBalanceService` | REFACTOR | Internal posting collaborator | It must not be a standalone public mutation API. |
| `InventoryReceipt`, items, resource, service | MIGRATE THEN DELETE | Canonical receipt operation | Live duplicate receipt writer used by catalog import. |
| `StockTransfer`, items, resource, service | MIGRATE THEN DELETE | Canonical transfer operation | Live duplicate transfer writer lacks target rules. |
| `StockReservation` | REFACTOR | `InventoryReservation` + allocations | Existing record must become authoritative, not seed-only. |
| Return placeholder | DELETE / ADD | Real `InventoryReturn` workflow | Current UI/type has no posting behavior. |
| `InventoryAdjustment` | KEEP / REFACTOR | Allocation-aware count adjustment | A distinct document is appropriate, current lot behavior is not. |
| Damage and maintenance consumers | REFACTOR | Canonical posting client | Must preserve lot/serial and movement invariants. |
| Product-level `product_units` | MIGRATE THEN DELETE | Variant-UOM conversion rows | It allows labels but has no quantity semantics. |
| ProductType-derived tracking flags | REFACTOR | Variant tracking profile | Current three cases are too restrictive as stock policy. |
| `InventoryOperationBackfiller` / reconciler | MIGRATE THEN DELETE | Release evidence / new reset reconciler | Only supports abandoned dual-write migration. |
| `Shipment` / `ShipmentService` | KEEP | Logistics confirmation | Verified non-posting behavior must remain. |
| `PurchaseOrderReceivingService` | KEEP / REFACTOR | Canonical receipt initiator | Correct domain ownership; update its UOM contract. |

# Required Business Decisions

| Question | Why it matters | Recommended default | Alternative | Implementation impact |
|---|---|---|---|---|
| Provide/approve `IERP-SRS-PIM-001 v1.1`. | It is named as the primary business source but is absent; current specs may not contain every required rule. | Attach it and record any conflicts before Phase 1. | Continue from derivative specs only. | Without it, a later SRS conflict can invalidate UOM, return, or stock-condition work. |
| May the named development database be reset after export/approval? | Existing quantities have no reliable conversion factors. | Yes: controlled reset/reseed after canonical migration work. | Signed per-variant conversion/data mapping. | Reset is lower risk; mapping adds a substantial data-migration and reconciliation program. |
| Is partial transfer receipt required? | It determines lifecycle, fields, discrepancy and reversal behavior. | Yes: support received quantity plus shortage/damage disposition. | All-or-nothing receipt only. | The alternative removes Phase 4 discrepancy modeling but cannot satisfy operational variance cases. |
| Is FEFO mandatory or a suggested default? | It determines whether allocation may be overridden. | Suggested FEFO with explicit allocation and audited expired-stock override. | Enforced automatic FEFO. | Automatic enforcement changes delivery/maintenance allocation UX and exception policy. |
| Is supplier return first-release scope? | Customer return is required by the brief; supplier return affects purchasing, inspection, and conditions. | Include supplier return after customer return, using the same return document model. | Defer outbound supplier return while retaining Return-to-Supplier inspection disposition. | Deferral leaves quarantined/damaged return inventory awaiting a later workflow. |
| Are warehouse bins/locations required now? | Locations alter every balance/allocation grain and add significant operational UI. | No: warehouse remains the custody/balance grain; packages remain containers. | Add bins now. | Adding locations now changes stock, lot, serial, reservation, transfer, and report schemas. |

# Implementation Readiness

**BLOCKED BY BUSINESS DECISIONS.** Implementation must not start until the missing primary SRS is reconciled and the development-data reset/mapping path is approved. Partial transfer receipt, FEFO policy, supplier-return scope, and location scope should be approved before their respective phases; the recommended defaults above allow the design to proceed immediately after approval.
