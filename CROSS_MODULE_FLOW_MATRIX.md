# Cross-Module Flow Matrix — IERP

**Document type:** Seam-by-seam audit of module-to-module handoffs
**Perspective:** ERP Solution Architect / Business Process Auditor
**Baseline branch:** `feat/cross-module-remediation` (`b29a49a`)
**Created:** 2026-09-03
**Companion:** `BUSINESS_LOGIC_GAPS.md` (gap definitions, impact, priority)

---

## 0. How to read this document

`BUSINESS_LOGIC_GAPS.md` answers *what is wrong*. This document answers *where it is felt* — at which
handoff between two modules a business fact either crosses intact, crosses damaged, or fails to cross.

Each row is **one handoff**, not one module. A row exists wherever a fact must move from a module that owns
it to a module that depends on it.

| Column | Meaning |
|---|---|
| **From Module** | The module that owns the fact at the start of the handoff |
| **To Module** | The module that must receive it |
| **Scenario** | The scenario (`Docs/EXPECTED_BUSINESS_SCENARIOS.md`) and flow (`Docs/CROSS_MODULE_BUSINESS_FLOWS.md`) the handoff belongs to |
| **Expected** | What must cross the seam for the business outcome to hold |
| **Current** | What actually crosses it at `b29a49a`, verified against source |
| **Gap** | `OK` if the seam holds; otherwise the gap ID(s) from `BUSINESS_LOGIC_GAPS.md` and a one-line verdict |

### Verdict vocabulary used in the Gap column

| Verdict | Meaning |
|---|---|
| **OK** | The fact crosses intact. No action. |
| **Severed** | The two sides exist and nothing connects them. |
| **Lossy** | The fact crosses but arrives incomplete. |
| **Dormant** | The connection is built but nothing triggers it. |
| **Absent** | One side of the seam does not exist. |
| **Deferred** | Absent by an explicit ADR decision; recorded, not a defect. |

### Seam health summary

| Chain | Seams audited | OK | Impaired |
|---|---|---|---|
| Sales ↔ Inventory ↔ Accounting (the money spine) | 15 | 10 | 5 |
| Purchasing ↔ Inventory ↔ Accounting | 9 | 7 | 2 |
| CRM ↔ Sales | 6 | 1 | 5 |
| Support ↔ Maintenance ↔ Inventory ↔ Accounting | 7 | 3 | 4 |
| Employees ↔ CRM ↔ Sales ↔ Payroll | 6 | 3 | 3 |
| Inventory internal (condition, reservation, correction) | 8 | 3 | 5 |
| Cross-cutting (audit, notification, reporting, close) | 9 | 0 | 9 |
| **Total** | **60** | **27** | **33** |

The pattern the totals show: **the core transactional spine is sound and the surrounding accountability
layer is not.** Where two modules exchange a *document*, the seam usually holds. Where they must exchange
*evidence, explanation, or a signal*, it usually does not.

---

## 1. Sales ↔ Inventory ↔ Accounting — the money spine

