# Module Capability Matrix

**Generated:** 2026-09-03 · **Branch:** `feat/cross-module-remediation` (`b29a49a`) · Read-only analysis.
**Companion:** [CURRENT_IMPLEMENTATION_MAP.md](CURRENT_IMPLEMENTATION_MAP.md) — scenario-level detail and evidence.

---

## Legend

| Mark | Meaning |
|---|---|
| ● | Present and enforced |
| ◐ | Partially present — one or two legs of the four-leg test missing |
| ○ | Absent |
| ⊘ | Absent **by design** — an ADR explicitly excludes it |
| ✕ | Present but incorrect or self-inconsistent |

**Four-leg test** (from the discovery brief): a capability counts only when a *business rule*
is enforced in code, a *workflow* (state machine or orchestrated action) exists, a *UI surface*
exposes it, and *tests* validate it. A database table alone counts for nothing.

---

## 1. Module readiness overview

| Module | Entities | Business rule | Workflow | UI | Tests | Overall |
|---|---|---|---|---|---|---|
| Products & Catalog | 17 tables | ● | ● | ● | ● | **Complete** |
| Inventory | 22 tables | ● | ● | ● | ● | **Complete** |
| Sales | 11 tables | ● | ● | ● | ● | **Complete** (2 defects) |
| Purchasing | 7 tables | ● | ● | ● | ● | **Complete** |
| Accounting | 10 tables | ● | ● | ● | ● | **Complete** (1 defect) |
| Payments | 5 tables | ● | ● | ● | ● | **Complete** (manual channel only) |
| Tax | 1 table | ● | ● | ◐ | ● | **Partial** (register read-only; no rate catalogue) |
| CRM | 6 tables | ● | ● | ● | ● | **Partial** (customers + tiers only) |
| Employees | 14 tables | ● | ● | ● | ● | **Complete** (review-side only) |
| Support | 5 tables | ● | ● | ● | ● | **Complete** |
| Maintenance | 3 tables | ● | ● | ● | ● | **Complete** (no preventive scheduling) |

---

## 2. Capability matrix — core dimensions

| Capability | Products | Inventory | Sales | Purchasing | Accounting | Payments | Tax | CRM | Employees | Support | Maintenance |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Domain models + migrations | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Factories | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Seeders / demo data | ● | ● | ● | ● | ● | ● | ○ | ● | ● | ● | ● |
| Service layer | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Typed status enum + transition matrix | ● | ● | ✕ | ● | ✕ | ✕ | n/a | ● | ● | ● | ● |
| Typed domain exceptions | ○ | ○ | ● | ● | ● | ○ | ○ | ○ | ● | ● | ● |
| Transactional writes (`DB::transaction`) | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Pessimistic locking on money/stock | ● | ● | ● | ● | ● | ● | ● | ○ | ○ | ○ | ○ |
| Policies + granular permissions | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Filament resource (CRUD or manage) | ● | ● | ● | ● | ● | ● | ◐ | ● | ● | ● | ● |
| Lifecycle actions in UI | ● | ● | ● | ● | ● | ● | ○ | ● | ● | ● | ● |
| Dashboard page + widgets | ● | ● | ● | ● | ● | ● | ○ | ● | ● | ● | ● |
| Reports surface | ● | ● | ○ | ● | ● | ○ | ◐ | ● | ● | ● | ◐ |
| CSV / file export | ● | ● | ○ | ● | ● | ○ | ○ | ● | ● | ○ | ○ |
| PDF document generation | ○ | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Queued jobs | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ |
| Scheduled reconciliation command | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ |
| Activity-log audit trail | ● | ◐ | ● | ● | ● | ● | ○ | ● | ● | ● | ● |
| Media / attachments | ● | ● | ● | ○ | ● | ○ | ○ | ● | ● | ● | ○ |
| Global search | ● | ● | ○ | ○ | ○ | ○ | ○ | ● | ● | ○ | ○ |
| Feature tests | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Concurrency tests | ○ | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Permission-leak tests | ● | ● | ● | ● | ● | ● | ○ | ● | ● | ● | ● |
| **HTTP API** | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ |
| **Customer-facing channel** | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ◐ | ⊘ | ⊘ | ⊘ |
| **Employee mobile channel** | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ | ⊘ |

