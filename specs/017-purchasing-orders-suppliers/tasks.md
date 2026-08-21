# Tasks: Purchasing — Purchase Orders and Supplier Confirmations

**Input**: Design documents from `/specs/017-purchasing-orders-suppliers/`

**Prerequisites**: [plan.md](./plan.md) (required), [spec.md](./spec.md) (required for user stories), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/permissions.md](./contracts/permissions.md)

**Tests**: Test tasks ARE included. Project rule 5 (`.ai/feature-development`) requires every behaviour change to ship with a Pest test, and the constitution's Principle VI item 6 requires a test for every implemented business rule. Coverage and type-coverage thresholds are 100 and must not be lowered.

**Organization**: Tasks are grouped by user story so each can be implemented and tested independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US7)
- Exact file paths are included in every task

## Path Conventions

Laravel modular monolith. Domain services under `app/Services/Purchasing/`, Filament resources under `app/Filament/Resources/`, tests under `tests/Feature/Purchasing/` and `tests/Unit/`.

---

## Phase 0: Governance Gate (BLOCKING — no implementation may begin)

**Purpose**: Constitution Principle I requires the approved documentation set to be in place before any code. Three of four items are complete.

- [X] T001 Amend `.specify/memory/constitution.md` to 1.6.0 with the ADR 0006 exception and extraction-order divergence note
- [X] T002 List the ADR 0006 exception in `Docs/PRD.md` §11
- [X] T003 Apply ERD extensions E-1…E-6 to `Docs/database/ERD.md`
- [X] T004 **PROJECT OWNER**: move `Docs/adr/0006-filament-purchasing-dashboard.md` Status from `Proposed` to `Accepted`

**⚠️ CHECKPOINT**: T004 is a human approval, not a code task. **No task from T005 onward may start until T004 is done.**

---

## Phase 1: Setup

**Purpose**: Directory scaffolding and translation keys

- [X] T005 [P] Create directories `app/Services/Purchasing/` and `app/Services/Purchasing/Exceptions/`
- [X] T006 [P] Create directory `tests/Feature/Purchasing/`
- [X] T007 Add purchasing labels, status names, action names, and validation messages to `lang/en/admin.php` (English-only per spec D6)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Enums, schema, models, and factories that every user story depends on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

### Enums

- [X] T008 [P] Create `PurchaseOrderStatus` enum with `canTransitionTo()`, `isReceivable()`, `isEditable()`, `isTerminal()` per data-model.md §8 in `app/Enums/PurchaseOrderStatus.php`
- [X] T009 [P] Create `SupplierConfirmationStatus` enum (pending/confirmed/rejected) in `app/Enums/SupplierConfirmationStatus.php`
- [X] T010 [P] Create `PurchasePermission` enum with the 18-value catalogue from contracts/permissions.md §1 in `app/Enums/PurchasePermission.php`
- [X] T011 Add `PurchasingManager` and `PurchasingOfficer` cases to `app/Enums/DashboardRole.php`

### Migrations (sequential — order per data-model.md §11)

- [X] T012 Create `purchase_settings` table migration in `database/migrations/`
- [X] T013 Create `purchase_orders` table migration with unique `purchase_order_number` and composite `(status, supplier_id)` index in `database/migrations/`
- [X] T014 Create `purchase_order_lines` table migration with unique `(purchase_order_id, product_variant_id, unit_id)` in `database/migrations/`
- [X] T015 Create `supplier_confirmations` table migration with `confirmable_type`/`confirmable_id` morph and `promised_at` in `database/migrations/`
- [X] T016 Add nullable `pending_reason` column to `orders` table in `database/migrations/`
- [X] T017 Add partial unique index on active `supplier_product_references` per `(supplier_id, product_variant_id)`, failing loudly on pre-existing duplicates rather than silently deactivating rows, in `database/migrations/`

### Models

- [X] T018 [P] Create `PurchaseSetting` singleton model in `app/Models/PurchaseSetting.php`
- [X] T019 [P] Create `PurchaseOrder` model with relations, casts, soft deletes, blameable, and non-fillable service-owned columns per data-model.md §10 in `app/Models/PurchaseOrder.php`
- [X] T020 [P] Create `PurchaseOrderLine` model with non-fillable `quantity_received`, `last_received_unit_cost`, `line_total` in `app/Models/PurchaseOrderLine.php`
- [X] T021 [P] Create `SupplierConfirmation` model with `confirmable()` morph in `app/Models/SupplierConfirmation.php`
- [X] T022 Add `purchaseOrders()` and `confirmations()` relations to `app/Models/Supplier.php`
- [X] T023 Add `confirmations()` morph relation and `pending_reason` handling to `app/Models/Order.php`
- [X] T024 Add `scopeActiveFor()` to `app/Models/SupplierProductReference.php`

