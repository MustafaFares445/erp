# Business Logic Gaps — IERP

**Document type:** ERP gap analysis (expected business behaviour vs. built behaviour)
**Perspective:** ERP Solution Architect / Business Process Auditor
**Baseline branch:** `feat/cross-module-remediation` (`b29a49a`)
**Created:** 2026-09-03

---

## 0. How to read this document

This document compares two inputs:

| Input | Role |
|---|---|
| `Docs/EXPECTED_BUSINESS_SCENARIOS.md` | 128 scenarios (MD/PM/IN/CR/SL/PU/AC/EM/SU/MT/XC) describing *ideal* ERP behaviour |
| `CURRENT_IMPLEMENTATION_MAP.md` | Static map of what is actually built at `b29a49a` |
| `Docs/CROSS_MODULE_BUSINESS_FLOWS.md` | 21 end-to-end journeys (F-01…F-21), used to name the seam each gap sits on |

Every gap below was **re-verified directly against the source tree**, not taken on trust from the
implementation map. Where a claim rests on a file, the file and line are named.

### Gap ID scheme

| Prefix | Category | Meaning |
|---|---|---|
| `GAP-MW-nn` | **Missing workflow** | The business step does not exist in any form |
| `GAP-BW-nn` | **Broken workflow** | The step exists but does not connect, does not fire, or loses data across a seam |
| `GAP-WL-nn` | **Wrong business logic** | The step exists and runs, but enforces a rule that differs from the intended one |
| `GAP-UI-nn` | **Missing UI representation** | The behaviour exists in the domain but no user can reach it |

### Priority definitions

| Priority | Meaning |
|---|---|
| **Critical** | Money, stock, or a legal obligation can be wrong or stranded with no in-system remedy |
| **High** | A named business capability is unusable, unauditable, or unenforced; a documented control does not actually control |
| **Medium** | The outcome is achievable, but only by a workaround that costs traceability, effort, or explainability |
| **Low** | Hygiene; misleading to a maintainer, invisible to the business |

### Scope note on deliberate deferrals

Six gaps below are **explicitly out of scope** by an ADR (customer/employee channels, online payments,
COGS and inventory valuation, ticket revenue accounting, supplier debit notes, multi-currency). They are
still recorded because the *business* consequence is real and unfunded — but each is flagged
`Deferred by ADR` so it is never confused with an accidental defect. The priority reflects business
impact, not implementation urgency.

### Summary

| Category | Critical | High | Medium | Low | Total |
|---|---|---|---|---|---|
| Missing workflows | 2 | 13 | 4 | 0 | 19 |
| Broken workflows | 1 | 4 | 3 | 0 | 8 |
| Wrong business logic | 0 | 3 | 2 | 0 | 5 |
| Missing UI representation | 0 | 4 | 3 | 1 | 8 |
| **Total** | **3** | **24** | **12** | **1** | **40** |

---

# 1. Missing Workflows

### GAP-MW-01 — Lead, interaction, campaign and funnel management does not exist

**Scenario:** CR-01, CR-02, CR-03, CR-06, CR-07; flow F-17 (Campaign → lead → customer → first sale).

**Expected behaviour:** A lead is captured with a mandatory **source**, worked through a qualification
pipeline (new → contacted → qualified → converted/disqualified) where every stage move is justified by a
recorded interaction, converted into a customer carrying its interaction history, and attributed —
through campaign and source — all the way to **invoiced and collected revenue**.

**Current behaviour:** None of the five concepts exists. There is no model, table, service, Filament
resource, policy or test for leads, interactions, campaigns, campaign recipients or campaign responses
(name scan across `app/Models` and the migration set returns zero matches). The CRM module that was
built is customers plus pricing tiers only.

**Impact:** The company cannot answer "which acquisition channel works". Revenue can be reported, but
never attributed to what caused it, so marketing spend is unmeasurable. F-17 has no first step, and
CR-07's requirement that the funnel be "traceable to money, not stop at interested" is unreachable.
This is a named PRD core feature (FR-022) with no code behind it.

**Priority:** **Critical**

---

### GAP-MW-02 — An opportunity cannot exist unless an AI transcript created it

**Scenario:** CR-04 (Manage an opportunity toward a quotation); EM-07; flow F-04.

**Expected behaviour:** An opportunity records party, need, **estimated value**, **expected close date**,
**stage**, and origin; activities progress it; it produces a quotation and carries its summary forward;
it closes won on acceptance, or **closed lost with a reason from a controlled list**. An AI-detected
origin is one origin among several, and must stay visibly AI-originated.

**Current behaviour:** `sales_opportunities` (`database/migrations/2026_08_05_210000`) carries only
`voice_note_transcription_id` (**NOT NULL**, `constrained()->cascadeOnDelete()`), `ai_keyword_rule_id`,
`summary`, `status`, `reviewed_by`, `review_notes`. `SalesOpportunityStatus` is
`Draft → Approved | Rejected` — a *review* state machine, not a *sales* pipeline. There is no customer,
no estimated value, no expected close date, no stage, and no close-lost reason. Because the transcription
foreign key is mandatory, a salesperson **cannot record an opportunity at all** unless a field employee
recorded a voice note that an AI transcribed. `QuotationService::createFromOpportunity()` exists and
works well, but is reachable only from that single origin.

**Impact:** Pipeline value, pipeline ageing, win/loss analysis and forecasting are impossible. The
company has AI-suggested opportunities but no opportunity management. CR-04's rule that "closing lost
requires a reason, so the loss report means something" has no loss report to serve.

**Priority:** **High**

---

### GAP-MW-03 — Quarantined stock has no exit path and is permanently stranded

**Scenario:** IN-14 (Disposition quarantined stock); IN-01 step 3; IN-13 step 3.

**Expected behaviour:** A named person inspects quarantined stock and decides: release to saleable,
downgrade to damaged, dispose, or return to supplier. The decision, the inspector and the reason are
recorded, and the condition change posts through the canonical path. "Quarantine is a real condition
with a decision owner and an ageing report — never a parking space."

**Current behaviour:** `StockCondition::Quarantine` is a fully materialised balance
(`InventoryPostingService::materializedConditions()`, line 1293) and stock **can enter** it — via
`InventoryReturnDisposition::Quarantine` (`app/Enums/InventoryReturnDisposition.php:17`) and on receipt.
There is **no service that moves stock out of it**:

- `InventoryDamageService::damage/recover/dispose` maps only `Saleable ↔ Damaged → Disposed`
  (`app/Services/Inventory/InventoryDamageService.php:270-282`). Quarantine is not in the matrix.
- `InventoryAdjustmentService` reads and writes `StockCondition::Saleable` exclusively
  (lines 230, 316, 323, 326, 343).
- There is no operation type, no disposition action, no inspector field, and no quarantine ageing report.

Quarantine appears in stock tables and lot infolists as a **read-only number**.

**Impact:** Any goods sent to quarantine — a suspect receipt, a returned item pending inspection — are
removed from saleable availability **permanently**, with no in-system way to release, downgrade, dispose
or return them. The only remedy is a direct database edit, which bypasses the movement ledger and breaks
the IN-17 reconciliation invariants. A routine quality hold becomes permanent inventory loss.

**Priority:** **Critical**

---