CRM's ◐ for customer-facing: the public `/join-us` self-registration form is the only
non-admin write path in the entire application (`routes/web.php`, `JoinUsController`).

---

## 3. Lifecycle inventory

Every state machine actually implemented, and how it is enforced.

| Lifecycle | States | Enforcement | Module |
|---|---|---|---|
| `OperationStage` | 7 | enum + `canTransitionTo($target, OperationType)` | Inventory |
| `AdjustmentStatus` | 2 | enum + service guard | Inventory |
| `InventoryCorrectionStatus` | 3 | enum + service guard | Inventory |
| `InventoryReturnStatus` | 4 | enum + service guard | Inventory |
| `ReservationStatus` | 4 | enum; `Expired` unreachable in production | Inventory |
| `SerializedInventoryUnitStatus` × `SerializedCustodyType` | 10 × 7 | service-driven | Inventory |
| `ShipmentStatus` | 2 | enum + 3 confirmation sources | Inventory |
| `StockCondition` | 4 | enum + condition balances | Inventory |
| `InventoryImportRunStatus` / `…ItemStatus` | 9 / 5 | enum + jobs | Products |
| `PriceChangeRequestStatus` | 3 | enum + approval service | Products |
| `QuotationStatus` | 7 | enum + `InvalidQuotationTransition` | Sales |
| `CreditNoteStatus` | 4 | enum + `canTransitionTo()` + `isTerminal()` | Sales |
| `OrderPaymentStatus` | 3 | enum, **derived only** — never hand-written | Sales |
| **Invoice status** | 5 | **raw string, no guard** ✕ | Sales |
| **Payment status** | 3 | **raw string, no guard** ✕ | Payments |
| `PurchaseOrderStatus` | 9 | enum + `canTransitionTo()` + service guards | Purchasing |
| `SupplierConfirmationStatus` | 4 | enum | Purchasing |
| `JournalEntryStatus` | 2 | enum + `PostedEntryIsImmutable` | Accounting |
| **Bill / Expense / SupplierPayment / Refund status** | 4–5 each | **raw string, inline literals** ✕ | Accounting |
| `SalesPlanStatus` | 5 | enum + service `transition()` | Employees |
| `PlanTaskStatus` | 4 | enum + `task_status_logs` | Employees |
| `VisitStatus` | 4 | enum | Employees |
| `VoiceNoteStatus` / `TranscriptionStatus` | 4 / 3 | enum + job | Employees |
| `SalesOpportunityStatus` | 3 | enum + review service | Employees |
| `SalaryCalculationStatus` | 4 | enum + supersede-on-recalculate | Employees |
| `BonusSuggestionStatus` | 3 | enum + approval service | Employees |
| `TicketStatus` | 9 | enum + explicit matrix + maintenance-open guard | Support |
| `PaymentLinkStatus` | 3 | enum | Support |
| `MaintenanceStatus` | 4 | enum, shared by records and tasks | Maintenance |

**23 enum-guarded lifecycles. 6 raw-string lifecycles — all of them financial documents.**

---

## 4. Side-effect map

Which module writes what when a business action fires.

