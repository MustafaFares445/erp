---
description: "Task list for 019-sales-lifecycle-payments-credits"
---

# Tasks: Sales Module Completion — Quotation → Delivery Note → Invoice → Payment, with Credit Notes

**Input**: Design documents from `/specs/019-sales-lifecycle-payments-credits/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: **Required, not optional.** Constitution Principle VI and `.ai/feature-development` §5 make a test per behaviour change mandatory, and SC-010 requires the existing coverage threshold to hold. Test tasks are therefore first-class here rather than an add-on.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel — different files, no dependency on an incomplete task
- **[Story]**: `[US1]`–`[US9]`, mapping to spec.md's user stories

## Path Conventions

Existing Laravel layout at repository root: `app/`, `database/`, `lang/`, `resources/`, `tests/`. No new base directory (CLAUDE.md). See plan.md §Project Structure.

## Phase Boundaries Are Binding

Constitution 1.8.0 §Specification Governance and ADR 0008 §Two reversals make the P1 → P3 ordering below the agreed mitigation for owner decision D5's size. **Each phase must end with the suite green.** Collapsing phases into one batch is a governance failure, not a scheduling choice.

Shippable stopping points: **end of Phase 5** (quoting, no invoicing), **end of Phase 9** (the financial claim), **end of Phase 11** (collection and correction).

---

## Phase 1: Setup — Governance Gate

**Purpose**: Close constitution Principle I. **Blocks every later phase.** T001 is not a documentation chore; without it every Filament class in this feature violates §Product Scope & Boundaries.

- [x] T001 Obtain project-owner approval and move `Docs/adr/0008-filament-sales-payments-dashboard.md` Status from `Proposed` to `Accepted`, adding the approval date
- [x] T002 [P] Record all ten ERD deviations from spec.md §ERD Divergence Register in `Docs/database/ERD.md`: add `sales_settings` (five account references, per data-model.md §1) and the `payment_methods` additions; mark `order_items`, `delivery_notes`, `delivery_note_items`, `invoice_files`, `stripe_payment_records` and `tax_definitions` as not created with their authority; record the `orders` in-place extension and its omitted `supplier_id`; add `sort_order` to the three line tables; add `credited_amount` and `recognised_tax_amount` to `invoices` per data-model.md §7
- [x] T003 [P] Update `Docs/database/ERD.md` §10 Status and Enum Catalog: replace the unimplemented `delivery_notes` catalogue with a pointer to `OperationStage` per contracts/lifecycles.md §3, and narrow the `payments` catalogue to the four manual states per contracts/lifecycles.md §5
- [x] T004 [P] Add the ADR 0008 exception to `Docs/PRD.md` §11, alongside the existing ADR 0006 and ADR 0007 entries
- [x] T005 Install the one approved dependency: `composer require barryvdh/laravel-dompdf` (owner decision D4 — the only dependency change this feature may make)
- [x] T006 Update the constitution's Sync Impact Report follow-up list in `.specify/memory/constitution.md`, moving the T001–T004 items from `PENDING` to `DONE`

**Checkpoint**: ADR 0008 is Accepted, the canonical docs match the design, and `composer test` still passes on an unchanged codebase.

---

## Phase 2: Foundational — Blocking Prerequisites

**Purpose**: The cross-cutting pieces no single story owns. **No user story may begin until this phase is complete.**

- [x] T007 [P] Create `app/Enums/SalesPermission.php` with the 26 cases in contracts/permissions.md §1, a `values(): list<string>` helper, and a class docblock naming the six deliberate separations and why each exists
- [x] T008 [P] Create `app/Policies/Concerns/ChecksSalesPermissions.php` following `ChecksAccountingPermissions`, consulting `DashboardRole::fixedRoleNames()` for the admin-bypass narrowing
- [x] T009 Add `SalesManager`, `SalesOfficer` and `BillingOfficer` cases to `app/Enums/DashboardRole.php`, extending the docblock to record that these narrow every other module's bypass
- [x] T010 Create `database/seeders/SalesPermissionSeeder.php` seeding all 26 permissions and the three roles per the contracts/permissions.md §2 matrix, and granting Sales Manager and Billing Officer exactly `accounting.journal-entry.post-from-source` and nothing else from the accounting catalogue (contracts/permissions.md §4). Depends on `AccountingPermission::JournalEntryPostFromSource` and the `createFromSource`/`postFromSource` split in `JournalEntryPolicy` and `JournalPostingService`, added directly to those 018 files as a correction found during this feature's design review — see contracts/permissions.md §4 for why granting the old `.manage`/`.post` pair would have leaked manual journal-entry creation to Sales roles
- [x] T011 Register `SalesPermissionSeeder` in `database/seeders/DatabaseSeeder.php`
- [x] T012 [P] Create `app/Services/Sales/DocumentNumberGenerator.php` — one parameterised generator, prefixes `QT-`/`INV-`/`PAY-`/`CN-`, `max()` under `lockForUpdate()` with `withTrashed()`, per research.md R-005
- [x] T013 [P] Create the `admin.sales` label tree in `lang/en/admin.php` and add the `admin.resources.sales_settings` key
- [ ] ~~T014~~ Moved to T031 — adding the `admin.resources.sales_settings` registry item before `SalesSettingResource` exists fails PHPStan (`class.notFound`) and `.ai/feature-development` §7 forbids a new baseline entry to work around it, unlike the five pre-existing placeholder items already grandfathered into `phpstan-baseline.neon`. The registry item and the resource are added together in Phase 4.
- [x] T015 [P] Create `tests/Unit/Enums/SalesPermissionTest.php` asserting the catalogue's completeness and that no separation implies another
- [x] T016 [P] Create `tests/Unit/ChecksSalesPermissionsTest.php` covering every branch of the concern

**Checkpoint**: `php artisan test --compact --filter=Sales` green; permission catalogue exists with no surface using it yet.

---

## Phase 3: User Story 1 — Enforce Sales Roles and Permissions (P1)

**Goal**: The permission catalogue is real, the three roles behave per the matrix, and no accounting or other-module permission leaks.

**Independent test**: Assign each role and assert service-level authorization and cross-module isolation. Per-page assertions arrive with each resource in its own phase — a page cannot be asserted before it exists — and Phase 12 consolidates them.

- [x] T017 [P] [US1] Create `tests/Feature/Sales/SalesPermissionTest.php` asserting the seeder produces exactly 26 permissions and three roles with the matrix's grants, no more and no fewer
- [x] T018 [P] [US1] Create `tests/Feature/Sales/SalesRoleNarrowingTest.php` proving that granting a System Admin any sales role removes their admin bypass in Inventory, CRM, Employees, Support, Accounting **and** Purchasing (FR-073), following `PurchasingRoleNarrowingTest`
- [x] T019 [P] [US1] Create `tests/Feature/Sales/CrossModulePermissionLeakTest.php` asserting a Billing Officer holds exactly one accounting permission (`accounting.journal-entry.post-from-source`), cannot reach any accounting page, and is refused by `JournalEntryPolicy::create()` when attempting to draft a manual (unsourced) journal entry directly (contracts/permissions.md §4)
- [x] T020 [US1] Create `tests/Unit/ArchTest.php` additions: no class under `app/Services/Sales` or `app/Services/Payments` may reference `auth()`, `request()`, or any ambient accessor (FR-077)

**Checkpoint**: The permission layer is provably sealed before any document exists.

---

## Phase 4: User Story 2 — Configure Payment Terms and Sales Settings (P1)

**Goal**: Due dates derive, overdue derives, exactly one default term exists, the tax rate validates, and posting accounts are guarded.

**Independent test**: Configure both surfaces and assert derivation and validation with no quotation or invoice in existence.

### Schema and reference data

- [x] T021 [P] [US2] Create migration `create_sales_settings_table` per data-model.md §1, all five account FKs nullable and `restrictOnDelete`
- [x] T022 [P] [US2] Create migration `create_payment_terms_table` per data-model.md §2
- [x] T023 [US2] Add `'2350' => 'Deferred Sales Tax'` under `2000 Liabilities` in `database/seeders/ChartOfAccountsSeeder.php`, with a comment citing research.md R-007 on why the posting is unrepresentable without it

### Models

- [x] T024 [P] [US2] Create `app/Models/SalesSetting.php` — singleton with `current()`, following `PurchaseSetting`
- [x] T025 [P] [US2] Create `app/Models/PaymentTerm.php` with `SoftDeletes`, `TracksBlameable`, and a `dueDateFrom(CarbonInterface): CarbonInterface` accessor
- [x] T026 [P] [US2] Create factories for both models in `database/factories/`

### Services

- [x] T027 [P] [US2] Create `app/Services/Sales/SalesAccountResolver.php` resolving each posting account and throwing a named exception when it is null, non-postable or inactive (FR-007), plus `app/Services/Sales/Exceptions/PostingAccountUnavailable.php`
- [x] T028 [P] [US2] Create `app/Services/Sales/LineTotalCalculator.php` computing line tax and line total, and document totals from lines, per data-model.md §4 arithmetic (FR-017, FR-018)
- [x] T029 [US2] Create `app/Services/Sales/PaymentTermService.php` enforcing the single-default invariant inside a transaction (FR-009) and refusing deletion of a referenced term (FR-012)

### Policies and surfaces

- [x] T030 [P] [US2] Create `app/Policies/SalesSettingPolicy.php` and `app/Policies/PaymentTermPolicy.php` per contracts/permissions.md §3
- [x] T031 [US2] Create `app/Filament/Resources/SalesSettings/` — resource, form and page, editing the singleton with account selects filtered to postable and active accounts. Also add the `admin.resources.sales_settings` item to the `sales` group in `app/Filament/AdminModuleRegistry.php` (moved from the original T014 — see the note there)
- [x] T032 [US2] Create `app/Filament/Resources/PaymentTerms/` — resource, form, table, and List/Create/Edit pages
- [x] T033 [US2] Register both resources in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [x] T034 [P] [US2] Add all Payment Terms and Sales Settings labels to `lang/en/admin.php` — done as part of T013's upfront `admin.sales` tree

### Tests

- [x] T035 [P] [US2] Create `tests/Feature/Sales/PaymentTermTest.php`: single-default invariant, due-date derivation for the 2026-09-01 + Net 30 = 2026-10-01 case, overdue derivation past grace, and refused deletion when referenced
- [x] T036 [P] [US2] Create `tests/Feature/Sales/SalesSettingTest.php`: the two boundary values (0, 100) round-trip through storage, singleton behavior, and account resolution refused for a missing, non-postable, or inactive account. **Refusing 101 or -1 is a Filament form validation rule (`minValue`/`maxValue`, matching `PurchaseSettingResource`'s own convention), not a domain guard — that refusal is asserted at T031 against the built form, not here**
- [x] T037 [P] [US2] Create `tests/Unit/LineTotalCalculatorTest.php`: the qty 2 × 100.00 at 5% ⇒ tax 10.00, line total 210.00 case, plus zero-rate and per-line-override cases
- [ ] ~~T038~~ Folded into T036's `SalesSettingTest.php` — the three refusal branches (missing, non-postable, inactive) are `SalesAccountResolver` calls exercised through the settings singleton, so a separate file would duplicate the same setup
- [x] T039 [US2] Extend `tests/Feature/Accounting/AccountingSeederTest.php` to assert `2350 Deferred Sales Tax` exists, is postable, and that re-running the seeder on an existing chart adds it without touching the other 27 accounts

**Checkpoint**: Configuration is complete and validated. Independently demoable.

---

## Phase 5: User Story 3 — Quote a Customer and Record Their Answer (P1)

**Goal**: A quotation can be priced from the customer's tier, sent, and decided — and provably touches no stock and no ledger.

**Independent test**: Create, price, send and decide a quotation end to end; assert zero stock effect and zero journal entries.

### Schema and enums

- [x] T040 [P] [US3] Create migration `create_quotations_table` per data-model.md §4, with `quotation_number` unique and `converted_order_id` unique
- [x] T041 [P] [US3] Create migration `create_quotation_lines_table` per data-model.md §4 — **no** unique index on `(quotation_id, product_variant_id)`, with a comment explaining the deliberate asymmetry with `order_lines`
- [x] T042 [P] [US3] Create `app/Enums/QuotationStatus.php` with `canTransitionTo()` per contracts/lifecycles.md §1, following `OperationStage`
- [x] T043 [P] [US3] Create `app/Enums/QuotationDecision.php` (`Accepted`, `Rejected`)

### Models

- [x] T044 [P] [US3] Create `app/Models/Quotation.php` with relations to customer, employee, sales opportunity, payment term, lines and converted order; `SoftDeletes`; `TracksBlameable`; and model-layer refusal of content changes once status is past `draft` (FR-023)
- [x] T045 [P] [US3] Create `app/Models/QuotationLine.php`
- [x] T046 [P] [US3] Create factories for both, including a `sent` and an `accepted` state

### Services

- [x] T047 [US3] Create `app/Services/Sales/QuotationService.php`: create/update with `PriceResolver` defaults and `assertAtOrAboveFloor()` guarding overrides **while draft only** (research.md R-002), total recomputation on line change, `send()`, and `recordDecision()` capturing outcome, date, note and recording user (FR-021)
- [x] T048 [US3] Resolve the customer's `User` from `CustomerProfile::user()` before calling `PriceResolver`, storing the returned `ResolvedPriceSource` on the line (research.md R-001, FR-015)
- [x] T049 [P] [US3] Create `app/Services/Sales/Exceptions/` entries for below-floor override, decision on a non-sent quotation, and decision past expiry
- [x] T050 [US3] Add expiry handling: default `expires_at` from `sales_settings.default_quotation_validity_days`; refuse acceptance past expiry and store `expired`; derive `expired` for presentation on a `sent` quotation past its date (contracts/lifecycles.md §1)

### Policy and surface

- [x] T051 [P] [US3] Create `app/Policies/QuotationPolicy.php` with `send`, `decide` and `convert` abilities per contracts/permissions.md §3
- [x] T052 [US3] Create `app/Filament/Resources/Quotations/` — resource, form with a repeater showing the resolved price source per line, infolist, table with status filters, List/Create/Edit/View pages
- [x] T053 [US3] Create `app/Filament/Resources/Quotations/Actions/QuotationActions.php` for Send and Record Decision, each deriving visibility from the policy rather than a `visible()` closure
- [x] T054 [US3] Add a "Create Quotation" action to `app/Filament/Resources/SalesOpportunities/`, enabled only for an **approved** opportunity, carrying customer and note across and recording the resulting quotation (FR-025)
- [x] T055 [US3] Register `QuotationResource` in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [x] T056 [P] [US3] Add all Quotation labels to `lang/en/admin.php`

### Tests

- [x] T057 [P] [US3] Create `tests/Feature/Sales/QuotationPricingTest.php`: tier-resolved default, source recorded, below-floor override refused with the floor stated, floor **not** re-applied after send
- [x] T058 [P] [US3] Create `tests/Feature/Sales/QuotationLifecycleTest.php`: every legal and illegal transition in contracts/lifecycles.md §1, including decision on a draft refused and acceptance past expiry refused
- [x] T059 [P] [US3] Create `tests/Feature/Sales/QuotationImmutabilityTest.php`: content frozen from `sent`, by service and by direct model write
- [x] T060 [P] [US3] Create `tests/Feature/Sales/QuotationTouchesNoStockTest.php` — invariant `I-1`: no reservation, no movement, no on-hand change, in every quotation state, and no journal entry
- [x] T061 [P] [US3] Create `tests/Feature/Sales/QuotationFromOpportunityTest.php` (FR-025), including that a non-approved opportunity offers no action
- [x] T062 [P] [US3] Create `tests/Feature/Sales/QuotationNumberTest.php`: uniqueness including over soft-deleted rows
- [x] T063 [P] [US3] Create `tests/Feature/Sales/QuotationResourceTest.php`: per-role page and action visibility for the Quotations surface

**Checkpoint**: **Shippable** as a quoting module. Quote, send, decide. No invoicing, no stock, no ledger.

---

## Phase 6: User Story 4 — Turn an Accepted Quotation into a Priced Order (P2)

**Goal**: Conversion is exact and idempotent, and the built fulfillment machinery is provably unchanged. **Highest regression risk in the feature.**

**Independent test**: Convert and assert totals match exactly; then run every pre-existing fulfillment test unmodified.

- [x] T064 [P] [US4] Create migration `add_sales_pricing_to_orders_table`: `quotation_id` (unique, nullable), `payment_term_id`, `subtotal`, `tax_total`, `grand_total`, `payment_status` — **all nullable, nothing renamed, nothing backfilled** (research.md R-004)
- [x] T065 [P] [US4] Create migration `add_pricing_to_order_lines_table`: `unit_price`, `tax_amount`, `line_total` — all nullable, with a comment on why not `default 0`
- [x] T066 [P] [US4] Create `app/Enums/OrderPaymentStatus.php` per contracts/lifecycles.md §2
- [x] T067 [US4] Extend `app/Models/Order.php`: add the new fillable columns, the `quotation()` and `paymentTerm()` relations — **changing no existing relation or cast**. The `invoices()` relation is deferred to T093 (Phase 8): `Invoice` does not exist until then, and referencing `Invoice::class` here would be a `phpstan` `class.notFound` that `.ai/feature-development` §7 forbids baselining around
- [x] T068 [US4] Extend `app/Models/OrderLine.php` with the three price columns and their casts
- [x] T069 [US4] Create `app/Services/Sales/QuotationConversionService.php` per data-model.md §6: guard `accepted` and not-yet-converted, copy document totals **verbatim**, aggregate duplicate variants into one order line to satisfy the built unique index, and write both link columns in one transaction
- [x] T070 [US4] Add the Convert action to `app/Filament/Resources/Quotations/Actions/QuotationActions.php`
- [x] T071 [US4] Add View and Edit pages to `app/Filament/Resources/Orders/` (FR-028), with an infolist showing commercial detail, the source quotation, and priced lines
- [x] T072 [US4] Extend `app/Filament/Resources/Orders/Tables/OrdersTable.php` with grand total and payment status columns, rendering blank rather than zero for pre-019 orders
- [x] T073 [US4] Extend `app/Policies/OrderPolicy.php` with the `sales.order.view` and `sales.order.manage` abilities, leaving every existing rule intact
- [x] T074 [P] [US4] Add Order commercial labels to `lang/en/admin.php` — already present from T013's upfront `admin.sales` tree (order_payment_status, actions.convert*, notifications.converted, errors.already_converted/not_acceptable_status, fields.order_number/payment_status/source_quotation)
- [x] T075 [P] [US4] Create `tests/Feature/Sales/QuotationConversionTest.php`: totals equal exactly; second conversion refused; rejected, expired and draft quotations refused
- [x] T076 [P] [US4] Create `tests/Feature/Sales/QuotationConversionAggregationTest.php` — invariant `I-7`: a quotation with the **same variant on two lines** converts to one order line, and the order's document totals still equal the quotation's **exactly** to the cent
- [x] T077 [P] [US4] Create `tests/Feature/Sales/LegacyOrderCompatibilityTest.php`: an order created without pricing opens, displays blank prices, and remains fully usable in the fulfillment flow
- [x] T078 [US4] Run `php artisan test --compact` over the full existing suite and confirm **zero** pre-existing test required modification — invariant `I-12`, FR-083. If one did, the migration was not additive; fix the migration, not the test

**Checkpoint**: Conversion works and nothing regressed. This checkpoint is the phase's real deliverable.

---

## Phase 7: User Story 5 — Work Delivery Notes Off the Existing Operations (P2)

**Goal**: A Sales-facing delivery surface that provably reuses the one stock-writing path.

**Independent test**: Complete a delivery from the new surface; assert the movement is identical to one raised inside Inventory, and the ledger is empty.

- [ ] T079 [US5] Create `app/Filament/Resources/DeliveryNotes/DeliveryNoteResource.php` with `$model = InventoryOperation::class`, `getEloquentQuery()` scoped to `operation_type = 'delivery'`, and **no new model and no new table** (owner decision D3)
- [ ] T080 [US5] Create `app/Filament/Resources/DeliveryNotes/Tables/DeliveryNotesTable.php` showing the originating commercial document via the `sourceDocument` morph, customer, warehouse, stage and line count
- [ ] T081 [US5] Create `app/Filament/Resources/DeliveryNotes/Schemas/DeliveryNoteInfolist.php` with lines, and a derived `converted_to_invoice` indicator based on whether an invoice references this operation (contracts/lifecycles.md §3)
- [ ] T082 [US5] Wire stage actions to the existing `InventoryOperationService::markReady()`, `dispatch()`, `complete()` and `cancel()` — **adding no stock mutation and no new guard** (FR-034)
- [ ] T083 [US5] Gate the surface on `sales.delivery-note.view` for reading while leaving every stock-mutating action gated by the **unchanged** `InventoryOperationPolicy` (contracts/permissions.md §1)
- [ ] T084 [US5] Register `DeliveryNoteResource` in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [ ] T085 [P] [US5] Add Delivery Note labels to `lang/en/admin.php`
- [ ] T086 [P] [US5] Create `tests/Feature/Sales/DeliveryNoteSurfaceTest.php`: only delivery operations listed; receipts and internal transfers never appear
- [ ] T087 [P] [US5] Create `tests/Feature/Sales/DeliveryNoteStockPathTest.php` — invariant `I-10`: completing from this surface produces movements **identical in shape** to one raised inside Inventory, and leaves the ledger empty (FR-035)
- [ ] T088 [P] [US5] Create `tests/Feature/Sales/DeliveryNotePermissionTest.php`: a Sales Officer can read the surface but **cannot** complete a delivery

**Checkpoint**: The delivery surface exists and demonstrably added no second stock path.

---

## Phase 8: User Story 6 — Issue an Invoice, Send It, and Capture Receipt (P2)

**Goal**: Invoices exist, are immutable and undeletable once issued, and carry a PDF and append-only receipt evidence.

**Independent test**: Invoice a completed delivery, issue, generate, email, confirm — asserting immutability at each step.

### Schema, enums, models

- [ ] T089 [P] [US6] Create migration `create_invoices_table` per data-model.md §7, with `inventory_operation_id` **unique** and both stored aggregates
- [ ] T090 [P] [US6] Create migration `create_invoice_lines_table` with `order_line_id` provenance and `sort_order`
- [ ] T091 [P] [US6] Create migration `create_invoice_confirmations_table` — no `signature_path`, no `deleted_at`, no `updated_by`
- [ ] T092 [P] [US6] Create `app/Enums/InvoiceStatus.php` with `canTransitionTo()` per contracts/lifecycles.md §4, and `app/Enums/InvoiceConfirmationType.php`
- [ ] T093 [US6] Create `app/Models/Invoice.php`: relations, `SoftDeletes`, `TracksBlameable`, `HasMedia` with an `invoice-pdf` collection that is **not** `singleFile()` so regeneration retains the prior file (research.md R-006), a derived `isOverdue()`, and model-layer refusal of content change and of `deleting` once `issued_at` is set
- [ ] T094 [P] [US6] Create `app/Models/InvoiceLine.php`
- [ ] T095 [P] [US6] Create `app/Models/InvoiceConfirmation.php` with `HasMedia` for the signature and model-layer refusal of `updating` and `deleting` (FR-049), following `MaintenanceRecord`
- [ ] T096 [P] [US6] Create factories for all three, including `draft` and `issued` states

### Services and jobs

- [ ] T097 [US6] Create `app/Services/Sales/InvoiceService.php`: create from a delivery operation (asserting stage `done`, type `delivery`, not already invoiced) with lines from **delivered** quantities and prices from the originating order line; create standalone with manual lines; derive the due date from the payment term; recompute totals while draft
- [ ] T098 [US6] Add the pre-issue guards: refuse issuing when any line has a null unit price (FR-040) and when the source order is pending supplier confirmation (FR-031)
- [ ] T099 [P] [US6] Create `app/Services/Sales/InvoiceConfirmationService.php` recording an append-only confirmation with an optional signature attachment
- [ ] T100 [P] [US6] Create `resources/views/pdf/invoice.blade.php`
- [ ] T101 [US6] Create `app/Jobs/GenerateInvoiceDocument.php` rendering with dompdf and attaching to the `invoice-pdf` collection, retaining the prior file (FR-047)
- [ ] T102 [US6] Create `app/Jobs/SendInvoiceEmail.php` and its mailable, leaving the invoice's status unchanged on failure and surfacing the failure (FR-048)

### Policies and surface

- [ ] T103 [P] [US6] Create `app/Policies/InvoicePolicy.php` and `app/Policies/InvoiceConfirmationPolicy.php` per contracts/permissions.md §3
- [ ] T104 [US6] Create `app/Filament/Resources/Invoices/` — resource, form, infolist showing totals, balance and PDF history, table with status and overdue filters, List/Create/Edit/View pages
- [ ] T105 [US6] Create `app/Filament/Resources/Invoices/Actions/InvoiceActions.php` for Issue, Send, Regenerate PDF and Confirm Receipt
- [ ] T106 [US6] Create `app/Filament/Resources/Invoices/RelationManagers/` for lines and confirmations, the confirmations manager being read-plus-create only
- [ ] T107 [US6] Add a "Create Invoice" action to `app/Filament/Resources/DeliveryNotes/`, enabled only for a `done`, not-yet-invoiced delivery
- [ ] T108 [US6] Register `InvoiceResource` in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [ ] T109 [P] [US6] Add all Invoice labels to `lang/en/admin.php`

### Tests

- [ ] T110 [P] [US6] Create `tests/Feature/Sales/InvoiceFromDeliveryTest.php`: delivered quantities drive lines; prices carry from the order line; a null-priced origin blocks issuing until priced; a non-`done` or already-invoiced operation is refused
- [ ] T111 [P] [US6] Create `tests/Feature/Sales/InvoiceStandaloneTest.php`: a manual service invoice touches no stock
- [ ] T112 [P] [US6] Create `tests/Feature/Sales/InvoiceDueDateTest.php`: the 2026-09-01 + Net 30 case, override accepted, override before invoice date refused
- [ ] T113 [P] [US6] Create `tests/Feature/Sales/InvoiceImmutabilityTest.php` — invariant `I-3`: content frozen once issued; `delete()`, `forceDelete()` and cascade all refused; a draft deletes
- [ ] T114 [P] [US6] Create `tests/Feature/Sales/InvoiceDocumentTest.php`: PDF attached; regeneration produces a new current file and **retains** the previous one; email failure leaves status unchanged
- [ ] T115 [P] [US6] Create `tests/Feature/Sales/InvoiceConfirmationTest.php`: confirmation stored with signature; subsequent edit and delete both refused
- [ ] T116 [P] [US6] Create `tests/Feature/Sales/InvoiceOverdueTest.php`: derived overdue past due plus grace, with **no** row rewritten
- [ ] T117 [P] [US6] Create `tests/Feature/Sales/InvoicePendingSupplierTest.php` (FR-031)
- [ ] T118 [P] [US6] Create `tests/Feature/Sales/InvoiceResourceTest.php`: per-role page and action visibility

**Checkpoint**: Invoices exist and are immutable — but post nothing yet. Deliberately the next phase.

---

## Phase 9: User Story 7 — Invoice Issuance Reaches the Ledger (P2)

**Goal**: The ledger's first caller. Tax is deferred, not recognised.

**Independent test**: Issue one invoice; assert the entry's shape, balance, source link, and that tax payable is untouched.

- [ ] T119 [US7] Create `app/Services/Sales/InvoicePostingService.php` per contracts/posting.md §1: resolve accounts through `SalesAccountResolver`, build the three lines omitting the tax line when `tax_total` is zero, and call `JournalPostingService::postNew()` with the invoice as `source` — validating **neither** balance nor period, which the accounting service owns (research.md R-011)
- [ ] T120 [US7] Wrap `issued_at` and the posting in one `DB::transaction()` so a failed posting leaves the invoice a `draft` (FR-044)
- [ ] T121 [US7] Surface the posted entry on the invoice's infolist, linking to the journal entry
- [ ] T122 [US7] Add activity logging of issuance per ADR 0005
- [ ] T123 [P] [US7] Create `tests/Feature/Sales/InvoicePostingTest.php`: the 1000.00 + 50.00 case producing Dr receivable 1050.00 / Cr revenue 1000.00 / Cr deferred tax 50.00; the source link; the zero-tax two-line case
- [ ] T124 [P] [US7] Create `tests/Feature/Sales/InvoicePostingTaxTimingTest.php` — invariant `I-9`, SC-009: `2300 Sales Tax Payable` has **exactly zero** movement from issuance. Assert on the account's movement, not on the service
- [ ] T125 [P] [US7] Create `tests/Feature/Sales/InvoicePostingAtomicityTest.php` — invariant `I-8`: a closed fiscal period refuses issuing and leaves the invoice a draft with no entry; a null configured account and a non-postable configured account each refuse with the account named
- [ ] T126 [P] [US7] Assert the posted entry is immutable and reversible only through the accounting layer's own rules

**Checkpoint**: **Shippable** as quote → order → deliver → invoice, with correct accounting. No collection yet.

---

## Phase 10: User Story 8 — Collect Payment and Recognise Tax Proportionally (P3)

**Goal**: The feature's hardest rule. Recognised tax equals invoice tax exactly, at any split, in any order.

**Independent test**: One partial then one settling payment; assert balances, statuses, both entries and the tax at each step.

### Schema, enums, models

- [ ] T127 [P] [US8] Create migration `create_payment_methods_table` per data-model.md §3, with `chart_account_id` non-null and `restrictOnDelete`
- [ ] T128 [P] [US8] Create migration `create_payments_table` per data-model.md §8
- [ ] T129 [P] [US8] Create migration `create_payment_allocations_table` with unique `(payment_id, invoice_id)`
- [ ] T130 [P] [US8] Create migration `create_manual_payment_records_table` — no `proof_file_path`
- [ ] T131 [P] [US8] Create migration `create_tax_recognition_entries_table` with unique `(invoice_id, payment_id)`, no `deleted_at`, no `updated_by`
- [ ] T132 [P] [US8] Create `app/Enums/PaymentStatus.php` with the four manual states and `canTransitionTo()` per contracts/lifecycles.md §5, documenting why the ERD's online-only states are absent
- [ ] T133 [P] [US8] Create `app/Models/PaymentMethod.php` and `app/Models/PaymentTerm.php` relations as needed
- [ ] T134 [US8] Create `app/Models/Payment.php` with `HasMedia` for `payment-proof`, `SoftDeletes`, and model-layer refusal of change and of `deleting` once `posted_at` is set
- [ ] T135 [P] [US8] Create `app/Models/PaymentAllocation.php`, `app/Models/ManualPaymentRecord.php`, and `app/Models/TaxRecognitionEntry.php` — the last refusing `updating` and `deleting` (FR-062)
- [ ] T136 [P] [US8] Create factories for all of the above

### Services

- [ ] T137 [US8] Create `app/Services/Payments/PaymentAllocationService.php` per research.md R-009: `lockForUpdate()` per invoice, derive outstanding inside the lock, validate, write, update `paid_amount` and status, and **return whether the invoice is now settled** — with no separate balance service
- [ ] T138 [US8] Create `app/Services/Payments/TaxRecognitionService.php` per contracts/tax-recognition.md: proportional per allocation, with the **settling** allocation absorbing the exact residue, settlement evaluated inside the allocation's lock; no row and no entry when the recognised amount is zero
- [ ] T139 [US8] Create `app/Services/Payments/PaymentPostingService.php` per contracts/posting.md §2a: the collection entry, omitting the customer-deposits line when fully allocated. **No branch on payment channel**
- [ ] T140 [US8] Create `app/Services/Payments/PaymentService.php`: record with proof enforcement (FR-053), orchestrate allocation, posting and recognition in **one** transaction, and `reverse()` restoring every affected invoice's `paid_amount`, `recognised_tax_amount` and status while preserving all original rows
- [ ] T141 [P] [US8] Create `app/Services/Payments/Exceptions/` entries for over-allocation, allocation above an invoice balance, missing required proof, and reversing an already-reversed payment

### Policies and surfaces

- [ ] T142 [P] [US8] Create `app/Policies/PaymentMethodPolicy.php`, `app/Policies/PaymentPolicy.php`, `app/Policies/PaymentAllocationPolicy.php` and `app/Policies/TaxRecognitionEntryPolicy.php` per contracts/permissions.md §3
- [ ] T143 [US8] Create `app/Filament/Resources/PaymentMethods/` — resource, form with an account select filtered to postable and active, table, pages
- [ ] T144 [US8] Create `app/Filament/Resources/Payments/` — resource, form with an allocation repeater showing each invoice's outstanding balance, infolist showing both entries and the recognition rows, table, pages
- [ ] T145 [US8] Create `app/Filament/Resources/Payments/Actions/PaymentActions.php` for Post and Reverse
- [ ] T146 [US8] Add a "Record Payment" action to `app/Filament/Resources/Invoices/`, pre-allocating the invoice's outstanding balance
- [ ] T147 [US8] Register both resources in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [ ] T148 [P] [US8] Add all Payment and Payment Method labels to `lang/en/admin.php`

### Tests

- [ ] T149 [P] [US8] Create `tests/Feature/Sales/PaymentCollectionTest.php`: the 525.00-of-1050.00 case — paid amount, `partially_paid`, Dr bank / Cr receivable — then settlement to `paid`
- [ ] T150 [P] [US8] Create `tests/Feature/Sales/TaxRecognitionTest.php` — invariant `I-4`, SC-004: 25.00 on the half payment; **exactly 50.00** total after settlement; the three-equal-thirds case producing 16.67 / 16.67 / **16.66**; and the same allocations in several orderings all totalling 50.00
- [ ] T151 [P] [US8] Create `tests/Feature/Sales/TaxRecognitionEdgeCaseTest.php`: zero-tax invoice writes no row and no entry; a credited invoice's lowered settlement threshold still absorbs the residue exactly
- [ ] T152 [P] [US8] Create `tests/Feature/Sales/PaymentAllocationTest.php`: multi-invoice allocation; the 100.00 remainder to customer deposits; allocation above an invoice balance refused; allocations above the payment refused
- [ ] T153 [P] [US8] Create `tests/Feature/Sales/PaymentConcurrencyTest.php`: two concurrent allocations that would over-allocate — one refused, recognised tax consistent with the one that succeeded
- [ ] T154 [P] [US8] Create `tests/Feature/Sales/PaymentImmutabilityTest.php` — invariant `I-3`: a posted payment refuses edit and delete; reversal restores balances, statuses and recognition, and preserves every original row
- [ ] T155 [P] [US8] Create `tests/Feature/Sales/PaymentProofTest.php` (FR-053)
- [ ] T156 [P] [US8] Create `tests/Feature/Sales/PaymentChannelIsolationTest.php` — invariant `I-11`, FR-061: neither payment service references a channel identifier, and no class other than `PaymentPostingService` and `TaxRecognitionService` posts for a payment
- [ ] T157 [P] [US8] Create `tests/Feature/Sales/InvoiceBalanceInvariantTest.php` — invariants `I-4` and `I-6`: `recognised_tax_amount` equals its derived aggregate and `paid_amount` never exceeds grand total less credited, after every scenario

**Checkpoint**: Collection and tax recognition are correct. The module's hardest rule holds.

---

## Phase 11: User Story 9 — Correct an Invoice with a Credit Note (P3)

**Goal**: The only correction path for an issued invoice, with a reversal split by recognition ratio.

**Independent test**: Credit a partially paid invoice; assert the remaining balance, the status, and the deferred-versus-recognised split.

- [ ] T158 [P] [US9] Create migration `create_credit_notes_table` per data-model.md §9, `invoice_id` nullable
- [ ] T159 [P] [US9] Create migration `create_credit_note_lines_table` with `invoice_line_id` nullable and `sort_order`
- [ ] T160 [P] [US9] Create `app/Enums/CreditNoteStatus.php` with `canTransitionTo()` per contracts/lifecycles.md §6
- [ ] T161 [US9] Create `app/Models/CreditNote.php` with `HasMedia` for `credit-note-pdf`, `SoftDeletes`, and model-layer refusal of change and of `deleting` once `confirmed_at` is set
- [ ] T162 [P] [US9] Create `app/Models/CreditNoteLine.php` and factories for both
- [ ] T163 [US9] Create `app/Services/Sales/CreditNoteService.php`: draft with per-line and per-document caps against the uncredited remainder (FR-064), `confirm()` orchestrating the posting and the invoice update in one transaction, and `reverse()` restoring `credited_amount` and status
- [ ] T164 [US9] Create `app/Services/Sales/CreditNotePostingService.php` per contracts/posting.md §3: the four lines with the tax split derived by `recognisedPortion = round(tax × share, 2)` and `deferredPortion = tax − recognisedPortion` so the entry always balances (research.md R-010); omit either zero line; treat a standalone note's share as zero
- [ ] T165 [US9] Update the invoice's status on confirmation — `credited` on a full credit, balance-driven on a partial (FR-067)
- [ ] T166 [P] [US9] Create `resources/views/pdf/credit-note.blade.php` and `app/Jobs/GenerateCreditNoteDocument.php` using the same path as the invoice's (FR-069)
- [ ] T167 [P] [US9] Create `app/Policies/CreditNotePolicy.php` with `confirm` and `reverse` abilities
- [ ] T168 [US9] Create `app/Filament/Resources/CreditNotes/` — resource, form with an invoice-line picker showing each line's uncredited remainder, infolist, table, pages
- [ ] T169 [US9] Create `app/Filament/Resources/CreditNotes/Actions/CreditNoteActions.php` for Confirm, Reverse and Regenerate PDF
- [ ] T170 [US9] Add a "Create Credit Note" action to `app/Filament/Resources/Invoices/`, enabled only for an issued invoice with an uncredited remainder
- [ ] T171 [US9] Register `CreditNoteResource` in `app/Providers/Filament/AdminPanelServiceProvider.php`
- [ ] T172 [P] [US9] Add all Credit Note labels to `lang/en/admin.php`
- [ ] T173 [P] [US9] Create `tests/Feature/Sales/CreditNoteCapTest.php` — invariant `I-5`: per-line and per-document caps refused when exceeded; `credited_amount` equals its derived aggregate
- [ ] T174 [P] [US9] Create `tests/Feature/Sales/CreditNotePostingTest.php`: unpaid invoice reverses from deferred tax only; **half-paid** invoice splits 50/50 and the entry balances; fully paid invoice reverses from tax payable only; a standalone note reverses entirely from deferred tax
- [ ] T175 [P] [US9] Create `tests/Feature/Sales/CreditNoteInvoiceStatusTest.php`: full credit sets `credited`; partial credit leaves the status balance-driven
- [ ] T176 [P] [US9] Create `tests/Feature/Sales/CreditNoteImmutabilityTest.php` — invariant `I-3`: confirmed note refuses edit and delete; reversal restores the invoice; a draft deletes
- [ ] T177 [P] [US9] Create `tests/Feature/Sales/CreditNoteOverpaidTest.php`: crediting a fully paid invoice drives the receivable negative, leaving a customer credit balance — **recorded, not blocked**
- [ ] T178 [P] [US9] Create `tests/Feature/Sales/CreditNoteTouchesNoStockTest.php` (FR-070)
- [ ] T179 [P] [US9] Create `tests/Feature/Sales/CreditNoteResourceTest.php`: per-role visibility, confirming that Billing Officer may draft but not confirm

**Checkpoint**: **Shippable** as the complete lifecycle.

---

## Phase 12: Polish and Cross-Cutting Concerns

**Purpose**: The system-wide guarantees. These fail last and matter most.

- [ ] T180 Tighten `tests/Feature/Accounting/NoAutomaticPostingTest.php` per research.md R-015 and contracts/posting.md §6: keep all six existing assertions **unmodified**, and add one asserting the ledger's `source_type` values are exactly `Invoice`, `Payment` and `CreditNote` after a scenario exercising every document in the system. **Do not add `SalesDemoSeeder` to the demo-seeder sweep**
- [ ] T181 [P] Create `tests/Feature/Sales/PurchasingLedgerProhibitionTest.php` asserting a full purchase-order lifecycle — approve, send, receive, confirm — still writes zero journal entries (FR-081, ADR 0006 unrelaxed)
- [ ] T182 [P] Create `tests/Feature/Sales/TicketPaymentNotWiredTest.php` asserting a settled chargeable ticket still writes zero journal entries and has no link to any `Payment` (FR-082, ADR 0004 unrelaxed)
- [ ] T183 [P] Extend `tests/Unit/AdminModuleRegistryTest.php`: all six `sales` items plus the new `sales_settings` item and both `system` items resolve to real resources (FR-001, FR-002); Tax Definitions, Document Templates, Settings, AR, AP, Bills, Expenses, Refunds, Taxes and Financial Reports still resolve to the placeholder (FR-003)
- [ ] T184 [P] Create `tests/Feature/Sales/SalesNavigationTest.php`: the sidebar scopes correctly to the sales module and each resource's navigation sort falls inside the group's reserved range (FR-004)
- [ ] T185 [P] Create `tests/Feature/Sales/SalesEnglishLabelsTest.php` asserting every label key this feature added exists in `lang/en/admin.php` (FR-078)
- [ ] T186 [P] Create `tests/Feature/Sales/SalesAuditLogTest.php` asserting activity entries for quotation decisions, conversion, invoice issuance, payment posting, payment reversal, credit-note confirmation, credit-note reversal and PDF regeneration (FR-076)
- [ ] T187 [P] Create `tests/Feature/Sales/SalesModelRelationsTest.php` covering every relation in data-model.md §11
- [ ] T188 Create `database/seeders/SalesDemoSeeder.php` driving the real services end to end — quote, convert, deliver, invoice, part-pay, settle, credit — so the demo data exercises the same paths a user would
- [ ] T189 [P] Create `tests/Feature/Sales/SalesDemoSeederTest.php` asserting the seeder is idempotent and that its resulting ledger is internally consistent: receivable movement ties to invoices less credits (SC-003), and deferred plus payable tax ties to invoiced versus collected tax (SC-009)
- [ ] T190 Add the `sales` group to `app/Filament/Resources/AuditLogs/` filtering so sales activity is reachable under `sales.audit.view`
- [ ] T191 Run `vendor/bin/pint --dirty --format agent` and fix every finding
- [ ] T192 Run `vendor/bin/phpstan analyse` and fix every finding **without adding a baseline entry** — the baseline may only shrink (`.ai/feature-development` §7). Remove any entry this feature's changes made obsolete
- [ ] T193 Run `composer test` and confirm the whole gate passes at the existing minimum coverage threshold (SC-010)
- [ ] T194 Walk `quickstart.md` §Manual walk-through end to end, confirming the one-screen proof: `2350 Deferred Sales Tax` holds exactly the tax of what is invoiced and uncollected, and `2300 Sales Tax Payable` holds exactly the tax of what is collected

---

## Dependencies

```
Phase 1 (Governance) ──► Phase 2 (Foundational) ──► Phase 3 (US1)
                                                       │
                                    ┌──────────────────┘
                                    ▼
                              Phase 4 (US2) ──► Phase 5 (US3) ──► Phase 6 (US4) ──► Phase 7 (US5)
                                                                                        │
                                                              ┌─────────────────────────┘
                                                              ▼
                                                        Phase 8 (US6) ──► Phase 9 (US7)
                                                                             │
                                                              ┌──────────────┘
                                                              ▼
                                                       Phase 10 (US8) ──► Phase 11 (US9) ──► Phase 12