### GAP-MW-04 — Reservation expiry never runs in production

**Scenario:** IN-03 alternate path ("Reservation expires → stock frees automatically and the sales
document is flagged as no longer covered"); flow F-15.

**Expected behaviour:** A reservation is consumed, released, or **expires**, exactly once. On expiry,
availability rises automatically and the sales document is flagged as no longer covered.

**Current behaviour:** `InventoryReservationService::expire()` exists
(`app/Services/Inventory/InventoryReservationService.php:156`) and is correct. Its **only caller in the
entire repository is a test** (`tests/Feature/Inventory/InventoryReservationServiceTest.php`); a grep for
`expire(` across `app/` returns the declaration and nothing else. `routes/console.php` schedules exactly
three commands — `inventory:alerts:reconcile`, `inventory:shipments:auto-arrive`, `support:sla:reconcile`
— none of which touches reservations.

**Impact:** `ReservationStatus::Expired` is unreachable in production. Stock held by an abandoned
quotation or a stalled order stays reserved indefinitely, so **available quantity understates reality
without bound**. Salespeople are told stock is unavailable while it sits on the shelf, and the low-stock
queue (IN-11) fires against phantom demand, triggering real purchasing spend.

**Priority:** **High**

---

### GAP-MW-05 — Manual reservation release is built, permissioned, and unreachable

**Scenario:** IN-03 alternate path ("Stale hold → an authorised operator releases it through the
sanctioned release action, audited, and availability rises immediately").

**Expected behaviour:** An authorised operator can release a stale hold through a sanctioned, audited
action.

**Current behaviour:** Three of the four legs exist and the fourth is missing.
`InventoryReservationService::release()` is implemented. `InventoryPermission::ReservationRelease`
(`inventory.reservation.release`) is declared at `app/Enums/InventoryPermission.php:32` and referenced
**zero times** anywhere else in `app/`. `InventoryReservationResource` has a single page
(`ListInventoryReservations`) with table columns and **no actions**.

**Impact:** The documented remedy for GAP-MW-04 does not exist either. When a reservation is stuck, there
is no supported way to free the stock — the operator's only options are to cancel the source document, if
it is still cancellable, or to edit the database.

**Priority:** **High**

---

### GAP-MW-06 — There is no physical count document, only a blind adjustment

**Scenario:** IN-06 (Correct stock after a physical count); flow F-16.

**Expected behaviour:** A count is opened for a **scope** — a whole warehouse, a category, a variant set,
or a lot. Counted quantities are recorded **at lot and serial identity**. The system computes variance
per grain and shows its value. A separate authorised person confirms.

**Current behaviour:** `InventoryAdjustment` (`Draft → Confirmed`) is a free-standing list of variant
lines carrying a target quantity. There is no count scope, no count-sheet generation, no
counted-versus-system worksheet, and no variance valuation. Variance is computed per line at confirmation
time rather than presented as a reviewable count result, and escalation on a materiality threshold
(IN-06 alternate) does not exist. See also GAP-WL-02 (no maker/checker) and GAP-WL-03 (Saleable only).

**Impact:** Cycle counting cannot be run as a controlled process. An operator counting a warehouse must
hand-build the line list from an external spreadsheet, which means uncounted variants are silently
assumed correct — the classic way a stock ledger drifts unnoticed.

**Priority:** **Medium**

---

### GAP-MW-07 — An uncollectable receivable cannot be written off

**Scenario:** AC-13 (Write off an uncollectable receivable).

**Expected behaviour:** An authorised write-off document reverses the receivable against a **bad-debt
expense account**, with a reason and an approver, handling the associated deferred tax per policy. The
invoice shows as **written off, never as paid**.

**Current behaviour:** No write-off document, service, status, account reference or UI exists. A
case-insensitive scan of `app/` for `write_off`, `writeoff`, `bad_debt` and `baddebt` returns nothing.

**Impact:** A receivable that will never be collected has only two in-system exits, and both are wrong.
Raising a **credit note** debits *revenue*, so the loss is booked as if the sale never happened,
understating both revenue and bad-debt expense. Leaving the invoice open forever permanently inflates the
AR ageing and the receivable control account. Either way the trial balance carries an asset the company
knows it does not have, and AC-05's reconciliation proof reconciles to a figure that is not true.

**Priority:** **High**

---

### GAP-MW-08 — No preventive maintenance programme

**Scenario:** MT-07 (Run a preventive maintenance programme).

**Expected behaviour:** Serviceable equipment carries a maintenance schedule; the system raises due
requests in advance; scheduling groups them by customer or geography; completion resets the interval and
appends to the equipment's service history; a missed preventive service is **visible as missed**.

**Current behaviour:** `MaintenanceTask` is a checklist under a maintenance record, not a schedule. There
is no recurrence field, no interval, no next-due date, no generation command, and no scheduler entry. All
maintenance is reactive — raised from a ticket or created standalone by hand.

**Impact:** Preventive service revenue, and the contractual obligations behind it, are managed outside the
system. A missed service is invisible until a customer complains, which is also the moment it becomes a
warranty argument the company cannot evidence (MT-08).

**Priority:** **Medium**

---

### GAP-MW-09 — A maintenance job has no cost, so warranty work is invisible

**Scenario:** MT-05 (Cost a maintenance job); flow F-07 (Warranty service at zero revenue).

**Expected behaviour:** A job accumulates **parts cost, labour time at a rate, and third-party cost**;
revenue is nil for warranty work, the ticket settlement, or an invoice; margin per job, per equipment and
per customer is reportable. "Warranty-covered work has a real cost and zero revenue, and that must be
*visible* — free-of-charge service is a cost centre, not a blank record."

**Current behaviour:** `service_record_parts` (`database/migrations/2026_08_14_000008`) stores
`maintenance_task_id`, `product_variant_id`, `warehouse_id`, `quantity`, `inventory_movement_id` and the
reversal fields — and **no cost column of any kind**. There is no labour time field, no labour rate, no
third-party cost, and no job-cost or service-margin report. `SupportReportService` offers `workload`,
`sla` and `maintenance`; none of them is a cost report.

**Impact:** F-07 produces exactly the outcome the scenario warns against — a warranty job is a blank
record. The company cannot price a service contract, cannot decide whether honouring a warranty beats
replacing the unit, and cannot see free-of-charge service consuming margin. Compounded by GAP-MW-15 (no
inventory valuation), even the parts leg of the cost is unknowable.

**Priority:** **High**

---

### GAP-MW-10 — Completed chargeable service work cannot be billed

**Scenario:** MT-06 (Bill chargeable maintenance); flow F-06.

**Expected behaviour:** Completed service work with its parts and labour produces a quotation or a direct
invoice, then follows the **standard** invoice → collection → proportional tax recognition path.
"A second revenue path would be a second tax policy."

**Current behaviour:** There is no link from `MaintenanceRecord` or `MaintenanceTask` to a quotation or an
invoice. `InvoiceService` offers `createFromDelivery()` (which requires a completed `InventoryOperation`)
and `createStandalone()`. Billing service work therefore means an accountant hand-keying a standalone
invoice with no reference to the job, its parts, or its serialised equipment.

**Impact:** Service revenue is disconnected from the work that earned it. Consumed parts are correctly
relieved from stock but never appear on a customer document, so the customer is billed from a manually
retyped figure. F-06 breaks at the Maintenance → Sales seam.

**Priority:** **High**

---

### GAP-MW-11 — Ticket settlement is money the ledger never hears about

**Scenario:** SU-02 (Gate work on a chargeable ticket); flow F-06. **Deferred by ADR 0008 / spec 016 D4.**

**Expected behaviour:** Ticket revenue is recognised through the **same** invoice, collection and tax path
as any other sale.

**Current behaviour:** `TicketPaymentService` writes `ticket_payment_links` and `tickets` and nothing
else — its own docblock (line 20) states that no accounting, journal or tax side effect exists anywhere in
the class. A settled ticket produces no `Payment`, no journal entry, and no `TaxRecognitionEntry`.

**Impact:** Cash is collected from a customer and marked settled while the general ledger, the receivables
subledger and the **tax register** never see it. Tax is charged and collected without being recognised as
payable — a filing exposure, not merely a reporting gap. The amount is invisible to AC-06 and to the
AC-10 close.

**Priority:** **High**

---

### GAP-MW-12 — There is no notification or reminder engine

**Scenario:** XC-03 (Notify the right party at the right moment); SL-07 (Chase an overdue receivable).

**Expected behaviour:** Notifications are produced from templates through the party's channel for invoice
issued, payment received, quotation decided, task assigned, visit due, ticket updated, SLA at risk, stock
low, lot expiring, and approval pending. Delivery is attempted asynchronously and **every attempt and
outcome is logged**; a failed delivery is retried, never silently dropped.

**Current behaviour:** `app/Notifications/` **does not exist**. The only outbound mail in the system is
`InvoiceMail`, sent by the `SendInvoiceEmail` job. The only other notification is
`NotifyAdminOfSalaryRecalculation`, an internal queued job. There is no delivery log, no retry policy, no
template layer, and no scheduled reminder command.

**Impact:** SL-07 is entirely manual. `Invoice::isOverdue()` correctly derives overdue status
(`app/Models/Invoice.php:79`), but nothing acts on it. Nobody is told an invoice is late, an SLA is at
risk, a lot is expiring, or an approval is waiting. Every alerting mechanism in the specification degrades
to "someone remembers to look at a screen", and there is no evidence of the collection conversation that
SL-07 requires as evidence.

**Priority:** **High**

---

### GAP-MW-13 — One invoice per delivery is a hard schema constraint

**Scenario:** SL-06 alternate path ("Consolidated invoicing → one invoice covering several completed
deliveries for the same customer, each delivery still invoiced only once").

**Expected behaviour:** A customer with several completed deliveries can be invoiced once for all of them,
while each delivery remains invoiced at most once.

**Current behaviour:** `invoices.inventory_operation_id` is a **nullable unique** foreign key
(`database/migrations/2026_08_24_143226:17`), making the relationship strictly one-to-one in both
directions. `InvoiceService::createFromDelivery()` enforces single-invoicing correctly (line 47), but
there is no many-deliveries-to-one-invoice path.

**Impact:** A customer taking twenty deliveries in a month receives twenty invoices — or an accountant
raises a `createStandalone()` invoice that references **no** delivery, which silently defeats the
"a delivery is invoiced at most once" control because the standalone invoice is invisible to it. The
workaround breaks the very invariant the constraint was built to protect.

**Priority:** **Medium**

---

### GAP-MW-14 — Goods go back to a supplier with no commercial document

**Scenario:** IN-15 (Send goods back to a supplier); flow F-08. **Deferred by ADR 0006 §11.**

**Expected behaviour:** A supplier return posts outbound movements and carries an expected outcome —
replacement, credit, or refund. The financial consequence (a supplier credit or debit note) is a separate,
explicit accounting decision, but it **exists** and is linked.

**Current behaviour:** The physical leg is built and well guarded:
`InventoryReturnService::createSupplierReturn()` caps the return against the referenced receipt line
(`app/Services/Inventory/InventoryReturnService.php:365, 856`). The commercial leg does not exist — no
debit note, no supplier credit document, and no expected-outcome field.

**Impact:** Stock leaves the building and the payable stays at its full value. The supplier's bill is paid
in full for goods that were returned, or the recovery is tracked in email. F-08 completes its inventory
half and stops at the Purchasing → Accounting seam.

**Priority:** **Medium**

---

### GAP-MW-15 — No inventory valuation and no cost of goods sold

**Scenario:** AC-14; MT-05; SL-15; flows F-01 and F-07. **Deferred by ADR 0007 §11 / ADR 0008.**

**Expected behaviour:** Receipt values inventory as an asset at received cost; delivery relieves inventory
and recognises COGS against the revenue the invoice recognised; write-offs and disposals expense the loss;
the inventory account reconciles to the valued stock position. "Margin is only real when cost lands in the
same period as revenue."

**Current behaviour:** Delivery completion posts `MovementType::Sale` movements and **nothing to the
ledger** — verified by `Feature/Accounting/NoAutomaticPostingTest.php`, which guards this as an invariant.
There is no inventory asset account in the automated posting set, no COGS entry, and no valuation basis.
`SupplierCostWritebackService` maintains a last-received unit cost on the variant and supplier reference,
so a cost figure exists; it is simply never posted.

**Impact:** The profit and loss statement reports **revenue with no cost of sales**. Gross margin is not
derivable at any grain — per product, per customer, per job. The balance sheet omits inventory as an asset
while the warehouse holds it. Disposals and damage write-offs (IN-09) produce a stock movement and no
expense, so shrinkage never reaches the P&L. This is the single largest divergence between what the ledger
says and what the business is.

**Priority:** **High**

---

### GAP-MW-16 — The reconciliation proof exists, but nothing runs it and nobody sees it

**Scenario:** IN-17 (Prove the stock ledger reconciles); AC-10 step 1; flow F-14.

**Expected behaviour:** The system **proves and reports** that aggregate balance equals the sum of lot
balances, aggregate reserved equals the sum of active allocations, serialised custody agrees with the
aggregate, and the movement ledger replays to the current balance. A divergence is reported prominently as
an error and is never plugged.

**Current behaviour:** The engine is built and is exactly the right shape.
`InventoryLotReconciliationService::inspect()` is exposed by `ReconcileInventoryLotsCommand`, signature
`inventory:lots:reconcile`, described as verifying "canonical lot, condition, reservation, and
serialized-custody invariants without modifying inventory". But:

- The command is **not scheduled**. `routes/console.php` schedules `inventory:alerts:reconcile`,
  `inventory:shipments:auto-arrive` and `support:sla:reconcile` — not this one.
- There is **no UI**. `InventoryReportType` has 12 cases (catalog, stock levels, movements, devices,
  expiry lots, supplier comparison, price history, pricing tiers, customer assignments, floor overrides,
  import runs, import results) and **none is a reconciliation report**.

**Impact:** The invariant that makes the stock ledger trustworthy is checked only when a developer runs an
artisan command by hand. A divergence introduced today is discovered at audit, by which time the movements
needed to explain it are months deep. AC-10's rule that a period cannot close while a reconciliation shows
a difference has no signal to read (see GAP-MW-18).

**Priority:** **High**

---

### GAP-MW-17 — Sales has no reporting surface at all

**Scenario:** SL-15 (Report the sales engine); CR-08; XC-04.

**Expected behaviour:** Quotation volume and win rate by employee, customer and product; conversion
velocity through each lifecycle stage; **delivered-not-invoiced** exposure; **invoiced-not-collected**
ageing; recognised versus deferred tax; margin; and discount and floor-override incidence. The scenario
names the first two as first-class because "they are the two places money silently leaks in a
Quotation → Delivery → Invoice → Payment model."

**Current behaviour:** Every other module has a report resource — `EmployeeReports`, `FinancialReports`,
`InventoryReports`, `PurchasingReports`, `SupportReports`. **Sales has none.** `app/Services/Sales/`
contains no report service. The entire sales reporting surface is two dashboard widgets
(`SalesStatistics`, `SalesRevenueTrend`). A grep for `delivered_not_invoiced` / `uninvoiced` across `app/`
returns nothing.

**Impact:** The two named leak points are unmonitored. Goods can ship and never be invoiced, and an invoice
can age past collection, with no report that would surface either. Win rate, conversion velocity and
discount incidence — the numbers a sales organisation is managed by — are computed nowhere.

**Priority:** **High**

---

### GAP-MW-18 — A fiscal period closes without any reconciliation gate

**Scenario:** AC-10 (Close a period safely); flow F-14.

**Expected behaviour:** The accountant runs the reconciliations (AC-05, AC-06, PU-12, IN-17), **resolves
exceptions**, verifies the trial balance, then closes. "A period cannot be closed while a mandatory
reconciliation shows a difference."

**Current behaviour:** `FiscalPeriodService::close()` authorises the actor and calls `setClosed()`, which
flips `is_closed` and writes an activity log entry
(`app/Services/Accounting/FiscalPeriodService.php:107-134`). There is no trial-balance check, no AR/AP
reconciliation check, no stock reconciliation check, and no close checklist. Refusing postings *into* a
closed period is correctly enforced (`ClosedFiscalPeriod`); the decision to close is entirely unguarded.

**Impact:** A period can be closed over an unbalanced subledger, and reopening it is then the only route to
correction — which is exactly the audited exception AC-10 exists to make rare. The close becomes a
formality rather than a control, and F-14's month-end journey has no checklist behind it.

**Priority:** **High**

---

### GAP-MW-19 — Customers and employees have no channel of their own

**Scenario:** SL-13, SL-14, EM-03, EM-05, SU-01, PM-09, CR-01; flows F-19 and F-20.
**Deferred by ADR 0003 and ADR 0008.**

**Expected behaviour:** A customer logs in, browses at their own resolved prices, requests quotations and
orders, tracks documents, pays, and opens tickets. A field employee checks in with GPS, records visits and
voice notes, and sells from a van warehouse. Critically: "a customer's decision made in-app and a
customer's decision recorded by staff must produce the **same** business record, so the flow does not fork
by channel."

**Current behaviour:** There is no HTTP API. `routes/` contains only `web.php` and `console.php`;
`routes/api.php` does not exist; there is no Sanctum, no `JsonResource`, and no mobile client. Both actor
types exist as `UserType` cases with no surface. The domain is genuinely channel-ready —
`QuotationService::recordDecision` preserves both the decider and the recorder,
`ShipmentService::confirmByCustomer()` exists, `ShipmentConfirmationSource` distinguishes
`Customer|AdminUser|System`, and visit editing was deliberately removed so field evidence stays field
evidence. Every one of those is currently invoked by an admin recording someone else's action.

**Impact:** All customer self-service and all field capture is re-keyed by office staff. GPS check-in data
(EM-04) cannot be captured at all, so the work-time adherence factor that drives **salary** (EM-09, EM-10)
is fed by office-entered timestamps. F-19 and F-20 do not run.

**Priority:** **High** *(business impact; implementation deliberately deferred)*

---

# 2. Broken Workflows

### GAP-BW-01 — A customer return and its credit note are two documents that do not know about each other

**Scenario:** IN-13 step 5, SL-12 step 6, AC-04; flow F-03 (Product return and credit note).

**Expected behaviour:** The return and the credit note are "linked but distinct documents". A return posts
no financial entry by itself; a credit note is never a stock return. But the two are joined so a controller
can see that this credit corresponds to those goods, and so a "customer retained the goods" credit is
distinguishable from one where stock came back.

**Current behaviour:** Both documents are individually strong. `InventoryReturnService` validates the
return against the source delivery and refuses over-returns, mismatched lots and mismatched serials
(`app/Services/Inventory/InventoryReturnService.php:240, 696, 995, 1000, 1004`). `CreditNoteService` caps
each credited quantity at the invoice line's uncredited remainder and posts a correctly split reversal.
**There is no foreign key in either direction.** No `credit_note_id` on `inventory_returns` or its lines;
no `inventory_return_id` on `credit_notes` or their lines. The only cross-document links that exist on the
financial side are `refunds.credit_note_id` and `refunds.invoice_id`
(`database/migrations/2026_09_02_150100`).

**Impact:** Nothing prevents crediting the same returned goods twice — the return caps against the
*delivery*, the credit note caps against the *invoice*, and neither sees the other. Nothing prevents goods
coming back with no credit ever raised, or a credit raised for goods that never came back. IN-13's
requirement that a customer-retained credit be "representable, with the stock consequence explicitly nil"
cannot be distinguished from a clerical omission. F-03 has no traceable spine, and neither leg can prove
the other happened.

**Priority:** **Critical**

---

### GAP-BW-02 — Posted documents can only be corrected if they are receipts

**Scenario:** IN-16 (Correct a posted stock document).

**Expected behaviour:** "The posted document is never edited. The operator raises the right *linked*
correction — a compensating correction of the receipt, a customer return against the delivery, or a
transfer cancellation or shortage record — which references the original and posts its own movements."

**Current behaviour:** `InventoryCorrectionService` implements the pattern correctly
(`Draft → Posted | Cancelled`, writing `MovementType::Correction`), but `InventoryCorrectionType` has
exactly **one case: `Receipt`**. Deliveries and transfers have no correction document. Adjustments are
served by a separate mechanism, `InventoryAdjustmentService::createCorrection()`, which requires a
confirmed origin adjustment but is a parallel implementation of the same idea.

**Impact:** A wrongly posted delivery — wrong warehouse, wrong quantity, wrong serial — has no linked
correction path. The available workarounds are a customer return (which asserts goods physically came back
and validates against the delivery, so it fails for an internal keying error) or an adjustment (which
posts an unlinked `Adjustment` movement, breaking IN-16's "every post-commit change is a new document with
a reference to the original"). A transfer posted between the wrong warehouses has no remedy at all beyond
a second compensating transfer with no reference to the first.

**Priority:** **High**

---

### GAP-BW-03 — Price provenance is captured on the quotation and lost everywhere after it

**Scenario:** MD-12 ("The resolved price carries its **provenance** onto the document line, so an author
can see why a line costs what it does"); CR-08 (Explain a customer's price to their face); PM-04; flow
F-11.

**Expected behaviour:** For any priced line on any sales document, the system shows the resolved price,
the rule that produced it, the rules that lost and why, the floor position, and — where the floor was
breached — the approval that permitted it.

**Current behaviour:** `PriceResolver` is a strong implementation: it returns a `ResolvedPrice` carrying a
`ResolvedPriceSource` (`CustomerSpecificTier | ProductScopedTier | GeneralTier | Base`), never stacks, and
`assertAtOrAboveFloor()` blocks below-floor sales pending a logged override. That provenance is persisted
on **exactly one table**: `quotation_lines.resolved_price_source`, a `string(40)` nullable column
(`database/migrations/2026_08_23_162316:29`), written by `QuotationService` line 221 and displayed in the
quotation infolist and line repeater. `order_lines` and `invoice_lines` carry **no provenance column**, and
a repository-wide grep for `resolved_price_source` returns only the quotation-side references.

**Impact:** Provenance dies at the Sales → Sales conversion boundary. The moment a quotation becomes an
order — the document the customer actually challenges, and the one an auditor reviews — nobody can say
which rule produced the price. A direct order raised without a quotation (SL-03 alternate, an explicitly
supported path) has no provenance at any point in its life. CR-08's "every price is explainable" holds only
for offers that were never accepted. Separately, the column is an untyped string rather than a cast enum,
so a typo is storable — the same modelling weakness as GAP-WL-01.

**Priority:** **High**

---

### GAP-BW-04 — The stock reconciliation command exists but is never scheduled

**Scenario:** IN-17; AC-10. *(Companion to GAP-MW-16, recorded separately because the fix is different: one
is a missing report, this is a wiring omission.)*

**Expected behaviour:** Reconciliation invariants are proved continuously, and a divergence is surfaced
prominently as an error.

**Current behaviour:** `ReconcileInventoryLotsCommand` (`inventory:lots:reconcile`) is fully implemented
and checks the right four invariants. `routes/console.php` schedules three commands and this is not one of
them. The command is therefore dead code from a runtime perspective — reachable only from a shell.

**Impact:** The system has the ability to detect that its own stock ledger has diverged and does not use
it. Divergence is silent until someone thinks to run the command.

**Priority:** **High**

---

### GAP-BW-05 — The duplicate-payment control is skipped whenever the supplier reference is blank

**Scenario:** PU-09 ("The supplier's own invoice number is unique per supplier — **this is the primary
duplicate-payment control**").

**Expected behaviour:** A supplier's own invoice number is unique per supplier, so the same supplier
invoice can never be recorded — and therefore paid — twice.

**Current behaviour:** `AccountingDocumentService` implements the check
(`app/Services/Accounting/AccountingDocumentService.php:502-515`): it looks for another bill from the same
supplier with the same `supplier_reference` and throws if one exists. But line 502 reads
`if (blank($bill->supplier_reference)) { return; }` — **a bill with no supplier reference bypasses the
control entirely**. There is also no database unique constraint: `bills` has `bill_number` unique
(`database/migrations/2026_08_24_133509:18`) — the *internal* number — and `supplier_reference` carries no
index or constraint at all, so the guard is a service-layer read-then-write with no protection against a
concurrent duplicate.

**Impact:** The company's primary defence against paying the same supplier invoice twice has two holes: an
operator who leaves the reference blank defeats it outright, and two simultaneous entries defeat it by
race. Given that bill approval is what recognises the payable (AC-07) and supplier payment clears it
(AC-08), a duplicate flows straight through to a duplicate disbursement.

**Priority:** **High**

---

### GAP-BW-06 — The most consequential act in the system writes no audit entry

**Scenario:** XC-02 (Audit every sensitive action); IN-01, IN-04, IN-05.

**Expected behaviour:** "The system records who did what, to which record, when, from which channel, with
before and after values… An action the audit trail cannot explain is a defect." Audit records are
append-only and cover every financial and inventory change.

**Current behaviour:** Audit coverage across the codebase is genuinely good — 79 distinct activity-log
event names with a `withProperties` convention carrying `source_channel` and `ip_address`. But
`InventoryOperationService` contains exactly **one** `log()` call, at line 467:
`inventory.operation.canceled`. Marking ready, dispatching, completing and receiving a transfer — the acts
that actually move stock — write no activity entry. The `inventory_movements` ledger records the *effect*
with an actor id; the audit trail does not record the *decision*.

**Impact:** "Who confirmed this delivery, from which channel, at what time" is answerable only by joining
movement rows, and the channel and IP that every other audited action captures are absent. An operation
cancelled is auditable; an operation completed is not. For the one module where a mistake is physically
irreversible, the audit trail is thinner than for a pricing tier edit.

**Priority:** **Medium**

---

### GAP-BW-07 — A quotation only becomes expired when somebody tries to accept it

**Scenario:** SL-02 ("Expiry is enforced on decision *and* derived for display, so a stale offer never
looks live"); SL-16; flow F-18 (Quotation expiry and re-quote).

**Expected behaviour:** Expiry is enforced when a decision is attempted **and** derived for display, so a
stale offer never appears live.

**Current behaviour:** The enforcement half is correct: `QuotationService::recordDecision()` checks
`$quotation->isExpired()` and writes `QuotationStatus::Expired` before refusing the acceptance
(`app/Services/Sales/QuotationService.php:158-159`). The stored status of every other expired quotation
stays `Sent` indefinitely — there is no sweep command, no scheduled transition, and no listing-level
recomputation.

**Impact:** "Open quotations" over-counts, because it includes every offer whose validity lapsed without
anyone touching it. Pipeline and win-rate figures computed from stored status are wrong in the same
direction. F-18's re-quote journey is never triggered, because nothing ever announces that the original
lapsed.

**Priority:** **Medium**

---

### GAP-BW-08 — Deleting a transcription silently destroys the opportunity and the human decision on it

**Scenario:** EM-07 ("No AI output takes effect without an explicit, recorded human decision… A rejected
draft is retained as evidence with its reason, so keyword rules can be tuned"); EM-08; XC-02.

**Expected behaviour:** The draft, the rules that matched it, and the human decision with its identity and
note are retained as evidence. Rule and input changes never retroactively re-explain a historical draft.

**Current behaviour:** `sales_opportunities.voice_note_transcription_id` is
`constrained()->cascadeOnDelete()` (`database/migrations/2026_08_05_210000:15`). Deleting a transcription
hard-deletes every opportunity derived from it, including its `reviewed_by`, `reviewed_at` and
`review_notes`. By contrast `ai_keyword_rule_id` on the same table is correctly `nullOnDelete()`, so the
rule reference degrades gracefully — the design intent is visibly there and was applied inconsistently.

**Impact:** The audit evidence for an AI-originated business decision can be destroyed by a data-retention
cleanup on the audio pipeline. If that opportunity produced a quotation, the quotation survives and its
`sales_opportunity_id` becomes a dangling reference to a row that no longer exists — so the AI origin that
EM-07 requires to "remain visible on everything it produces" is gone precisely when someone asks where the
deal came from.

**Priority:** **Medium**

---

# 3. Wrong Business Logic

### GAP-WL-01 — Every accounting and billing document has an untyped lifecycle

**Scenario:** XC-01, XC-07, AC-16; the codebase's own prevailing convention.

**Expected behaviour:** A document's lifecycle is a modelled state machine with an explicit legal
transition set, so an illegal transition is impossible rather than merely unattempted. The codebase
establishes this convention comprehensively: `QuotationStatus`, `CreditNoteStatus`, `PurchaseOrderStatus`,
`TicketStatus`, `OperationStage`, `AdjustmentStatus`, `InventoryCorrectionStatus`, `InventoryReturnStatus`,
`ReservationStatus`, `SalesPlanStatus`, `PlanTaskStatus`, `VisitStatus`, `SalaryCalculationStatus` and
`JournalEntryStatus` are all backed enums, most with a `canTransitionTo()` matrix.

**Current behaviour:** `invoices`, `payments`, `bills`, `expenses`, `supplier_payments` and `refunds` —
**every document that carries money** — use `string(30)` status columns compared against inline literals:
`$document->status !== 'approved'`, `in_array($locked->status, ['issued','sent'], true)`,
`whereNotIn('status', ['cancelled'])`, `'status' => 'partially_paid'`. Three enum files exist to hold these
lifecycles and are empty stubs with zero references anywhere in `app/`, `database/` or `tests/`:

```php
enum InvoiceStatus { /* */ }          // app/Enums/InvoiceStatus.php
enum PaymentStatus { /* */ }          // app/Enums/PaymentStatus.php
enum InvoiceConfirmationType { /* */ }// app/Enums/InvoiceConfirmationType.php
```

`InvoiceConfirmationService` compensates by validating against a hard-coded array
`['customer_received', 'employee_confirmed_received']` at the call site.

**Impact:** The rules are enforced only where a guard clause happens to exist, and each guard is a separate
literal that can drift from its siblings. A new code path touching `invoices.status` inherits no
protection. An invalid status value is storable, and no test pins the legal transition set for any of these
six document families. The system's most valuable documents are the only ones without the safety net every
other module has — and the empty enum files advertise a type system that was never built.

**Priority:** **High**

---

### GAP-WL-02 — The same person can create and confirm a stock adjustment

**Scenario:** IN-06 ("**Creating and confirming an adjustment are separate decisions**"); §1.3
separation-of-duties principle 3 ("The party who counts stock is not necessarily the party who confirms the
correction").

**Expected behaviour:** "A separate authorised person confirms the correction." Adjustment creation and
adjustment confirmation are two decisions with two owners.

**Current behaviour:** The two-*step* workflow exists and is correct — `Draft → Confirmed`, with confirm
locking the adjustment, re-checking the draft status, validating the warehouse and posting through the
canonical path. But `InventoryAdjustmentService::confirm()`
(`app/Services/Inventory/InventoryAdjustmentService.php:85-160`) never compares `$actor` to the
adjustment's `created_by`. One user with the confirm permission can create an adjustment and immediately
confirm it.

Note the contrast within the same codebase: `RefundService::approve()` enforces exactly this control —
`'The user who recorded a refund cannot approve it.'` — and `PurchaseOrderApprovalService::approve()`
throws `SelfApprovalRejected`. The pattern is established; inventory adjustments do not use it.

**Impact:** Stock adjustment is the one operation that can make an inventory discrepancy disappear without
any counterparty. Without maker/checker, a single actor can write off shrinkage in one uninterrupted
action. IN-06's separation of duties is documented, is implemented for money, and is absent for goods.

**Priority:** **High**

---

### GAP-WL-03 — Counts and adjustments can only see saleable stock

**Scenario:** IN-06 ("A tracked variant cannot be adjusted without naming the lot or serial identity —
otherwise aggregate and lot balances silently diverge"); IN-17; IN-18.

**Expected behaviour:** A count covers what is physically in the warehouse. Aggregate balance must equal
the sum of its parts across every condition, and a correction must be able to reach any grain that can
diverge.

**Current behaviour:** `InventoryPostingService::materializedConditions()` maintains three condition
balances — `Saleable`, `Quarantine`, `Damaged` — and derives on-hand from all three (lines 778-790).
`InventoryAdjustmentService` reads and writes **only** `StockCondition::Saleable`: it compares against
`conditionOnHandQuantity(StockCondition::Saleable)` (line 230), and every serialised-unit guard requires
`$unit->stock_condition === StockCondition::Saleable` (lines 316, 323, 326, 343).

**Impact:** If a physical count finds that damaged or quarantined quantity is wrong, there is no way to
correct it. The adjustment will instead move *saleable* quantity to reconcile an aggregate discrepancy that
originated in another condition — which balances the total while making both condition balances wrong. This
is precisely the silent divergence IN-06 exists to prevent, and it interacts badly with GAP-MW-03: stock
stranded in quarantine cannot be counted out of it either.

**Priority:** **High**

---

### GAP-WL-04 — Receipt confirmation overwrites the invoice's lifecycle status

**Scenario:** SL-10 ("Receipt confirmation is evidence of *delivery of the document*, never evidence of
payment, and never a posting event"); SL-06.

**Expected behaviour:** An invoice has a lifecycle (draft → issued → sent) and, independently, an evidence
record that the customer received the document. These are two different facts about two different things.

**Current behaviour:** `InvoiceConfirmationService::confirm()` creates the `InvoiceConfirmation` row with
its signature correctly — and then writes the confirmation *type* into the invoice's *status* column:

```php
if (in_array($locked->status, ['issued', 'sent'], true)) {
    $locked->forceFill(['status' => $type, ...])->save();   // line ~50
}
```

After confirmation, `invoices.status` holds `'customer_received'` or `'employee_confirmed_received'`. The
`sent` state is destroyed, and because the new value is not in `['issued','sent']`, a subsequent
confirmation of the other type creates its `InvoiceConfirmation` row but silently fails to update the
status — a divergence with no error.

The financial machinery is unharmed: `Invoice::isIssued()` tests `issued_at !== null` rather than the
status string (`app/Models/Invoice.php:77`), so payment allocation, crediting and posting all continue to
work correctly. The damage is confined to the status field itself.

**Impact:** Two independent axes are collapsed into one column, so neither is answerable. "Which issued
invoices have not yet been sent?" cannot be asked once receipt is confirmed, and "which invoices were
confirmed by the customer versus by an employee?" is answerable only from the confirmations table, not the
invoice. Any future guard written against `status === 'sent'` will silently exclude every confirmed
invoice. Directly compounded by GAP-WL-01: an enum with a transition matrix would have made this
impossible to write.

**Priority:** **Medium**

---

### GAP-WL-05 — Available quantity is explainable in aggregate but not to a named cause

**Scenario:** IN-18 ("Availability is always *explainable* to a named cause; 'unavailable' is never an
unexplained number"); PM-05.

**Expected behaviour:** The stock position explains the gap between on-hand and available by naming its
causes: reserved quantity **with the documents holding it**, in-transit quantity **with its transfers**,
and quarantined, damaged or expired quantity **with its condition records**.

**Current behaviour:** The numbers are all present and correct. `StockLevelsTable` and `StockLevelInfolist`
surface on-hand, saleable, quarantine, damaged, reserved and available; `InventoryReportFormatter` exports
the same set. What is missing is the second half of every clause — the drill-through. Reserved quantity is
a number with no path to the reservations holding it, because `InventoryReservationResource` is a bare list
(see GAP-UI-01) with no filter by variant or warehouse and no link from the stock row. Quarantine and
damaged quantities have no path to the condition-change documents that produced them.

**Impact:** A salesperson who sees 40 on hand and 12 available learns that 28 are unavailable and cannot
find out why or who to ask. The scenario's requirement is not that the number exist — it does — but that
it be attributable, and it is not. This is the operational cost of GAP-MW-04 and GAP-MW-05 compounding: a
stale reservation is both undetectable and unreleasable.

**Priority:** **Medium**

---

# 4. Missing UI Representation

### GAP-UI-01 — The reservations screen is a list with no actions

**Scenario:** IN-03; IN-18.

**Expected behaviour:** An authorised operator can see what is holding stock, drill into the source
document, and release a stale hold through a sanctioned, audited action.

**Current behaviour:** `InventoryReservationResource` exposes one page, `ListInventoryReservations`, with
table columns only — no header actions, no row actions, no bulk actions, no filters. The domain behind it
is complete: `release()` and `expire()` are implemented, and `InventoryPermission::ReservationRelease`
exists to authorise the first of them.

**Impact:** This is the UI half of GAP-MW-04 and GAP-MW-05. The backend can free stuck stock and no user
can ask it to. Every reservation problem is escalated to a developer.

**Priority:** **High**

---

### GAP-UI-02 — Receivables get a list where payables get a subledger

**Scenario:** AC-05 (Maintain a receivables subledger that proves itself); SL-07; SL-15; flow F-14.

**Expected behaviour:** Per customer: invoiced, collected, credited and outstanding amounts, **aged against
due dates** with drill-down to documents, **reconciled against the receivable control account as an
explicit displayed proof**.

**Current behaviour:** `AccountsReceivable` consists of a single resource file and one page,
`ListAccountsReceivable` — a computed read-only surface over invoices, payments and credit notes. There are
no ageing buckets, no CSV export, no per-customer statement, and no reconciliation-to-control-account
proof. The payables side, built later under ADR 0011, has all of it: `AccountsPayableService` provides
`summary()`, `aging()`, `supplierDetail()`, `toCsv()` and `payableControlAccountMinor()`.

**Impact:** The company can age and prove what it owes and cannot age or prove what it is owed. Credit
control has no worklist, so SL-07's overdue chase has no starting point even before GAP-MW-12 removes the
reminder. AC-05's "any difference is shown as an error, never plugged" has no display to show it in, and
the AC-10 close cannot evidence that AR reconciles.

**Priority:** **High**

---

### GAP-UI-03 — The customer record knows nothing about the customer

**Scenario:** CR-05 ("One customer, one timeline — the account manager should never have to assemble the
relationship from five screens"); MD-01; PM-10.

**Expected behaviour:** A single customer timeline showing quotations, orders, invoices, payments, tickets,
visits and maintenance together.

**Current behaviour:** `CustomerProfile` declares exactly two relations — `user()` and
`deliveryAddresses()` (`app/Models/CustomerProfile.php:73, 79`). It has no `quotations()`, `orders()`,
`invoices()`, `payments()`, `tickets()`, `visits()` or `maintenanceRecords()`, even though every one of
those tables carries a `customer_id`. The inverse relations exist (`Order::customer()`,
`Invoice::customer()`), so the data is reachable from the other side. `ViewCustomer` has no relation
managers.

**Impact:** The account manager assembles the relationship from five screens, exactly as the scenario says
they should never have to. Before a customer call, or when judging exposure on a new order, the history has
to be reconstructed by filtering each module's list separately.

**Priority:** **High**

---

### GAP-UI-04 — The tax register lists entries but never proves the period

**Scenario:** AC-06 (Maintain the tax register); MD-07; AC-10.

**Expected behaviour:** The register lists, **per period**: tax charged on issued invoices as deferred, tax
recognised on collections as payable, tax reversed by credit notes and refunds, and purchase tax recognised
on approved bills — **reconciling to the deferred tax and tax payable accounts**. "The register must
reconcile to the ledger and must make the deferred-versus-payable distinction explicit, because that
distinction *is* the company's tax policy."

**Current behaviour:** The write path is exactly right — `TaxRecognitionService::recognise()` recognises
proportionally and settles the exact remainder to avoid rounding drift; `reverseForPayment()` and
`RefundService::unrecogniseTaxWhenRequired()` handle reversals; entries are immutable append-only facts.
The read path is `TaxResource`: a **list-only, read-only** page (`ListTaxes`) with a filter on `direction`.
There is no period grouping, no deferred-versus-payable summary, no reconciliation to the two tax accounts,
and no export. `FinancialReportType` has five cases and none of them is the tax register.

**Impact:** The accounting rule the whole system is built around — tax follows collection, not issuance —
has no report that demonstrates it. Preparing a filing means reading a flat list of recognition entries and
reconciling to the ledger by hand, and there is no artefact proving the register agrees with the deferred
tax and tax payable balances. This is also the AC-10 close's missing tax leg.

**Priority:** **High**

---

### GAP-UI-05 — Sales documents cannot be exported

**Scenario:** XC-04 ("filterable, exportable, and drill-through capable"); PRD FR-006.

**Expected behaviour:** Invoices and the documents around them are exportable to CSV/Excel, with export
records retained alongside their parameters so a figure can be reproduced.

**Current behaviour:** No export action exists on any sales resource — `Invoices/`, `Payments/`,
`Quotations/` and `CreditNotes/` contain no CSV or export action. Export is implemented and available for
Accounts Payable, Employee Reports, Financial Reports, Inventory Import Runs, Inventory Exports and
Purchasing Reports.

**Impact:** Finance cannot get invoice or payment data out of the system without database access. Every
ad-hoc reconciliation, auditor request or external filing that touches sales documents becomes a developer
task. Compounded by GAP-MW-17, sales is the only module with neither a report surface nor an export.

**Priority:** **Medium**

---

### GAP-UI-06 — Damage, recovery and disposal are actions on a stock row, not documents

**Scenario:** IN-07, IN-08, IN-09 ("**Evidence:** Condition-change document; movements with before and
after condition"; "Disposal document with authorisation and evidence").

**Expected behaviour:** Each condition change is a document with a cause, an authorising actor, and — for
disposal — attached disposal evidence, so a controller can later point at it. Recovery is "a new linked
document, never an edit of the damage record", referencing the original damage.

**Current behaviour:** `InventoryDamageService::damage/recover/dispose` posts correct movements with
before-and-after conditions, and the movement ledger is a complete record of *what happened*. But the
surface is `StockLevels/Actions/StockDamageActions` — modal actions on a stock row. There is no
condition-change document to open, no list of damage or disposal events, no attachment collection for
disposal evidence, and no reference field linking a recovery to the damage it reverses.

**Impact:** IN-08's "recovery is a new linked document referencing the original damage" is not
representable — the link exists only by inference from two movement rows. IN-09's disposal evidence has
nowhere to live, so the write-off an auditor will question is supported by a movement row and a free-text
reason. The damaged-stock work queue IN-07 calls for does not exist as a surface.

**Priority:** **Medium**

---

### GAP-UI-07 — Four navigation entries resolve to placeholders

**Scenario:** MD-07 (tax policy administration); XC-04; MD-08.

**Expected behaviour:** Configuration the accountant owns — tax policy in particular — is administrable
through a screen.

**Current behaviour:** `AdminModuleRegistry` declares navigation entries pointing at four classes that do
not exist: `TaxDefinitionResource`, `DocumentTemplateResource`, `OperationalReportResource` and
`Pages\Settings` (`app/Filament/AdminModuleRegistry.php:28, 46, 78, 255, 274-277`). `resolveLink()` guards
with `class_exists()` and falls through to `ModulePlaceholder`, so this is a deliberate
"declared, not built" pattern rather than a broken build, and `Unit/AdminModuleRegistryTest.php` pins the
behaviour.

**Impact:** A user navigating to Tax Definitions reaches a placeholder. Tax rate policy is administrable
only through `SalesSetting`, which is reachable elsewhere, so the business impact is confusion rather than
blockage. It is recorded here because MD-07's "the Accountant sets the default tax rate and the four
posting accounts" has no obvious home, and because the `use` statements for non-existent classes make the
intent ambiguous to the next maintainer.

**Priority:** **Medium**

---

### GAP-UI-08 — Two dead resource directories

**Scenario:** Codebase hygiene; no business scenario.

**Expected behaviour:** A resource directory contains a resource.

**Current behaviour:** `app/Filament/Resources/TaxRecognitionEntries/` contains an empty `Pages/` directory
and no resource class — a leftover of the rename to `Taxes/TaxResource`.
`app/Filament/Resources/InventoryExports/` contains only `Schemas/` with no resource class.

**Impact:** None to the business. Recorded so a maintainer reading the tree does not conclude that a tax
recognition resource exists.

**Priority:** **Low**

---

# 5. What is *not* a gap

Recorded deliberately, so a later reader does not re-open settled ground or mistake a correct
implementation for an unexamined one. Each was verified in this pass.

| Area | Scenario | Verified finding |
|---|---|---|
| Canonical stock posting | IN-01…IN-16, XC-07 | `InventoryBalanceService` is the sole stock writer, reachable only through `InventoryPostingService`; nine service callers; enforced by `ArchTest` **and** `InventoryDomainContractTest`. No workflow can change a balance without writing its movement. |
| Proportional tax recognition | SL-08, AC-03, F-05 | `TaxRecognitionService::recognise()` computes `min(remaining, allocation/total × taxTotal)` and recognises the exact remainder when an allocation settles the claim, so an invoice's recognitions sum to its tax total with no rounding drift. |
| Delivery recognises no tax | IN-04, SL-05, F-01 | Delivery completion posts stock movements and nothing to the ledger; guarded by `NoAutomaticPostingTest` and `QuotationTouchesNoStockTest`. |
| Narrow, named posting set | AC-16 | `JournalPostingService` has exactly six service callers plus the manual UI path, and the invariant is test-guarded. |
| Over-receipt under concurrency | IN-01, PU-06 | The receipt listener locks purchase order lines in id order inside the inventory transaction and throws `OverReceiptRejected`, rolling back the stock movement with it. |
| Customer return validation | IN-13 | Return quantity is capped at delivered-minus-already-returned, and lot and serial identity are validated against what was actually delivered (four distinct guards). *The financial link is the gap — see GAP-BW-01.* |
| Payment allocation integrity | SL-08 | Allocation total is capped at the payment amount, per-invoice allocation is capped at outstanding, cross-customer allocation is refused, and the same invoice cannot be allocated twice by one payment. |
| Refund controls | AC-12, F-13 | Maker ≠ checker is enforced by name; available credit is computed from collections, invoice claims, standalone credit notes and already-reserved refunds; payment reverses tax proportionally. |
| Payment term snapshot | MD-06, SL-06 | The invoice stores both `payment_term_id` and the resolved `due_date`, so a later policy edit cannot move an issued invoice's due date. |
| Single-invoicing of a delivery | SL-06 | Enforced twice — a service guard and a unique constraint. *Its side effect is GAP-MW-13.* |
| Purchase order approval | PU-03, PU-04 | Threshold auto-approval with the submitter recorded honestly as approver, self-approval rejected, immutability after send, cancellation blocked once anything is received. |
| Short-close as a human decision | PU-07 | `close()` requires an explicit reason, stamps `closed_at` and the actor, and audits — never automatic. |
| Posted entry immutability | AC-01, AC-11 | `PostedEntryIsImmutable` on any mutation; reversal-only correction; `EntryAlreadyReversed` blocks doubles; closed-period posting refused. |
| Price resolution | MD-12, PM-04 | Deterministic, non-stacking, lowest-eligible-wins with id tie-break, floor enforced as a hard block with a logged System Admin override. *Its provenance does not survive conversion — see GAP-BW-03.* |
| AI never acts alone | EM-05, EM-06, EM-07 | Transcription failure never blocks a visit; confidence carries an explicit provenance label and is never fabricated; every draft needs a recorded human decision. |
| Field evidence is not editable | EM-03, EM-04 | Visit editing was deliberately removed and the removal is test-asserted; reviewers may only add a review note. |
| Bin/location in the balance key | MD-05, IN-01 | Deliberately removed and test-asserted; replaced by `Package`/`PackageType`. Correctly out of scope. |

---

## 6. Remediation sequence

Ordered by business risk, not by effort. Items in one band are independent of each other.

**Band 1 — stop the bleeding (Critical):**
GAP-MW-03 (quarantine exit) → GAP-BW-01 (return ↔ credit note link) → GAP-MW-01 (CRM lead and campaign
foundation). The first two are contained changes to modules that are otherwise sound; the third is a new
module and should be sequenced accordingly.

**Band 2 — restore the controls that exist on paper (High, low effort):**
GAP-BW-04 (schedule `inventory:lots:reconcile`), GAP-MW-04 and GAP-MW-05 with GAP-UI-01 (reservation
expiry, release, and the action to invoke it), GAP-WL-02 (adjustment maker/checker), GAP-BW-05
(duplicate-bill control). Each of these is a small change that activates machinery already built and
tested.

**Band 3 — close the visibility gaps (High):**
GAP-MW-17 and GAP-UI-02 and GAP-UI-04 (sales reporting, AR subledger, tax register) — these three share a
reporting shape and are best done together. Then GAP-MW-16 and GAP-MW-18 (reconciliation report feeding
the period-close gate), GAP-MW-12 (notifications), GAP-UI-03 (customer 360).

**Band 4 — complete the workflows (High/Medium):**
GAP-MW-07 (write-off), GAP-MW-09 and GAP-MW-10 (service cost and billing), GAP-BW-02 (correction types),
GAP-BW-03 (provenance through conversion), GAP-WL-01 and GAP-WL-04 (status enums, which resolve together),
GAP-WL-03 (adjustments across conditions), GAP-MW-02 (opportunity as a first-class object).

**Band 5 — scope decisions for the owner, not the engineer:**
GAP-MW-15 (valuation and COGS), GAP-MW-19 (customer and employee channels), GAP-MW-11 (ticket revenue),
GAP-MW-14 (supplier debit notes). Each is deferred by an ADR. The decision to be taken is whether the
business consequence recorded above is still acceptable — particularly GAP-MW-15, which is why no margin
figure exists anywhere in the system.

---

*Companion document:* `CROSS_MODULE_FLOW_MATRIX.md` maps these gaps onto the module-to-module seams where
they are felt.

*End of document.*