| Trigger | Inventory ledger | Journal entry | Tax entry | Audit log | Media/PDF | Queue | Event |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Operation `complete()` (receipt/delivery) | ● | ○ | ○ | ○ | ● | ○ | ● |
| Operation `dispatch()` / `receiveTransfer()` | ● | ○ | ○ | ○ | ○ | ○ | ● |
| Operation `cancel()` | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Adjustment `confirm()` | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Correction `post()` | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Return `post()` | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Damage / recover / dispose | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Service-record part `consume()` / `reverse()` | ● | ○ | ○ | ● | ○ | ○ | ○ |
| Catalog import `apply()` | ● | ○ | ○ | ● | ○ | ● | ○ |
| Quotation `send` / `recordDecision` | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Quotation `convert()` | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Order fulfilment `create()` | ○¹ | ○ | ○ | ● | ● | ○ | ○ |
| Invoice `issue()` | ○ | ● | ○ | ● | ● | ● | ○ |
| Invoice `send()` | ○ | ○ | ○ | ● | ● | ● | ○ |
| Invoice receipt confirm | ○ | ○ | ○ | ● | ● | ○ | ○ |
| Payment `post()` | ○ | ●● | ● | ● | ○ | ○ | ○ |
| Payment `reverse()` | ○ | ●● | ● | ● | ○ | ○ | ○ |
| Credit note `confirm()` / `reverse()` | ○² | ● | ○ | ● | ● | ● | ○ |
| PO submit / approve / send / close / cancel | ○ | ○ | ○ | ● | ○ | ○ | ○ |
| PO receipt (via listener) | ●³ | ○ | ○ | ● | ○ | ○ | ○ |
| Supplier confirmation `answer()` | ○ | ○ | ○ | ● | ○ | ○ | ○ |
| Bill / Expense `approve()` | ○ | ● | ● | ● | ● | ○ | ○ |
| Expense / Supplier payment `pay()` | ○ | ● | ○ | ● | ○ | ○ | ○ |
| Refund `approve()` / `pay()` | ○ | ● | ● | ● | ○ | ○ | ○ |
| Journal `post()` / `reverse()` | ○ | ● | ○ | ● | ○ | ○ | ○ |
| Ticket transition / assign | ○ | ○ | ○ | ● | ● | ○ | ○ |
| Ticket payment `settle()` | ○ | ⊘ | ⊘ | ● | ○ | ○ | ○ |
| Maintenance record transition | ○ | ○ | ○ | ● | ○ | ○ | ○ |
| Voice note intake | ○ | ○ | ○ | ● | ● | ● | ○ |
| Salary calculate / recalculate | ○ | ○ | ○ | ● | ○ | ● | ○ |
| Price update / floor override | ○ | ○ | ○ | ● | ○ | ○ | ○ |

¹ Creates the delivery operations; stock moves only when they complete.
² Deliberate — ADR 0008 excludes goods-return movements from credit notes.
³ Advances PO quantities inside the inventory transaction; posts no accounting artefact (ADR 0006).
`●●` = two journal entries (cash/AR, then tax).

**Only three documents post to the general ledger automatically** — invoice issuance,
payment collection, credit-note confirmation — plus the four named payables callers.
`Feature/Accounting/NoAutomaticPostingTest.php` guards this.

---

## 5. Gap register

### Missing by design (ADR-excluded — do not treat as defects)

| Gap | Authority |
|---|---|
| Any HTTP API; customer app; employee mobile app | PRD §11, ADR 0003, ADR 0008 |
| Stripe / any online payment channel | ADR 0008 |
| Customer-driven quotation accept/reject | ADR 0008 |
| Goods-return inventory movement from a credit note | ADR 0008 |
| COGS posting / inventory valuation / FIFO / moving average | ADR 0007, ADR 0008, ADR 0006 |
| Multi-currency, revaluation | ADR 0007 |
| Year-end close into retained earnings; budgets; bank reconciliation | ADR 0007 |
| Recurring or scheduled journal entries; approval beyond `draft→posted` | ADR 0007 |
| Bills / AP / supplier payments **inside Purchasing** | ADR 0006 (lives in Accounting per ADR 0011) |
| Supplier returns & debit notes; landed cost; requisitions & RFQs; reorder-point buying; PO email/EDI | ADR 0006 |
| Supplier portal; public website | PRD §11 |
| Customer credit limits; sales commission; recurring billing; dunning | PRD §11, ADR 0008 |
| Warehouse locations / bin tracking | spec 012/014 (replaced by Packages) |
| Ticket payments wired to Payments/accounting | ADR 0008, spec 016 D4 |
| Employee-app visit & attendance capture | ADR 0003 |