### Factories

- [X] T025 [P] Create `PurchaseOrderFactory` with draft/approved/sent/received states in `database/factories/PurchaseOrderFactory.php`
- [X] T026 [P] Create `PurchaseOrderLineFactory` in `database/factories/PurchaseOrderLineFactory.php`
- [X] T027 [P] Create `SupplierConfirmationFactory` with pending/confirmed/rejected states in `database/factories/SupplierConfirmationFactory.php`
- [X] T028 [P] Create `PurchaseSettingFactory` in `database/factories/PurchaseSettingFactory.php`

### Foundational tests

- [X] T029 [P] Unit test every legal and illegal `PurchaseOrderStatus` transition in `tests/Unit/Enums/PurchaseOrderStatusTest.php`

**Checkpoint**: Schema and models ready — user story implementation can begin

---

## Phase 3: User Story 1 — Enforce Purchasing Roles and Permissions (Priority: P1) 🎯 MVP

**Goal**: Every purchasing action is governed by one of four fixed roles, enforced identically at the page checkpoint and the service checkpoint.

**Independent Test**: Sign in as each of the four roles and exercise the same action through a page visit, a record action, a bulk action, and a direct service call — allow/deny must match across all four paths.

### Tests for User Story 1

- [X] T030 [P] [US1] Test every ability × every role at both checkpoints in `tests/Feature/Purchasing/PurchasePermissionTest.php`
- [X] T031 [P] [US1] Test purchasing roles reach no other module, and that receiving needs no `inventory.*` permission (FR-008), in `tests/Feature/Purchasing/CrossModulePermissionLeakTest.php`

### Implementation for User Story 1

- [X] T032 [US1] Create `ChecksPurchasePermissions` trait with `authorizePurchaseAbility()` and unconditional `forceDelete() === false`, mirroring `ChecksSupportPermissions`, in `app/Policies/Concerns/ChecksPurchasePermissions.php`
- [X] T033 [P] [US1] Create `PurchaseOrderPolicy` in `app/Policies/PurchaseOrderPolicy.php`
- [X] T034 [P] [US1] Create `SupplierConfirmationPolicy` in `app/Policies/SupplierConfirmationPolicy.php`
- [X] T035 [P] [US1] Create `SupplierPolicy` in `app/Policies/SupplierPolicy.php`
- [X] T036 [P] [US1] Create `SupplierProductReferencePolicy` in `app/Policies/SupplierProductReferencePolicy.php`
- [X] T037 [P] [US1] Create `PurchaseSettingPolicy` in `app/Policies/PurchaseSettingPolicy.php`
- [X] T038 [US1] Register the five policies via `Gate::policy()` in `app/Providers/AppServiceProvider.php`
- [X] T039 [US1] Create `PurchasePermissionSeeder` implementing the contracts/permissions.md §2 role matrix, granting System Admin `PurchasePermission::values()` in full, in `database/seeders/PurchasePermissionSeeder.php`
- [X] T040 [US1] Register `PurchasePermissionSeeder` after `SupportPermissionSeeder` in `database/seeders/DatabaseSeeder.php`
- [X] T041 [US1] **REGRESSION** — run the Inventory, CRM, Employees, and Support authorization suites and fix any breakage caused by T011 narrowing `DashboardRole::fixedRoleNames()`

**Checkpoint**: Permission boundary complete and testable before any purchasing record exists

---

## Phase 4: User Story 2 — Draft a Purchase Order With Supplier Pricing (Priority: P1)

**Goal**: Draft a supplier purchase order with product-variant lines whose costs default from supplier product references, with stored line and document totals.

**Independent Test**: Create, edit, search, filter, and delete a draft purchase order without approving, sending, or receiving it.

### Tests for User Story 2