Flows F-01 (normal sale), F-03 (return and credit note), F-05 (partial payment), F-13 (overpayment to
refund), F-15 (cancellation), F-18 (quotation expiry).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Sales (Quotation) | Sales (Order) | SL-03, F-01 | One order per accepted quotation, carrying customer, payment term, priced lines and totals; double conversion refused | `QuotationConversionService::convert` creates the `SO-` order, copies aggregated lines with UOM snapshots, sets `ConvertedToDelivery` + `converted_order_id`, and rejects a second conversion | **OK** |
| Sales (Pricing) | Sales (Order/Invoice) | MD-12, CR-08, F-11 | Every priced line carries the rule that produced it, so any document's price is explainable | `resolved_price_source` exists on `quotation_lines` only; `order_lines` and `invoice_lines` have no provenance column | **GAP-BW-03** — Lossy: provenance dies at conversion |
| Sales (Order) | Inventory (Reservation) | IN-03, SL-05, F-01 | A source-linked reservation drops available quantity without moving on-hand | `OrderFulfillmentService::create` → `InventoryOperationService` reserves per warehouse under lock, with allocations for tracked variants | **OK** |
| Sales (Order) | Inventory (Delivery) | IN-04, SL-05, F-01 | Confirmation consumes the reservation, decreases the balance at the right condition, transfers serialised custody, writes movements — all in one transaction | `complete()` does all of it in a single locked transaction, with type guards and quantity snapshots | **OK** |
| Inventory (Delivery) | Accounting (Ledger) | IN-04, SL-05, F-01 | Stock moves; **no tax and no revenue is recognised** | Delivery posts `MovementType::Sale` movements and nothing to the ledger; guarded by `NoAutomaticPostingTest` | **OK** |
| Inventory (Delivery) | Accounting (COGS) | AC-14, F-01 | Delivery relieves inventory value and recognises cost of goods sold against the revenue the invoice recognises | No inventory asset account, no COGS entry, no valuation basis | **GAP-MW-15** — Deferred (ADR 0007/0008): P&L reports revenue with no cost of sales |
| Inventory (Delivery) | Sales (Invoice) | SL-06, F-01 | A completed delivery becomes invoiceable, at most once; several deliveries may consolidate onto one invoice | `createFromDelivery()` requires a completed delivery and refuses a second invoice. `invoices.inventory_operation_id` is **unique**, so consolidation is structurally impossible | **GAP-MW-13** — Lossy: single-invoicing enforced, consolidation blocked; the `createStandalone` workaround bypasses the control |
| Sales (Invoice) | Accounting (Ledger) | SL-06, AC-02, F-01 | One balanced entry on issuance: debit receivable, credit revenue, **credit deferred tax — never tax payable** | `InvoicePostingService::post` writes exactly that entry, source-linked to the invoice | **OK** |
| Payments (Collection) | Tax (Recognition) | SL-08, AC-03, F-05 | Tax moves from deferred to payable **in proportion to the collected share**, with the exact remainder on settlement and no rounding drift | `TaxRecognitionService::recognise` computes `min(remaining, allocation/total × taxTotal)` and settles the exact remainder against `total − credited` | **OK** |
| Payments (Collection) | Sales (Invoice state) | SL-08, F-05 | Paid amount advances; state derives to partially paid or paid; allocation never exceeds payment or outstanding | `PaymentAllocationService` caps per invoice and per payment, refuses cross-customer and duplicate allocation; `InvoiceBalanceService` syncs invoice and order | **OK** |
| Sales (Credit note) | Accounting (Ledger) | SL-12, AC-04, F-03 | One balanced entry: debit revenue; debit deferred tax and/or tax payable split by the already-recognised ratio; credit receivable | `CreditNotePostingService::post` writes the split reversal and bumps `invoices.credited_amount` | **OK** |
| Sales (Credit note) | Inventory (Return) | SL-12 step 6, IN-13 step 5, F-03 | Two distinct documents that are **linked**, so a credit can be tied to the goods that came back — or explicitly to none | No foreign key in either direction. `inventory_returns` has no `credit_note_id`; `credit_notes` has no `inventory_return_id` | **GAP-BW-01** — Severed: double-crediting is undetectable; goods-retained credits are indistinguishable from clerical omissions |
| Accounting (Credit note) | Accounting (Refund) | AC-12, F-13 | Refund draws only on available credit, is approved by someone other than the recorder, and reverses recognised tax proportionally | `RefundService` links to `credit_note_id`/`invoice_id`, computes available credit from collections, invoice claims, standalone credit notes and reserved refunds, enforces maker ≠ checker by name, and un-recognises tax on payment | **OK** |
| Sales (Cancellation) | Inventory (Reservation) | SL-11, F-15 | Cancelling an uncommitted document releases its reservations; availability rises immediately | `InventoryOperationService::cancel` → `releaseOperation`; cancel after dispatch correctly refuses to restore source stock | **OK** |
| Sales (Quotation validity) | Sales (Pipeline) | SL-02, SL-16, F-18 | Expiry is enforced on decision **and** derived for display, so a stale offer never looks live | Enforced on decision (`recordDecision` writes `Expired` before refusing). No sweep, no scheduled transition — every untouched expired quotation stays `Sent` | **GAP-BW-07** — Dormant: open-quotation and win-rate figures over-count |

---

## 2. Purchasing ↔ Inventory ↔ Accounting