### Genuine gaps (no spec, no ADR — unaccounted for)

| # | Gap | Impact | Evidence |
|---|---|---|---|
| G1 | **CRM leads, interactions, campaigns, recipients, responses** — FR-022, a PRD core feature — have no model, table, service, resource or test. | A whole named feature is unbuilt with no deferral record. | No matching model in `app/Models`; not in the created-table list |
| G2 | **Invoice export to CSV/Excel** (FR-006). | Stated requirement, no implementation. No export action on any sales resource. | `app/Filament/Resources/Invoices/**` |
| G3 | **Reservation expiry is unreachable.** `InventoryReservationService::expire()`'s only caller is a test; no scheduled command. `ReservationStatus::Expired` never occurs in production. | Stale reservations hold stock indefinitely. | `tests/Feature/Inventory/InventoryReservationServiceTest.php:87`; `routes/console.php` |
| G4 | **Manual reservation release has no UI.** Service method + `inventory.reservation.release` permission exist; the permission has **0 references** in `app/`; `InventoryReservationResource` has no actions. | Operators cannot free a reservation. | `app/Enums/InventoryPermission.php:32`; `InventoryReservationResource.php` |
| G5 | **Customer 360 view.** `CustomerProfile` declares only `user()` and `deliveryAddresses()`; no relation to orders, invoices, quotations, payments, tickets or visits, and `ViewCustomer` has no relation managers — despite every one of those tables carrying `customer_id`. | No consolidated customer history anywhere in the product. | `app/Models/CustomerProfile.php:73-84` |
| G6 | **Accounts Receivable is shallower than Accounts Payable.** AP has `aging()`, `toCsv()`, `supplierDetail()`; AR is a single computed list page with none of these. | Collections work has no aging or statement. | `app/Services/Accounting/AccountsPayableService.php` vs `Resources/AccountsReceivable/**` |
| G7 | **No notification layer.** `app/Notifications/` does not exist. Only `InvoiceMail` + `NotifyAdminOfSalaryRecalculation`. No payment reminders, overdue notices or task notifications (FR-023). | The "Reports **and Notifications**" core feature is half-built. | `app/Mail/`, `app/Jobs/` |
| G8 | **No sales reports surface.** Inventory, Employees, Support, Purchasing and Accounting each have a reports resource; Sales has only dashboard widgets (FR-023 lists sales/invoice/payment/tax reports). | No sales reporting outside widgets. | `app/Filament/Resources/*Reports/` |
| G9 | **Corrections cover only receipts.** `InventoryCorrectionType` has one case, `Receipt`. Deliveries and transfers have no correction path. | Non-destructive correction (PRD §9) is incomplete for outbound documents. | `app/Enums/InventoryCorrectionType.php` |
| G10 | **No preventive/scheduled maintenance.** `MaintenanceTask` is a checklist, not a schedule — no recurrence, no due-date reminder. | Maintenance is reactive only. | `app/Models/MaintenanceTask.php` |
| G11 | **Operation completion is not audit-logged.** The most consequential act in the system logs only `inventory.operation.canceled`; arrival/departure is traceable via `inventory_movements`, not the audit trail (FR-024). | Audit trail has a hole at the stock boundary. | 79 log names, none for completion |

### Defects (present but wrong or inconsistent)

