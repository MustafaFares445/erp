# Phase 4 Plan — Optimization, Hardening, and Deferred-Scope Activation

**Generated:** 2026-09-07, from `feat/cross-module-remediation`
**Purpose:** a focused, standalone reference for Phase 4 so it can be read and acted on without
working through the full `ERP_REMEDIATION_PLAN.md` (224KB) and `ERP_IMPLEMENTATION_PHASES.md`
(26KB). This file curates Phase 4's existing design from those two documents and adds pointers
into the current codebase per work package.

**This is an extract, not a new design.** It does not change or override anything. Where this file
and a companion document appear to disagree, the companion document is authoritative:

- `ERP_REMEDIATION_PLAN.md:3825-4033` — the per-package design (trigger, work, tests, scope
  sketches) this file is drawn from.
- `ERP_IMPLEMENTATION_PHASES.md` §4, §6, §7, §8 — sequencing, effort, decision points, governance.
- `docs/adr/0001`–`0011` — existing ADRs; WP-4.5–4.8 are each currently only a deferral pointer
  inside ADR 0003, 0006, 0007, and 0008 respectively. No ADR exists yet for any of the four Phase
  4B decisions themselves — the next new ADR would be `0012`.

---

## 0. Scope: two distinct kinds of work

| | 4A — hardening (WP-4.1–4.4) | 4B — deferred decisions (WP-4.5–4.8) |
|---|---|---|
| **Nature** | Engineering work, no new business decision behind it | Four gaps deferred by an explicit ADR — owner decisions, not engineering decisions |
| **Question each answers** | "How do we make what Phases 1–3 built fast, clean, and sustainable?" | "Is the recorded business consequence still acceptable?" — not "how" |
| **Admission test** (phases doc §0.1) | Tightening what Phases 1–3 built | The four ADR-deferred gaps |

Every Phase 4B package can be pulled into an earlier phase the day the answer changes — WP-4.6 in
particular has a live argument for that (see §2).

---

## 1. Phase 4A — Hardening (WP-4.1–4.4)

### WP-4.1 — Performance of the new report and reconciliation surfaces

**Trigger:** Phases 2 and 3 add roughly twenty report queries, a full-ledger reconciliation on a
daily schedule, and a paginated timeline union. None has been measured against production-scale
data, and several are called from inside the period-close gate, where slowness becomes a blocker.

**Work:**
- Measure first — a seeder producing a realistic three-year dataset (≈50k invoices, ≈500k
  movements, ≈5k customers) plus a benchmark test asserting a wall-clock budget per report. The
  budget is the deliverable; the optimisation is whatever meets it.
- Query-count budgets on the timeline, the availability explainer, and every report page, following
  the `PricingTierQueryCountTest` precedent already in the suite.
- Index review of every index this plan added, against actual query plans rather than intent.
- `InventoryLotReconciliationService` — chunked replay and, if the budget demands it, an
  incremental mode that replays only movements since the last passing run, with a weekly full
  replay retained as the backstop.