Flows F-02 (shortage sale), F-21 (procure-to-pay), F-08 (supplier return).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Sales (Shortage) | Purchasing (PO) | SL-04, PU-02, F-02 | An uncoverable order routes to supplier confirmation or procurement, with the coverage link recorded | `SalesProcurementService::detectShortages` → `sales_procurement_requirements` → `requestSupplierConfirmation` / `createPurchaseOrder`, with eligible suppliers resolved from `supplier_product_supports` | **OK** |
| Purchasing (PO) | Inventory (Receipt) | IN-01, PU-06, F-21 | Purchasing never writes stock itself; the receipt posts through the canonical path and names the PO as its source | `PurchaseOrderReceivingService::initiate` creates an `InventoryOperation` of type `Receipt` with `source = PurchaseOrder`; all posting goes through `InventoryPostingService` | **OK** |
| Inventory (Receipt) | Purchasing (PO advance) | IN-01, PU-06, F-21 | Received quantities advance under lock; cumulative receipt may never exceed ordered | `AdvancePurchaseOrderOnOperationCompleted` runs synchronously inside the inventory transaction, locks lines in id order, throws `OverReceiptRejected` — which rolls back the stock movement with it | **OK** — the strongest seam in the system |
| Inventory (Receipt) | Purchasing (Cost) | PU-01, MD-03, F-21 | The actual received unit cost is written back so the next order starts from reality | `SupplierCostWritebackService::apply` updates supplier references and variant cost inside the same transaction | **OK** |
| Inventory (Receipt) | Sales (Coverage) | SL-04, F-02 | The waiting customer order learns its coverage arrived | `AdvanceSalesProcurementOnOperationCompleted` → `refreshFromPurchaseOrder` → `sales.order.procurement_fulfilled` | **OK** |
| Purchasing (Bill) | Accounting (Payable) | PU-09, AC-07, F-21 | Approval recognises the payable; the supplier's own invoice number is unique per supplier — the **primary duplicate-payment control** | `AccountingDocumentService::recordBill` performs advisory three-way matching and checks `supplier_reference` per supplier — but returns early when the reference is blank, and there is no unique constraint or index behind it | **GAP-BW-05** — Lossy: the control is bypassed by a blank reference and by a concurrent write |
| Purchasing (PO) | Accounting (Ledger) | PU-02, AC-16 | A purchase order commits money but creates **no** accounting entry | Verified: `JournalPostingService` has no Purchasing caller | **OK** |
| Accounting (Payment) | Purchasing (Bill state) | PU-10, AC-08, F-21 | Supplier payment allocates across bills, debits payable control, credits the method's account, advances each bill's state | `AccountingDocumentService::paySupplierPayment` posts the entry with remaining-balance and same-supplier guards, then recomputes bill status | **OK** |
| Inventory (Supplier return) | Purchasing (Debit note) | IN-15, F-08 | The physical return is matched by an explicit supplier credit or debit note, linked to it | `InventoryReturnService::createSupplierReturn` correctly caps against the receipt line. There is no debit note, no supplier credit document, and no expected-outcome field | **GAP-MW-14** — Deferred (ADR 0006): stock leaves, the payable stays at full value |

---

## 3. CRM ↔ Sales

Flows F-17 (campaign to first sale), F-11 (below-floor approval), F-04 (AI opportunity).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| CRM (Campaign) | CRM (Lead) | CR-06, CR-01, F-17 | A campaign builds a recipient list, records per-recipient sends and responses, and interested responses become leads attributable back to the campaign | No campaign, recipient, response, lead or interaction model exists | **GAP-MW-01** — Absent: F-17 has no first step |
| CRM (Lead) | CRM (Customer) | CR-03, MD-01, F-17 | Conversion carries the lead's interaction history onto the customer and marks the lead converted, pointing at what it became | Customer onboarding (`CustomerOnboardingService::register`) is complete and sound. There is no lead to convert from | **GAP-MW-01** — Absent |
| CRM (Opportunity) | Sales (Quotation) | CR-04, SL-01, F-04 | An opportunity with a party, value, stage and expected close date produces a quotation carrying its summary forward | `QuotationService::createFromOpportunity` works and refuses double-quoting. But `sales_opportunities` requires a **non-null `voice_note_transcription_id`**, has no customer, value, close date, stage or loss reason | **GAP-MW-02** — Lossy: only AI-originated opportunities can exist; pipeline value and win/loss are uncomputable |
| CRM (Pricing tier) | Sales (Quotation line) | MD-12, PM-04, F-11 | Deterministic, non-stacking resolution; the floor is a hard block with a logged System Admin override | `PriceResolver` resolves customer-specific → lowest product-scoped → general → base with an id tie-break; `assertAtOrAboveFloor` blocks; `price_floor_overrides` records the approval | **OK** |
| CRM (Customer) | All transacting modules | CR-05, MD-01 | One customer, one timeline — quotations, orders, invoices, payments, tickets, visits and maintenance visible from the customer record | `CustomerProfile` declares two relations: `user()` and `deliveryAddresses()`. Every transacting table carries `customer_id`, but no relation and no relation manager exposes it | **GAP-UI-03** — Severed: the account manager assembles the relationship from five screens |
| CRM (Funnel) | Accounting (Revenue) | CR-07, SL-15, F-17 | The funnel is traceable to invoiced and collected revenue, not just to "interested" | No funnel exists, and no sales report surface exists to receive it | **GAP-MW-01 + GAP-MW-17** — Absent at both ends |