| # | Defect | Evidence |
|---|---|---|
| D1 | **Three empty stub enums with zero references**: `InvoiceStatus`, `PaymentStatus`, `InvoiceConfirmationType` — each literally `enum X { // }`. The lifecycles they name run on raw strings. | `app/Enums/InvoiceStatus.php`, `PaymentStatus.php`, `InvoiceConfirmationType.php` |
| D2 | **Six financial-document lifecycles use raw-string statuses** compared as inline literals (`$document->status !== 'approved'`, `whereNotIn('status', ['cancelled'])`) with no enum, cast, or transition matrix — while 23 other lifecycles are enum-guarded. Illegal transitions are blocked only where a guard clause happens to exist. | `AccountingDocumentService.php:106-410`, `RefundService.php:36-232`, `InvoiceConfirmationService.php:50` |
| D3 | **Four navigation entries reference non-existent classes**: `TaxDefinitionResource`, `DocumentTemplateResource`, `OperationalReportResource`, `Pages\Settings`. Safe at runtime (`resolveLink()` uses `class_exists()`, falls through to `ModulePlaceholder`) but the `use` statements read as a broken build. | `app/Filament/AdminModuleRegistry.php:28,46,78,255,274-277` |
| D4 | **Four declared permissions are never checked**: `InventoryPermission::ReservationRelease`, and `AuditView` on `SalesPermission`, `AccountingPermission`, `EmployeePermission`. | enum scan vs `app/**` references |
| D5 | **Two dead resource directories**: `Resources/TaxRecognitionEntries/Pages/` (empty) and `Resources/InventoryExports/` (a `Schemas/` folder, no resource class). Rename leftovers. | `app/Filament/Resources/` |
| D6 | **`dedoc/scramble` is a production dependency with nothing to document** — it generates OpenAPI specs, and there is no API. | `composer.json` |
| D7 | **`Docs/api/API_CONTRACT.md` (276 lines) describes endpoints that do not exist**, and PRD §6.2/§6.3 describe customer and employee flows with no channel. The ADRs supersede both, but neither document says so. | `Docs/api/API_CONTRACT.md`, `Docs/PRD.md` |

---

## 6. Test coverage distribution

| Suite | Files |
|---|--:|
| `tests/Feature/Inventory/` | 56 |
| `tests/Feature/Employees/` | 55 |
| `tests/Feature/Filament/` | 49 |
| `tests/Feature/` (root — inventory/pricing/CRM legacy layout) | 45 |
| `tests/Unit/` | 38 |
| `tests/Feature/Accounting/` | 20 |
| `tests/Feature/Purchasing/` | 19 |
| `tests/Feature/Sales/` | 18 |
| `tests/Feature/Support/` | 16 |
| **Total** | **316** |

### Architecture-enforcing tests worth knowing about

| Test | Invariant held |
|---|---|
| `Unit/ArchTest.php` | Folder-level domain boundaries, `declare(strict_types=1)`, final classes |
| `Feature/InventoryDomainContractTest.php` | Only `InventoryPostingService` may write stock |
| `Feature/Accounting/NoAutomaticPostingTest.php` | Only the ADR-approved callers post to the ledger |
| `Feature/Accounting/PostedEntryImmutabilityTest.php` | A posted journal entry cannot be mutated |
| `Unit/AdminModuleRegistryTest.php` | Navigation registry resolves or degrades safely |
| `Feature/Filament/PanelAccessTest.php` + `Feature/Employees/DashboardFixedRoleMatrixTest.php` | The 15 fixed dashboard roles see only their own module |
| `CrossModulePermissionLeakTest` (×5: Employees, Inventory, Purchasing, Sales, Support) | No module's permissions grant access to another's |
| `Feature/Inventory/InventoryBalanceConcurrencyTest.php`, `InventoryOperationConcurrencyTest.php`, `Feature/Purchasing/PurchaseOrderOverReceiptTest.php` | Row-lock correctness under concurrent writes |
| `Feature/Inventory/LegacyInventoryRemovalTest.php`, `WarehouseLocationTrackingTest.php`, `Feature/ProductSubscriptionRemovalTest.php`, `Feature/Employees/VisitEditRemovedTest.php` | Removed features stay removed |
| `Feature/Filament/CoverageSurfaceTest.php`, `Unit/Coverage/PrimitiveCoverageTest.php`, `Unit/PageUsageGuideTest.php` | Every resource/page/enum case stays reachable and documented |
| `Feature/Sales/QuotationTouchesNoStockTest.php` | A quotation never moves stock |
| `Feature/Inventory/PackageBalanceInvarianceTest.php`, `PackageDeletionGuardTest.php` | Package balances stay consistent |