- [X] T042 [P] [US2] Test number uniqueness including soft-deleted rows and under concurrent creation (FR-011) in `tests/Feature/Purchasing/PurchaseOrderNumberTest.php`
- [X] T043 [P] [US2] Test drafting, cost defaulting, duplicate-line rejection, quantity/cost validation, total recomputation, search and filter in `tests/Feature/Purchasing/PurchaseOrderDraftTest.php`

### Implementation for User Story 2

- [X] T044 [US2] Create `PurchaseOrderNumberGenerator` using the existing `operation_number`/`order_number` sequence approach in `app/Services/Purchasing/PurchaseOrderNumberGenerator.php`
- [X] T045 [P] [US2] Create `PurchaseOrderNotEditable` exception in `app/Services/Purchasing/Exceptions/PurchaseOrderNotEditable.php`
- [X] T046 [US2] Create `PurchaseOrderService` handling draft creation, line mutation, cost defaulting from `SupplierProductReference`, and line/document total recomputation in `app/Services/Purchasing/PurchaseOrderService.php`
- [X] T047 [US2] Create `PurchaseOrderResource` in `app/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php`
- [X] T048 [P] [US2] Create `PurchaseOrderForm` schema with supplier/warehouse/currency/date fields and the lines repeater in `app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderForm.php`
- [X] T049 [P] [US2] Create `PurchaseOrdersTable` with search by number and supplier, and filters for status, warehouse, currency, and date range in `app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php`
- [X] T050 [P] [US2] Create `PurchaseOrderInfolist` schema in `app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php`
- [X] T051 [US2] Create List/Create/Edit/View pages in `app/Filament/Resources/PurchaseOrders/Pages/`
- [X] T052 [US2] Create `LinesRelationManager` in `app/Filament/Resources/PurchaseOrders/RelationManagers/LinesRelationManager.php`
- [X] T053 [P] [US2] Create `PurchaseSettingResource` and `ManagePurchaseSettings` page, mirroring `InventorySettings`, in `app/Filament/Resources/PurchaseSettings/`
- [X] T054 [US2] Replace the `admin.resources.purchase_orders` string stub with `PurchaseOrderResource::class` in `app/Filament/AdminModuleRegistry.php`

**Checkpoint**: A drafted, priced, searchable purchase order

---

## Phase 5: User Story 3 — Approve and Transmit a Purchase Order (Priority: P1)

**Goal**: Threshold-based approval with separation of duties, then transmission after which the order is immutable.

**Independent Test**: Submit one order below the threshold and one above it, approve the second, send both, and confirm the immutability boundary — without any confirmation or receipt.

### Tests for User Story 3

- [X] T055 [P] [US3] Test threshold branch, auto-approval attribution, self-approval refusal, rejection-and-return-to-draft, no retroactive approval, and concurrent-approval single winner in `tests/Feature/Purchasing/PurchaseOrderApprovalTest.php`
- [X] T056 [P] [US3] Test that a sent order is unchangeable via both the Filament action and a direct service call (SC-006) in `tests/Feature/Purchasing/PurchaseOrderImmutabilityTest.php`

### Implementation for User Story 3

- [X] T057 [P] [US3] Create `SelfApprovalRejected` exception in `app/Services/Purchasing/Exceptions/SelfApprovalRejected.php`
- [X] T058 [US3] Create `PurchaseOrderApprovalService` with `submit()`, `approve()`, `reject()`, `send()`, `cancel()`, `close()`, threshold evaluation at submission time, and the currency-mismatch rule from data-model.md §5 in `app/Services/Purchasing/PurchaseOrderApprovalService.php`
- [X] T059 [US3] Add the post-transmission immutability guard to `PurchaseOrderService` and `PurchaseOrderPolicy` so both checkpoints refuse edits (FR-025)
- [X] T060 [US3] Add Submit, Approve, Reject, Send, Cancel, and Short-close actions to `app/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php` and its pages
- [X] T061 [US3] Configure Spatie Activitylog on `PurchaseOrder` and `PurchaseOrderLine` so every transition records actor and timestamp (FR-054)

**Checkpoint**: The financial control point and immutability boundary work

---

## Phase 6: User Story 5 — Receive Against a Purchase Order (Priority: P1)

**Goal**: Receiving posts stock exclusively through the existing Inventory operation services, advancing purchase-order progress with over-receipt blocked.

**Independent Test**: Receive one order fully in a single receipt, and a second across two partial receipts, verifying stock, movements, and progress after each.