---

## 4. Support ↔ Maintenance ↔ Inventory ↔ Accounting

Flows F-06 (chargeable ticket to service revenue), F-07 (warranty service at zero revenue), F-12
(serialised device life).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Support (Ticket) | Maintenance (Request) | SU-08, MT-01, F-06 | The ticket raises a maintenance request carrying customer, product, serial and problem; the two stay linked in both directions | `MaintenanceRecordService::createFromTicket` links the record to the ticket, and `TicketLifecycleService` blocks resolution while a non-terminal maintenance record is open | **OK** |
| Maintenance (Request) | Inventory (Serialised unit) | MT-01, PM-10, F-12 | Linking to a serialised unit happens only on a real identifier match; warranty status is explicit and never guessed | `resolveEquipmentAndWarranty()` validates the serialised unit and throws on mismatch; `WarrantyStatus` includes an explicit `Unknown` | **OK** |
| Maintenance (Service record) | Inventory (Consumption) | MT-03, IN-12, F-07 | Parts decrease stock through the canonical posting path, with allocations for tracked variants, and each consumption is paired with the movement it produced | `ServiceRecordPartService::consume` posts `MovementType::ServiceConsumption` through `InventoryPostingService` with tracking allocation; `reverse()` posts the compensating movement | **OK** |
| Inventory (Consumption) | Maintenance (Job cost) | MT-05, F-07 | The part's cost attaches to the service record, building the job's cost alongside labour and third-party cost | `service_record_parts` stores quantity and the movement id and **no cost column**. No labour time, no rate, no third-party cost, no job-cost report | **GAP-MW-09** — Severed: a warranty job is a blank record, exactly as MT-05 warns |
| Maintenance (Completed work) | Sales (Invoice) | MT-06, F-06 | Completed chargeable work produces a quotation or invoice and follows the standard invoice → collection → tax path | No link from `MaintenanceRecord` or `MaintenanceTask` to any sales document. Billing means hand-keying a standalone invoice | **GAP-MW-10** — Severed: service revenue is disconnected from the work that earned it |
| Support (Ticket payment) | Accounting (Ledger + Tax) | SU-02, MT-06, F-06 | Ticket revenue is recognised through the **same** invoice, collection and tax machinery as goods revenue | `TicketPaymentService` writes `ticket_payment_links` and `tickets` only; its docblock states no accounting side effect exists | **GAP-MW-11** — Deferred (ADR 0008): cash is collected, and the ledger and tax register never see it |
| Maintenance (Schedule) | Maintenance (Due request) | MT-07 | Serviceable equipment carries an interval; due requests are raised in advance; a missed service is visible as missed | `MaintenanceTask` is a checklist, not a schedule. No recurrence, no due generation, no scheduler entry | **GAP-MW-08** — Absent: all maintenance is reactive |

---

## 5. Employees ↔ CRM ↔ Sales ↔ Payroll