- Materialised daily snapshots for AR ageing and the tax register **only if measurement shows they
  are needed** — derived-and-cached with the live derivation retained as the source of truth
  (AC-05's "derived, never stored" is a correctness rule, not a performance rule).

**Tests:** `tests/Feature/Performance/ReportBudgetTest.php` — wall-clock and query-count budgets
per report, skipped unless the large dataset is seeded so CI stays fast.

**Current implementation:**
- Reconciliation today is detect-and-record, not auto-correct:
  [`app/Services/Inventory/InventoryLotReconciliationService.php`](app/Services/Inventory/InventoryLotReconciliationService.php)
  (`inspect()` / `inspectDetailed()`), persisted by
  [`app/Services/Reconciliation/ReconciliationRunRecorder.php`](app/Services/Reconciliation/ReconciliationRunRecorder.php)
  into [`app/Models/ReconciliationRun.php`](app/Models/ReconciliationRun.php).
- Report services to benchmark: `app/Services/Sales/SalesReportService.php`,
  `app/Services/Accounting/FinancialReportService.php`, `app/Services/Accounting/TaxRegisterService.php`,
  `app/Services/Accounting/AccountsReceivableService.php`, `app/Services/Accounting/AccountsPayableService.php`,
  `app/Services/Inventory/InventoryReportService.php`, `app/Services/Purchasing/PurchasingReportService.php`,
  `app/Services/Crm/CrmFunnelReportService.php`, `app/Services/Crm/CustomerTimelineService.php`,
  `app/Services/Employees/EmployeeReportService.php`, `app/Services/Support/SupportReportService.php`.

---

### WP-4.2 — Schema deprecation cleanup

**Work:** remove `invoices.inventory_operation_id` once WP-2.13's join table has been in production
for one release and no reader remains (verified by grep and by a logged deprecation shim, not by
assumption). Remove the `AdjustmentStatus`-adjacent dead code paths superseded by WP-3.5. Drop the
`ModulePlaceholder` fall-through from `AdminModuleRegistry` if WP-3.7's inverted test has held.

**Each removal is its own commit with its own test run** — a deprecation removal that breaks
something breaks it silently.

**Current implementation:**
- `invoices.inventory_operation_id`: added in migration
  `database/migrations/2026_08_24_143226_add_sales_lifecycle_columns_to_invoices_table.php` as a
  **unique** (one-to-one) FK; referenced in [`app/Models/Invoice.php`](app/Models/Invoice.php)
  (fillable + `inventoryOperation(): BelongsTo`). Superseded by the many-to-many
  [`app/Models/InvoiceDeliveryLink.php`](app/Models/InvoiceDeliveryLink.php) introduced in
  migration `2026_09_05_150000_allow_consolidated_invoicing.php` — the rigid unique FK cannot
  represent consolidated invoicing (one invoice from multiple deliveries), which is why it's now
  obsolete.
- `ModulePlaceholder`: [`app/Filament/Pages/ModulePlaceholder.php`](app/Filament/Pages/ModulePlaceholder.php),
  wired into [`app/Filament/AdminModuleRegistry.php`](app/Filament/AdminModuleRegistry.php) via
  `resolveLink()`, `firstUrlFor()`, `navigationItems()`, and `activeGroupKey()`. Removing it
  requires every registry item to resolve to a real, always-accessible Resource/Page class first.

---

### WP-4.3 — Asynchronous exports with retained parameters

**Trigger:** XC-04 requires that long exports run asynchronously and are retained with their
parameters so a figure can be reproduced. `InventoryExport` already does this for inventory; WP-2.8
generalised the concern to sales documents. This package completes the pattern across every report
surface and moves any synchronous export above a row threshold onto the queue.

**Work:** a single `DocumentExport` model and queued job serving every module, with the requester,
parameters, row count, generated file, and expiry retained; a download page listing the
requester's exports; scheduled cleanup of expired files.

**Tests:** an export requested, queued, generated, and downloaded; parameters reproduce the same
figures on a re-run; another user cannot download it.

**Current implementation — two unmerged patterns exist today, which is exactly what this package fixes:**
- **Inventory (queued, but no expiry):**
  [`app/Models/InventoryExport.php`](app/Models/InventoryExport.php),
  [`app/Jobs/GenerateInventoryExport.php`](app/Jobs/GenerateInventoryExport.php),
  [`app/Services/Inventory/InventoryExportService.php`](app/Services/Inventory/InventoryExportService.php),
  trait [`app/Filament/Concerns/RequestsInventoryExports.php`](app/Filament/Concerns/RequestsInventoryExports.php).
  Persists `type`, `filters`, `file_path`, `status`, `failure_reason` — but **no `expires_at` and
  no cleanup job/command**; completed files live on disk indefinitely.
- **Sales (synchronous, no export record):** trait
  [`app/Filament/Concerns/ExportsSalesDocuments.php`](app/Filament/Concerns/ExportsSalesDocuments.php),
  used by `ListInvoices`, `ListPayments`, `ListQuotations`, `ListCreditNotes`, `ListOrders`. Streams
  CSV directly via `response()->streamDownload()`; the trait's own doc comment explicitly says the
  queued `InventoryExportService` machinery "is not needed here" — so today there is **no shared
  export-request model** between the two modules.

---

### WP-4.4 — Notification volume management

**Trigger:** XC-03's "notification volume is managed so alerts stay meaningful." Phase 2 delivers
the engine and five scheduled reminders; at scale these become noise, and a noisy alerting layer is
functionally the same as no alerting layer.

**Work:** per-user digest preferences (immediate / daily / weekly) with a digest builder;
per-template rate limits; a quiet-hours window; a `notifications:digest` command; and a
delivery-volume report so the noise is measurable rather than anecdotal.

**Tests:** digest batching, rate-limit suppression recorded as a decision (not a silent drop),
quiet hours deferring rather than dropping.

**Current implementation:**
- Per-user preference **already exists, partially**:
  [`app/Models/NotificationPreference.php`](app/Models/NotificationPreference.php)
  (`user_id`, `template_key`, `channel`, `enabled`) with Filament CRUD, checked in
  [`app/Services/Notifications/NotificationDispatcher.php`](app/Services/Notifications/NotificationDispatcher.php)
  (`preferenceDisables()`) — but it is a **boolean opt-out only**. There is a separate global
  `communication_suppressions` table (`routeSuppressed()`), unrelated to per-user preference.
- **No digest, rate-limiting, or quiet-hours logic exists anywhere** in the dispatcher today —
  dispatch is immediate/queued per event with a flat 3-retry cap. WP-4.4 builds on top of the
  existing preference model rather than starting from nothing.

---

## 2. Phase 4B — Deferred decisions (WP-4.5–4.8)

These are not "how" questions — each is specified at the same depth as everything else so the
owner can price the decision rather than re-open a discovery.

### WP-4.5 — API and channel foundation (GAP-MW-19) — deferred by ADR 0003 / ADR 0008

| | |
|---|---|
| **Business consequence if left as is** | All customer self-service and all field capture is re-keyed by office staff. **GPS check-in data (EM-04) cannot be captured at all**, so the work-time adherence factor that drives salary (EM-09, EM-10) is fed by office-entered timestamps. F-19 and F-20 do not run |
| **Scenario** | SL-13, SL-14, EM-03, EM-04, EM-05, SU-01, PM-09, CR-01 |

**Why this is cheaper than it looks:** the domain is genuinely channel-ready — it was built that
way deliberately. `QuotationService::recordDecision` preserves both the decider and the recorder;
`ShipmentService::confirmByCustomer()` exists; `ShipmentConfirmationSource` already distinguishes
`Customer | AdminUser | System`. Every one of these is currently invoked by an admin recording
someone else's action. The remaining work is transport, authentication, and authorisation — not
domain.

**Scope:** `routes/api.php` + Sanctum, versioned under `/api/v1`; `App\Http\Resources\` per read
shape already named in the plan; `App\Http\Requests\Api\` mapping to existing data objects — **no
controller calls a model directly**, every write goes through the same service Filament calls
(enforced by an `ArchTest` rule); policies reused unchanged. Customer surface: catalogue, quotation
request, order tracking, document download, ticket creation, statement. Employee surface: plan and
tasks, visit check-in/out **with GPS**, voice note upload, lead/interaction capture, opportunity
creation, van-warehouse sale.

**Tests:** a contract test per endpoint; an authorisation matrix test; and
`tests/Feature/Api/ChannelParityTest.php` asserting the same business action through the API and
through Filament produces byte-identical domain records.

---

### WP-4.6 — Inventory valuation and cost of sales (GAP-MW-15) — deferred by ADR 0007 §11 / ADR 0008

| | |
|---|---|
| **Business consequence if left as is** | **The P&L reports revenue with no cost of sales.** Gross margin is not derivable at any grain — per product, per customer, per job. The balance sheet omits inventory as an asset while the warehouse holds it. Disposals and damage write-offs produce a stock movement and no expense, so shrinkage never reaches the P&L |
| **Scenario** | AC-14, MT-05, SL-15 |

**This is the single largest divergence between what the ledger says and what the business is** —
the one item with a live argument for pulling forward. Every other deferred gap costs the business
a capability; this one costs it the truth of its own financial statements. WP-2.9 delivers
job-level margin from cost snapshots, which narrows the operational pain but does not touch the
ledger.

**Scope sketch:** weighted-average valuation (recommended — `SupplierCostWritebackService` already
maintains a last-received unit cost, and moving-average is the smallest honest step from it; FIFO
would need lot-level cost layers the schema doesn't carry); an average-cost column/table maintained
by `InventoryPostingService` on every receipt; receipt posts inventory asset (Dr inventory, Cr
GRNI), bill approval clears GRNI; delivery completion posts COGS (Dr COGS, Cr inventory) — **which
changes the invariant `NoAutomaticPostingTest` guards**, a deliberate, explicit rewrite signalling
this is a scope decision, not a defect fix; damage/disposal/count variance post to a shrinkage
expense account; a new `PeriodCloseCheck::InventoryAgreesToControlAccount` added to WP-2.5's
checklist.

**Prerequisite:** WP-2.5 (period-close gate) must exist first — introducing inventory postings
without a reconciliation gate would add a new way for the ledger to be wrong with no signal that it is.

**Current implementation:** ledger write path is
[`app/Services/Accounting/JournalPostingService.php`](app/Services/Accounting/JournalPostingService.php)
(`draft()` / `post()` / `postNew()`, always takes an explicit `User $actor` + optional `$source`);
posting-caller precedent is
[`app/Services/Sales/InvoicePostingService.php`](app/Services/Sales/InvoicePostingService.php)`::post()`.
No costing/valuation engine exists yet — `SupplierCostWritebackService` only writes purchase cost
back to product/variant records, which is not inventory valuation.

---

### WP-4.7 — Ticket revenue through the ledger (GAP-MW-11) — deferred by ADR 0008 / spec 016 D4

| | |
|---|---|
| **Business consequence if left as is** | Cash is collected from a customer and marked settled while the general ledger, the receivables subledger, and the **tax register** never see it. **Tax is charged and collected without being recognised as payable — a filing exposure, not a reporting gap.** The amount is invisible to AC-06 and to the AC-10 close |

**Scope sketch:** ticket settlement creates a standard `Invoice` (or allocates to one) through
`InvoiceService`, then follows the standard collection and proportional tax path — the same
"no second revenue path" rule WP-2.9 applies to maintenance billing. WP-2.9's
`MaintenanceBillingService` is the template, making this the cheapest of the four deferred items to
activate.

**Note on sequencing:** this is a **tax compliance** exposure, not merely a reporting gap. If the
business collects ticket settlements at any material volume, **this belongs in Phase 2 next to
WP-2.9, not in Phase 4.** The volume figure is the deciding input and the business owns it.

**Current implementation:**
[`app/Services/Support/TicketPaymentService.php`](app/Services/Support/TicketPaymentService.php)`::settle()`
docblock explicitly states no accounting, journal, or tax side effect exists anywhere in the class
— settlement touches only `ticket_payment_links` and `tickets`.
[`app/Models/TicketPaymentLink.php`](app/Models/TicketPaymentLink.php) (`PaymentLinkStatus` enum:
Pending / Settled / Cancelled).

---

### WP-4.8 — Supplier debit notes (GAP-MW-14) — deferred by ADR 0006 §11

| | |
|---|---|
| **Business consequence if left as is** | Stock leaves the building and the payable stays at its full value. The supplier's bill is paid in full for goods that were returned, or the recovery is tracked in email. F-08 completes its inventory half and stops at the Purchasing → Accounting seam |

The physical leg is built and well guarded — `InventoryReturnService::createSupplierReturn()` caps
the return against the referenced receipt line. The commercial leg does not exist: no debit note,
no supplier credit document, no expected-outcome field.

**Scope sketch:** an `expected_outcome` enum on the supplier return (`Replacement | Credit |
Refund`); a `SupplierDebitNote` document mirroring `CreditNote` in shape and lifecycle, linked to
the return at line grain exactly as WP-1.3 links customer returns to credit notes; posting that
reduces the payable and reverses input tax proportionally; an exception report for supplier returns
awaiting a credit that never arrived.

WP-1.3 is the direct template — the same problem, the same shape, the other direction — making
this the second-cheapest deferred item once WP-1.3 has shipped.

**Current implementation:** [`app/Models/InventoryReturn.php`](app/Models/InventoryReturn.php)
already carries `return_type`, `supplier_id`, `financial_reference_type`/`financial_reference_id`,
and `credit_note_required` columns — partial plumbing toward linking a supplier-direction return to
a financial document — but no `SupplierDebitNote` model/resource exists yet. Direct template:
customer-side [`app/Models/CreditNote.php`](app/Models/CreditNote.php) +
`CreditNotePostingService.php` from WP-1.3.

---

## 3. Effort and sequencing

| Phase | Packages | Engineer-days | Elapsed (3 tracks) |
|---|---|---|---|
| **Phase 4A** (optimization) | 4 | 30–40 | 4–6 weeks |
| **Phase 4B** (ADR-deferred, if funded) | 4 | 150–210 | not scheduled |

Two caveats specific to Phase 4:

1. **Phase 4B is a range across four independent decisions, not one project.** WP-4.7 (ticket
   revenue) is roughly 10–15 days because WP-2.9 builds its template. WP-4.6 (COGS) is the bulk of
   the range at 60–90 days and touches the ledger's most load-bearing invariant.
2. Review and rework are inside the estimates; production incident response is not.

---

## 4. Decision points for the owner

Four decisions belong to the business, not to engineering:

| # | Decision | Deciding input | Consequence of "no" |
|---|---|---|---|
| **D-1** | Fund **WP-4.6** (inventory valuation and COGS)? | Does the business need gross margin, or is revenue-only reporting acceptable to its owners, lenders, and auditors? | The P&L reports revenue with no cost of sales, permanently. No margin figure exists at any grain. The balance sheet omits inventory as an asset |
| **D-2** | Fund **WP-4.5** (customer and employee channels)? | Volume of re-keyed customer and field activity; whether the salary model's work-time adherence factor may keep being fed by office-entered timestamps | All self-service and field capture stays re-keyed. GPS check-in cannot be captured at all |
| **D-3** | Pull **WP-4.7** (ticket revenue) into Phase 2? | **Annual value of ticket settlements** — a tax-recognition exposure, not a reporting gap | Tax is charged and collected without being recognised as payable. If volume is material, "no" is not a reporting decision — it is a filing risk |
| **D-4** | Defer **WP-3.5** (physical count)? | Is the business running controlled cycle counts today, or counting on spreadsheets? | Counts stay a hand-built adjustment list, and uncounted variants stay silently assumed correct |

**D-3 is recommended to answer first** — it is the cheapest of the four to build, the template
already exists after WP-2.9, and it is the only one whose "no" carries a compliance consequence
rather than a capability cost.

---

## 5. Governance specific to Phase 4

1. **A new ADR (0012 or later) is written for each Phase 4B decision, whether the answer is yes or
   no.** A recorded "no, and here is why" is worth as much as a "yes" — it's what stops the same
   discussion recurring in six months.
2. **WP-4.2's removals are each their own commit with their own test run** (per
   `.ai/feature-development` rule 3: one logical change per commit). A deprecation removal that
   breaks something breaks it silently, so it must be isolated and reviewable on its own.
3. The PHPStan baseline may only shrink (rule 7) — every package removes the entries it obsoletes
   in the files it touches, and this applies as much to Phase 4 cleanup as to any other phase.

---

## 6. Entry criteria

Phase 4 does not start until **all Phase 1–3 exit criteria still hold** — `composer test` passes
with nothing skipped or weakened, the PHPStan baseline has not grown, and the three regenerated
analysis documents (`CURRENT_IMPLEMENTATION_MAP.md`, `BUSINESS_LOGIC_GAPS.md`,
`CROSS_MODULE_FLOW_MATRIX.md`) show no impaired seam outside the four ADR-deferred ones.

4A specifically cannot start meaningfully before its own trigger exists: the ~20 report queries,
the reconciliation schedule, and the customer timeline that WP-4.1 benchmarks are Phase 2/3
deliverables.

---

*Companion documents: `ERP_REMEDIATION_PLAN.md` (full per-package design),
`ERP_IMPLEMENTATION_PHASES.md` (sequencing, dependency graph, exit criteria, governance). This file
is a snapshot as of `feat/cross-module-remediation`; the companions are authoritative on any future
update.*