> Sequenced ahead of User Story 4 because US5 is P1 and US4 is P2 — a purchase order is fully useful without ever recording a confirmation.

### Tests for User Story 5

- [X] T062 [P] [US5] Architecture test asserting no class under `App\Services\Purchasing` or `App\Filament\Resources\PurchaseOrders` references `InventoryStock`, `InventoryMovement`, or a balance writer (SC-002) in `tests/Unit/ArchTest.php`
- [X] T063 [P] [US5] Test partial receipt, full receipt, receipt cancellation, non-receivable status refusal, inactive-warehouse refusal, and lot/serial/expiry passthrough in `tests/Feature/Purchasing/PurchaseOrderReceivingTest.php`
- [X] T064 [P] [US5] Test over-receipt rejection naming the offending line, and concurrent completion without double-counting (SC-004) in `tests/Feature/Purchasing/PurchaseOrderOverReceiptTest.php`

### Implementation for User Story 5

- [X] T065 [P] [US5] Create `PurchaseOrderNotReceivable` and `OverReceiptRejected` exceptions in `app/Services/Purchasing/Exceptions/`
- [X] T066 [US5] Add an operation-completed event to `app/Services/Inventory/InventoryOperationService.php` carrying no purchasing knowledge (R-002); if review rejects touching Inventory, implement instead as a purchasing-owned observer scoped to `source_document_type === PurchaseOrder::class`
- [X] T067 [US5] Create `PurchaseOrderReceivingService` that initiates a draft receipt operation pre-filled from open quantities, with the purchase order as `source_document` and the order's supplier and destination warehouse (FR-037), in `app/Services/Purchasing/PurchaseOrderReceivingService.php`
- [X] T068 [US5] Create `AdvancePurchaseOrderOnOperationCompleted` listener that locks the affected lines with `SELECT ... FOR UPDATE`, rejects over-receipt, increments `quantity_received`, records `last_received_unit_cost`, and advances order status — all inside the completing transaction (R-002, R-003) — in `app/Listeners/AdvancePurchaseOrderOnOperationCompleted.php`
- [X] T069 [US5] Register the listener in `app/Providers/AppServiceProvider.php`
- [X] T070 [US5] Add the Receive action to `app/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php`, visible only when `status.isReceivable()`
- [X] T071 [US5] Create `ReceiptsRelationManager` showing linked inventory operations and the ordered-vs-received cost variance in `app/Filament/Resources/PurchaseOrders/RelationManagers/ReceiptsRelationManager.php`

**Checkpoint**: Purchase orders become real inventory, with Principle III mechanically enforced

---

## Phase 7: User Story 4 — Record a Supplier Confirmation (Priority: P2)

**Goal**: Record the supplier's answer against either a purchase order or a customer order, append-only.

**Independent Test**: Record confirmations against both target types, exercise confirmed and rejected outcomes, and verify the parent document reacts — without any receipt.

### Tests for User Story 4

- [X] T072 [P] [US4] Test both target types, invalid-target rejection, append-only immutability, promised-date validation, chronological history, and customer-order status reaction in `tests/Feature/Purchasing/SupplierConfirmationTest.php`

### Implementation for User Story 4

- [X] T073 [P] [US4] Create `ConfirmationNotAmendable` exception in `app/Services/Purchasing/Exceptions/ConfirmationNotAmendable.php`
- [X] T074 [US4] Create `SupplierConfirmationService` enforcing the two-type target restriction, append-only rule, promised-date validation, and customer-order `pending_reason` and status transitions in `app/Services/Purchasing/SupplierConfirmationService.php`
- [X] T075 [US4] Flag a purchase order whose latest confirmation is a rejection without changing its lifecycle status (FR-034) in `app/Models/PurchaseOrder.php`
- [X] T076 [US4] Create `SupplierConfirmationResource` with status, supplier, and target-type filters in `app/Filament/Resources/SupplierConfirmations/SupplierConfirmationResource.php`
- [X] T077 [US4] Create `ManageSupplierConfirmations` page in `app/Filament/Resources/SupplierConfirmations/Pages/ManageSupplierConfirmations.php`
- [X] T078 [US4] Create `ConfirmationsRelationManager` in `app/Filament/Resources/PurchaseOrders/RelationManagers/ConfirmationsRelationManager.php`
- [X] T079 [US4] Replace the `admin.resources.supplier_confirmations` string stub with `SupplierConfirmationResource::class` in `app/Filament/AdminModuleRegistry.php`