### Coverage thin spots

| Area | Note |
|---|---|
| Invoice / payment status transitions | No test pins the legal transition set — there is no enum to test |
| Bill / expense / refund transitions | Behaviourally covered (`PayablesLifecycleTest`); illegal transitions unpinned |
| Reservation expiry | The only `expire()` caller *is* the test — coverage without a production path |
| Accounts Receivable | One resource test; no aging or statement logic to cover |
| Notifications | No notification layer to test |

---

## 7. Recommended remediation order

Sequenced by risk removed per unit of change, not by size.

1. **D1 + D2** — introduce `InvoiceStatus`, `PaymentStatus`, `InvoiceConfirmationType`,
   `BillStatus`, `ExpenseStatus`, `SupplierPaymentStatus`, `RefundStatus` as backed enums with
   `canTransitionTo()`, cast them on the models, and replace the string literals. This is the
   single highest-value change: it puts the *financial* documents under the same guard discipline
   that already protects inventory, purchasing and support, and closes the coverage thin spot in
   one move. The three stub files already exist and are unreferenced, so nothing breaks.
2. **G3 + G4 + D4** — either wire reservation expiry to a scheduled command and add the release
   action behind `ReservationRelease`, or delete `expire()`, `release()`, the permission and the
   `Expired` case. Right now the code claims a capability it cannot perform.
3. **G11** — log `inventory.operation.completed` alongside the existing `canceled` event.
   One line; closes the audit hole at the stock boundary (FR-024).
4. **D3 + D5** — decide per entry: build `TaxDefinitions` / `DocumentTemplates` /
   `OperationalReports` / `Settings`, or remove the navigation entries and dangling `use`
   statements. Delete the two dead resource directories.
5. **G6** — bring AR to parity with AP (`aging()`, `toCsv()`, `supplierDetail()` equivalents).
   The AP service is a working template.
6. **G2 + G8** — invoice CSV/Excel export and a sales reports resource. Both have five existing
   patterns to copy (`FinancialReportService`, `PurchasingReportService`, `EmployeeReportService`,
   `SupportReportService`, `InventoryReportService`).
7. **G9** — extend `InventoryCorrectionType` to deliveries and transfers, or state in the spec
   why outbound documents are corrected differently.
8. **G5** — add relation managers to `ViewCustomer` (orders, invoices, payments, tickets, visits).
   Cheap, and it is the gap an ERP user notices first.
9. **G7** — a notification layer for payment reminders, overdue-invoice notices and task
   assignment (FR-023). Larger; needs its own spec.
10. **G1 + G10** — CRM marketing (leads/interactions/campaigns) and preventive maintenance are
    new features, not fixes. Each needs a spec and an ADR before code.
11. **D6 + D7** — align the documentation with reality: mark `Docs/api/API_CONTRACT.md` as
    aspirational or delete it, annotate PRD §6.2/§6.3 with their ADR deferrals, reconcile
    `Docs/database/ERD.md` with the removed tables, and drop `dedoc/scramble` until an API exists.

---

## 8. Scope of this analysis

Static reading of the tree at `b29a49a`: 100 models, 159 migrations, 84 enums, 145 services,
1 event, 2 listeners, 9 jobs, 9 observers, 63 policies, 65 Filament resources, 10 pages,
20 widgets, 7 routes, 18 seeders, 316 test files, plus `Docs/**` and `specs/001…022`.

No code was modified. **The test suite was not run**, so every ● in the *Tests* column means
"coverage exists and targets this behaviour", not "currently green". Run `composer test`
(parallel — see project convention) to confirm.