```

The chain is genuinely sequential, unlike most features — and it is worth being explicit about why, because the template's expectation is that stories are mostly independent:

- **US2 before US3**: a quotation line cannot compute tax without the settings singleton, and cannot resolve a due date without a payment term.
- **US3 before US4**: conversion needs something to convert.
- **US4 before US5 before US6**: an invoice's lines come from a delivery, whose prices come from an order, whose prices come from a quotation. This is Principle III's mandated lifecycle order, so the dependency is a requirement rather than an artefact.
- **US6 before US7**: posting needs an invoice to post.
- **US7 before US8**: proportional recognition needs deferred tax to have been credited by an issuance.
- **US8 before US9**: the reversal split reads how much tax a payment already recognised.

**US1 is the only story that could move.** It is first because every other story's tests assert authorization, and building the surfaces before the catalogue would mean revisiting each one.

## Parallel Opportunities

`[P]` tasks within a phase touch different files and may run concurrently. The largest clusters:

| Phase | Parallel cluster |
|---|---|
| 1 | T002, T003, T004 — three separate documentation files |
| 2 | T007, T008, T012, T013, T015, T016 |
| 4 | T021–T022 (migrations), T024–T026 (models), T027–T028 (services), T035–T038 (tests) |
| 5 | T040–T043, T044–T046, T057–T063 (seven test files) |
| 8 | T089–T092, T110–T118 (nine test files) |
| 10 | T127–T132 (six migrations), T149–T157 (nine test files) |
| 11 | T173–T179 (seven test files) |
| 12 | T181–T187, T189 — nearly the whole phase |

Migrations within a phase are `[P]` to **author** but must be applied in dependency order; the file timestamps encode that order.

## Implementation Strategy

**MVP**: Phases 1–5. Permissions, configuration, and a working quoting module. Delivers real value — a salesperson can quote a customer at tier-correct prices with a floor guard — while touching no money, no stock and no ledger. It is also the cheapest phase to get wrong and the easiest to review.

**Increment 2**: Phases 6–9. The financial claim. Conversion, the delivery surface, invoices, and the ledger's first caller. The riskiest increment, because Phase 6 modifies live tables and Phase 9 is where a tax-timing mistake would become a statutory misstatement.

**Increment 3**: Phases 10–12. Collection, correction, and the system-wide guarantees.

**If review capacity runs out**, stop at the end of Phase 5, 9 or 11 — never mid-increment. Each leaves the suite green and the module coherent. Stopping mid-increment leaves either an invoice that cannot be paid or a payment that cannot be corrected, and both are worse than not shipping.

## Task Summary

| Phase | Story | Tasks | Count |
|---|---|---|---|
| 1 | — | T001–T006 | 6 |
| 2 | — | T007–T016 | 10 |
| 3 | US1 (P1) | T017–T020 | 4 |
| 4 | US2 (P1) | T021–T039 | 19 |
| 5 | US3 (P1) | T040–T063 | 24 |
| 6 | US4 (P2) | T064–T078 | 15 |
| 7 | US5 (P2) | T079–T088 | 10 |
| 8 | US6 (P2) | T089–T118 | 30 |
| 9 | US7 (P2) | T119–T126 | 8 |
| 10 | US8 (P3) | T127–T157 | 31 |
| 11 | US9 (P3) | T158–T179 | 22 |
| 12 | — | T180–T194 | 15 |
| | | **Total** | **194** |

Of these, **73 are test tasks** — 38% of the feature. That ratio is intentional: nine of the twelve invariants in data-model.md §12 protect a constitutional non-negotiable, and an invariant with no test is a comment.