**Checkpoint**: The ERD's sanctioned flow works on both document types

---

## Phase 8: User Story 6 — Maintain Supplier Product References and Costs (Priority: P2)

**Goal**: A first-class reference catalogue whose costs stay current automatically from completed receipts.

**Independent Test**: Manage references through the list surface, then complete a receipt at a different cost and verify the writeback.

### Tests for User Story 6

- [X] T080 [P] [US6] Test cost update, create-if-absent, currency follow without conversion, inactive-reference exclusion, and duplicate-active rejection in `tests/Feature/Purchasing/SupplierCostWritebackTest.php`

### Implementation for User Story 6

- [X] T081 [US6] Create `SupplierCostWritebackService` updating purchase cost and currency, or creating an active reference when absent, in `app/Services/Purchasing/SupplierCostWritebackService.php`
- [X] T082 [US6] Invoke the writeback from `AdvancePurchaseOrderOnOperationCompleted` inside the same transaction in `app/Listeners/AdvancePurchaseOrderOnOperationCompleted.php`
- [X] T083 [US6] Configure Spatie Activitylog on `SupplierProductReference` so the previous cost is retained (FR-048) in `app/Models/SupplierProductReference.php`
- [X] T084 [P] [US6] Create `SupplierProductReferenceResource` with search by supplier, variant SKU, supplier item number, and manufacturer in `app/Filament/Resources/SupplierProductReferences/SupplierProductReferenceResource.php`
- [X] T085 [US6] Create `ManageSupplierProductReferences` page in `app/Filament/Resources/SupplierProductReferences/Pages/ManageSupplierProductReferences.php`

**Checkpoint**: Reference costs stay current without manual maintenance

---

## Phase 9: User Story 7 — Report On and Audit Purchasing Activity (Priority: P3)

**Goal**: Open commitments, receiving performance, and cost variance, plus a complete audit trail.

**Independent Test**: With purchase orders in every status, open each report and the audit view and confirm figures reconcile against the underlying records.

### Tests for User Story 7

- [X] T086 [P] [US7] Test that open commitments reconcile exactly against ordered-minus-received for non-terminal orders (SC-007), and that export honours the same permission boundary as the on-screen report, in `tests/Feature/Purchasing/PurchasingReportTest.php`

### Implementation for User Story 7

- [X] T087 [US7] Create `PurchasingReportService` with open-commitments, receiving-performance, and cost-variance queries aggregating stored totals in `app/Services/Purchasing/PurchasingReportService.php`
- [X] T088 [US7] Create `PurchasingReportResource` registered under the existing `reports` navigation group, mirroring `InventoryReportResource`, in `app/Filament/Resources/PurchasingReports/PurchasingReportResource.php`
- [X] T089 [US7] Create `ListPurchasingReports` page with exports gated by `purchase.report.view` in `app/Filament/Resources/PurchasingReports/Pages/ListPurchasingReports.php`
- [X] T090 [US7] Add the purchasing audit trail view to the purchase-order View page in `app/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php`

**Checkpoint**: All seven user stories independently functional

---

## Phase 10: Polish & Cross-Cutting Concerns