Flows F-04 (AI opportunity), F-20 (field sale from a van warehouse), F-14 (month-end).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Employees (Voice note) | AI (Transcription) | EM-05, EM-06, F-04 | Failure never blocks visit completion; confidence carries an explicit provenance label and is never fabricated | `TranscribeVoiceNoteJob` records `VoiceNoteStatus::Failed` and changes nothing else; `TranscriptionConfidenceSource` distinguishes `ProviderReported` / `DerivedFromLogProb` / `Unavailable` | **OK** |
| AI (Transcription) | CRM (Opportunity draft) | EM-07, F-04 | The draft names the transcript and the matched rules; no AI output takes effect without a recorded human decision; a rejected draft is retained as evidence | `KeywordDetectionService` creates the draft; `OpportunityReviewService::approve/reject` records `reviewed_by` and `review_notes`. But `voice_note_transcription_id` is `cascadeOnDelete`, so deleting the transcription hard-deletes the draft **and the human decision on it** | **GAP-BW-08** — Lossy: AI-origin evidence is destructible by an audio retention cleanup |
| Employees (Field) | Employees (Visit capture) | EM-03, EM-04, F-20 | The employee checks in with GPS, records the visit, and checks out; captured data is not editable by a reviewer | The review side is complete — `CustomerVisit`, `VisitGpsLog`, derived duration, GPS trail map, and visit editing deliberately removed and test-asserted. There is **no capture channel**: no API, no mobile client | **GAP-MW-19** — Deferred (ADR 0003): GPS-verified check-in cannot be captured at all |
| Employees (Visit) | Sales (Quotation / Delivery) | SL-14, F-20 | A field sale obeys the same floor controls, availability checks, reservation and posting rules; the van is a warehouse | The domain supports it — warehouse roles include a service van, and all sales controls are service-layer rather than form-layer. No field surface exists to invoke them | **GAP-MW-19** — Deferred: F-20 does not run |
| Employees (Visit / Task) | Payroll (Score) | EM-09, EM-10, F-14 | Scoring is deterministic and reproducible, with a full breakdown snapshotting weights, thresholds and inputs so a later edit cannot rewrite history | `PerformanceScoringService` computes from task status, executed visits, due dates and check-in/out durations, storing the full breakdown; `SalaryCalculationService` copies the payable base onto the calculation, and correction is supersession rather than edit | **OK** |
| Payroll (Score) | Payroll (Salary) | EM-10, EM-11 | Only approved bonuses contribute; confirmation and calculation are separate permissions | `SalaryCalculate` and `SalaryConfirm` are distinct; `BonusApprovalService` gates contribution on approval; supersession chain preserved | **OK** |

---

## 6. Inventory internal seams