- [X] T091 [P] Create `PurchasingDemoSeeder` producing purchase orders in every status, confirmations of both target types, and completed receipts, in `database/seeders/PurchasingDemoSeeder.php`
- [X] T092 Register `PurchasingDemoSeeder` after `SupportDemoSeeder` in `database/seeders/DatabaseSeeder.php`
- [X] T093 Run `vendor/bin/pint --dirty --format agent` and fix all formatting
- [X] T094 Run `composer test:types` and resolve every finding without adding a PHPStan baseline entry (project rule 7 — the baseline may only shrink)
- [ ] T095 Run `composer test:coverage` and `composer test:type-coverage`, holding both at 100 without lowering either threshold (project rule 8) — type coverage is at 100; code coverage reached 98.5% on the first pass and is being closed without lowering the threshold
- [~] T096 Walk every scenario in [quickstart.md](./quickstart.md) manually against the running dashboard — **automated portion done, manual browser walk outstanding.** Every `php artisan test --filter=...` command in quickstart.md passes, and `tests/Feature/Purchasing/PurchasingResourceTest.php` renders all eight purchasing surfaces through Livewire, which covers what the manual walk is for: a broken schema, a missing translation key, or a column pointing at a relation that does not exist. What it does **not** cover is a human signing in as each of the four roles in a browser. That still needs doing before release.
- [X] T097 Run `composer test` as the final gate; do not re-run single-worker after a passing parallel run

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 0 (Governance)**: BLOCKS everything. T004 is a project-owner signature.
- **Phase 1 (Setup)**: Depends on T004.
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all user stories.
- **Phase 3 (US1)**: Depends on Phase 2. Should complete before other stories so the permission boundary and the cross-module regression (T041) are settled early.
- **Phase 4 (US2)**: Depends on Phase 2. Independent of US1 in code, but US1 first is strongly recommended.
- **Phase 5 (US3)**: Depends on Phase 4 (needs a draft order to submit).
- **Phase 6 (US5)**: Depends on Phase 5 (receipt initiation requires a sent order).
- **Phase 7 (US4)**: Depends on Phase 5 for the purchase-order target; the customer-order target depends only on Phase 2.
- **Phase 8 (US6)**: Depends on Phase 6 (writeback fires on receipt completion).
- **Phase 9 (US7)**: Depends on all prior stories having produced data.
- **Phase 10 (Polish)**: Depends on all desired stories being complete.

### Critical Path

`T004 → Phase 2 → US1 → US2 → US3 → US5 → US6 → US7`

US4 branches off after US3 and is not on the critical path.

### Within Each User Story

- Tests are written before implementation and must fail first
- Enums and models before services
- Services before Filament resources
- Both permission checkpoints before the story is considered done

### Parallel Opportunities

- T005, T006 in Setup
- T008, T009, T010 (three enums, different files)
- T018–T021 (four models), then T025–T028 (four factories)
- All `[P]`-marked test tasks within a story
- US4 (Phase 7) can run in parallel with US6 (Phase 8) once US5 is done
- Migrations T012–T017 are **strictly sequential** — foreign keys depend on prior tables

---

## Parallel Example: Phase 2 Foundational

```bash
# Three enums together (different files, no dependencies):
Task: "Create PurchaseOrderStatus enum in app/Enums/PurchaseOrderStatus.php"
Task: "Create SupplierConfirmationStatus enum in app/Enums/SupplierConfirmationStatus.php"
Task: "Create PurchasePermission enum in app/Enums/PurchasePermission.php"

# After migrations, four models together:
Task: "Create PurchaseSetting model in app/Models/PurchaseSetting.php"
Task: "Create PurchaseOrder model in app/Models/PurchaseOrder.php"
Task: "Create PurchaseOrderLine model in app/Models/PurchaseOrderLine.php"
Task: "Create SupplierConfirmation model in app/Models/SupplierConfirmation.php"
```

---

## Implementation Strategy

### MVP Scope

The minimum that delivers real value is **US1 + US2 + US3 + US5** — the permission boundary, a priced purchase order, the approval gate, and receiving. That is the full P1 set and the point at which purchasing genuinely replaces whatever spreadsheet it supersedes. US4, US6, and US7 are valuable additions on top.

Stopping after US1 + US2 alone gives a drafting tool that commits nothing and receives nothing; it is a checkpoint, not a shippable increment.

### Incremental Delivery

1. Phase 0 → owner approval clears the gate
2. Phases 1–2 → schema and models ready
3. US1 → permission boundary provable, cross-module regression settled
4. US2 → drafting works → demo
5. US3 → approval and immutability → demo
6. US5 → **MVP complete**, stock posts through Inventory → demo
7. US4, US6, US7 → each adds value independently

### Risk Note

T041 (cross-module regression) and T066 (the Inventory event seam) are the two tasks most likely to surface unplanned work. T041 changes behaviour in four shipped modules; T066 touches a service outside the purchasing domain. Neither should be deferred to the end.

---

## Notes

- `[P]` tasks touch different files and have no incomplete dependencies
- `[Story]` labels map tasks to spec.md user stories for traceability
- Every service must enforce permissions itself, not rely on the Filament layer having hidden a button (FR-007)
- Nothing under `app/Services/Purchasing/` may write stock — T062 enforces this mechanically
- Commit after each task or logical group