Flows F-09 (inter-warehouse transfer), F-10 (lot recall and expiry write-off), F-16 (cycle count).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Inventory (Warehouse A) | Inventory (Warehouse B) | IN-05, F-09 | Dispatch removes source custody into in-transit; receipt adds destination custody; lot and serial identity survive; discrepancies are dispositioned, never absorbed | `dispatch()` → `transferOut` + `InTransit`; `receiveTransfer()` → `transferIn`, holding `PartiallyReceived` until filled; `TransferDiscrepancyDisposition` (`Shortage` / `Damaged` / `Cancelled`) raises alerts | **OK** |
| Inventory (Balance) | Inventory (Movement ledger) | IN-01…IN-16, XC-07 | Every stock-changing operation writes its movement in the same transaction; there is exactly one posting path | `InventoryBalanceService` is the sole stock writer and is reachable only through `InventoryPostingService`; nine service callers; enforced by `ArchTest` and `InventoryDomainContractTest` | **OK** — the architectural spine of the system |
| Inventory (Saleable) | Inventory (Quarantine) | IN-14, IN-01 step 3 | Stock enters quarantine and is dispositioned out by a named inspector: release, downgrade, dispose, or return to supplier | Entry works (`InventoryReturnDisposition::Quarantine`, receipt). **No service moves stock out**: `InventoryDamageService` maps only `Saleable ↔ Damaged → Disposed`; adjustments touch `Saleable` only | **GAP-MW-03** — Severed: quarantined stock is stranded permanently with no in-system remedy |
| Inventory (Damage) | Inventory (Recovery / Disposal) | IN-07, IN-08, IN-09 | Each condition change is a document with a cause and an actor; recovery is a **new linked document** referencing the original damage; disposal carries evidence | `InventoryDamageService::damage/recover/dispose` posts correct movements. The surface is modal actions on a stock row — no condition-change document, no damage-to-recovery reference, no attachment for disposal evidence | **GAP-UI-06** — Lossy: the link exists only by inference from two movement rows |
| Inventory (Count) | Inventory (Adjustment) | IN-06, F-16 | A scoped count records counted quantity at lot and serial identity; variance is valued; a **separate person** confirms | `InventoryAdjustment` is `Draft → Confirmed` with correct locking and posting. No count scope, no worksheet, no variance valuation. `confirm()` never compares `$actor` to `created_by`, and reads and writes `Saleable` only | **GAP-MW-06 + GAP-WL-02 + GAP-WL-03** — Lossy: one actor can create and confirm; non-saleable conditions are uncorrectable |
| Inventory (Posted document) | Inventory (Correction) | IN-16 | Every post-commit change is a new document referencing the original — for receipts, deliveries and transfers alike | `InventoryCorrectionService` is correct, but `InventoryCorrectionType` has exactly one case: `Receipt`. Deliveries and transfers have no linked correction path | **GAP-BW-02** — Lossy: a mis-posted delivery or transfer has no referencing remedy |
| Inventory (Reservation) | Inventory (Availability) | IN-03, IN-18, F-15 | A reservation is consumed, released, or expires exactly once; availability rises immediately on release | Consume and release-by-cancellation run correctly from `InventoryOperationService`. `expire()` has no production caller and no scheduler entry; `release()` has no caller, no UI, and its permission has zero references | **GAP-MW-04 + GAP-MW-05 + GAP-UI-01** — Dormant: availability understates reality without bound |
| Inventory (Lot) | Inventory (FEFO / expiry) | IN-10, F-10 | Lots ordered by nearest expiry; expiring lots surface as a work queue and alerts; expired stock is non-saleable, with an audited override | `InventoryLotService` (`assertReservable`, `availableLots`), `InventoryAlertType::Expiry` and `ExpiredStockReleased`, `ExpiredStockOverride` permission, `inventory:alerts:reconcile` daily | **OK** |

---

## 7. Cross-cutting seams

Flows F-14 (month-end close) and the XC scenarios that every other flow depends on.

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Every module | Audit trail | XC-02 | Every financial, inventory, pricing, payroll and access change records actor, record, time, channel and before/after values | Genuinely good coverage — 79 activity event names with `source_channel` and `ip_address`. But `InventoryOperationService` logs only `inventory.operation.canceled`: completing a delivery, receipt or transfer writes no audit entry | **GAP-BW-06** — Lossy: the most consequential act is the least audited |
| Every module | Notification | XC-03 | Invoice issued, payment received, task assigned, SLA at risk, stock low, lot expiring, approval pending — delivered per party per channel, every attempt logged and retried | `app/Notifications/` does not exist. One mail class (`InvoiceMail`) and one internal job (`NotifyAdminOfSalaryRecalculation`). No delivery log, no retry, no templates, no reminder schedule | **GAP-MW-12** — Absent: every alert degrades to "someone remembers to look" |
| Sales | Reporting | SL-15, XC-04 | Win rate, conversion velocity, **delivered-not-invoiced**, **invoiced-not-collected**, tax position, margin, discount and floor-override incidence | Every other module has a report resource. Sales has none — two dashboard widgets, no report service, no export on any sales resource | **GAP-MW-17 + GAP-UI-05** — Absent: the two named money-leak points are unmonitored |
| Accounting (AR) | Reporting | AC-05, F-14 | Per customer: invoiced, collected, credited, outstanding; **aged**; reconciled to the receivable control account as displayed proof | `ListAccountsReceivable` is a computed list. No ageing, no export, no statement, no reconciliation proof. Payables has all five (`AccountsPayableService`) | **GAP-UI-02** — Lossy: the company can prove what it owes and not what it is owed |
| Tax | Reporting | AC-06, MD-07, F-14 | Per period: tax deferred on issuance, recognised on collection, reversed by credits and refunds, input tax on approved bills — reconciling to both tax accounts | The write path is exact and drift-free. The read path is a flat, list-only `TaxResource` with a `direction` filter — no period grouping, no deferred-vs-payable summary, no reconciliation, no export | **GAP-UI-04** — Lossy: the company's defining accounting rule has no report that demonstrates it |
| Inventory | Reconciliation proof | IN-17, F-14 | Aggregate = sum of lots; reserved = sum of allocations; serialised custody agrees; the ledger replays to the balance — proved continuously, divergence shown as an error | `inventory:lots:reconcile` checks exactly these invariants and is **not scheduled**; `InventoryReportType` has 12 cases and none is a reconciliation report | **GAP-MW-16 + GAP-BW-04** — Dormant: the system can detect its own divergence and never does |
| Reconciliation | Accounting (Period close) | AC-10, F-14 | A period cannot close while a mandatory reconciliation shows a difference | `FiscalPeriodService::close()` authorises, flips `is_closed`, and logs. No trial-balance check, no AR/AP check, no stock check, no checklist. (Posting *into* a closed period is correctly refused.) | **GAP-MW-18** — Absent: the close is a formality, not a control |
| Accounting (AR) | Accounting (Bad debt) | AC-13 | An authorised write-off reverses the receivable against bad-debt expense, with reason and approver; the invoice reads written off, never paid | No write-off document, service, status or account reference exists anywhere | **GAP-MW-07** — Absent: the only in-system exits are a credit note (which wrongly debits revenue) or an indefinitely open invoice |
| Every module | Customer / Employee channel | SL-13, SL-14, EM-03, SU-01, F-19, F-20 | An in-app decision and a staff-recorded decision produce the **same** business record, so the flow does not fork by channel | No `routes/api.php`, no Sanctum, no `JsonResource`, no mobile client. The domain is channel-ready (`recordDecision` keeps decider and recorder; `ShipmentConfirmationSource` distinguishes `Customer` / `AdminUser` / `System`) but every path is invoked by an admin | **GAP-MW-19** — Deferred (ADR 0003/0008): F-19 and F-20 do not run |

---

## 8. What the matrix shows

**The transactional core is sound.** Every seam where a *document* hands off to another *document* holds:
quotation → order → delivery → invoice → payment → tax → ledger runs end to end with correct locking,
correct posting, correct proportional tax recognition, and a test-guarded invariant that no workflow can
change a stock balance without writing its movement. The purchase-order receipt seam — where over-receipt
is rejected under a row lock inside the inventory transaction, rolling back the stock movement with it — is
better than most production ERPs manage.

**The accountability layer around it is not.** Thirty-three of the sixty seams are impaired, and they
cluster into four recognisable shapes:

1. **Evidence that does not cross.** A return and its credit note (GAP-BW-01), a damage and its recovery
   (GAP-UI-06), a price and the rule that produced it (GAP-BW-03), a job and its cost (GAP-MW-09). In each
   case both sides are individually well built and nothing joins them, so neither can prove the other
   happened.

2. **Machinery that is built and never invoked.** Reservation expiry and release (GAP-MW-04, GAP-MW-05),
   the stock reconciliation command (GAP-BW-04), the `ReservationRelease` permission with zero references.
   These are the cheapest fixes in the document and among the highest-value: the code exists, is tested,
   and is one scheduler line or one Filament action away from working.

3. **Controls documented but not enforced.** Adjustment maker/checker (GAP-WL-02), the duplicate-bill
   guard's blank-reference escape (GAP-BW-05), the unguarded period close (GAP-MW-18). Each has a correct
   sibling implementation elsewhere in the same codebase — `RefundService` enforces maker ≠ checker by
   name, `PurchaseOrderApprovalService` throws `SelfApprovalRejected` — so the pattern is established and
   was simply not applied.

4. **Whole modules that were scoped and not built.** CRM leads and campaigns (GAP-MW-01), opportunity
   management (GAP-MW-02), sales reporting (GAP-MW-17), notifications (GAP-MW-12), bad-debt write-off
   (GAP-MW-07).

**One seam deserves separate mention.** GAP-MW-03 — quarantine having an entrance and no exit — is the only
gap in the matrix where a routine, correct business action puts company assets permanently beyond reach with
no in-system remedy. It is a small fix in a module that is otherwise the strongest in the codebase, and it
should not wait behind anything.

---

*Companion document:* `BUSINESS_LOGIC_GAPS.md` — gap definitions, impact, priority, and remediation
sequence.

*End of document.*
