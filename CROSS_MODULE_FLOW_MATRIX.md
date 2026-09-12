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

**2026-09-12 update:** this table originally scored 33/60 OK at the `2026-09-03` baseline. A Phase 5
remediation effort planned work against the 27 impaired rows and found, WP by WP, that most of them were
already fixed in the source tree without this document being updated. That prompted a full re-verification
of every remaining row directly against current source and tests (not this document's own prose) — see the
companion reconciliation note in `BUSINESS_LOGIC_GAPS.md`. All 60 seams now verify **OK**.

| Chain | Seams audited | OK | Impaired |
|---|---|---|---|
| Sales ↔ Inventory ↔ Accounting (the money spine) | 15 | 15 | 0 |
| Purchasing ↔ Inventory ↔ Accounting | 9 | 9 | 0 |
| CRM ↔ Sales | 6 | 6 | 0 |
| Support ↔ Maintenance ↔ Inventory ↔ Accounting | 7 | 7 | 0 |
| Employees ↔ CRM ↔ Sales ↔ Payroll | 6 | 6 | 0 |
| Inventory internal (condition, reservation, correction) | 8 | 8 | 0 |
| Cross-cutting (audit, notification, reporting, close) | 9 | 9 | 0 |
| **Total** | **60** | **60** | **0** |

Each row below still carries its original `Expected`/pre-fix `Current` framing where useful, updated with
the evidence that closed it — nothing was deleted, so the record of what was broken and how it was fixed is
preserved. `BUSINESS_LOGIC_GAPS.md`'s GAP-WL-05, initially logged mid-reconciliation as only partially
resolved, was found fully resolved on a second look — `StockAvailabilityExplainer` already drills every
named cause (reserved, quarantine, damaged) through to its holding documents.

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
| Sales (Pricing) | Sales (Order/Invoice) | MD-12, CR-08, F-11 | Every priced line carries the rule that produced it, so any document's price is explainable | `resolved_price_source`, `resolved_price_tier_id`, `price_floor_override_id`, `list_price_minor`, and `floor_price_minor` now exist on `order_lines` and `invoice_lines` too, backfilled from the quotation, and typed via a shared `CarriesPriceProvenance` cast | **OK** (resolved 2026-09-12 — GAP-BW-03) |
| Sales (Order) | Inventory (Reservation) | IN-03, SL-05, F-01 | A source-linked reservation drops available quantity without moving on-hand | `OrderFulfillmentService::create` → `InventoryOperationService` reserves per warehouse under lock, with allocations for tracked variants | **OK** |
| Sales (Order) | Inventory (Delivery) | IN-04, SL-05, F-01 | Confirmation consumes the reservation, decreases the balance at the right condition, transfers serialised custody, writes movements — all in one transaction | `complete()` does all of it in a single locked transaction, with type guards and quantity snapshots | **OK** |
| Inventory (Delivery) | Accounting (Ledger) | IN-04, SL-05, F-01 | Stock moves; **no tax and no revenue is recognised** | Delivery posts `MovementType::Sale` movements and nothing to the ledger; guarded by `NoAutomaticPostingTest` | **OK** |
| Inventory (Delivery) | Accounting (COGS) | AC-14, F-01 | Delivery relieves inventory value and recognises cost of goods sold against the revenue the invoice recognises | `InventoryValuationService` posts receipt→GRNI, delivery→COGS, damage/disposal→shrinkage behind an opt-in `isConfigured()` gate. `InventoryMovementObserver` (`#[ObservedBy]` on `InventoryMovement`) fires `processStandaloneMovement()` on every movement; `ApplyInventoryValuationOnOperationCompleted` handles `InventoryOperationCompleted`, dispatched synchronously in-transaction from `InventoryOperationService::complete()`. `PeriodCloseChecklistService::checkInventoryValuation()` makes `InventoryAgreesToControlAccount` a mandatory close check. 11 tests in `InventoryValuationServiceTest` | **OK** (ADR 0013) — wired and enforced at close, not dormant; no end-to-end test yet drives the event→listener chain itself, only direct service calls are tested |
| Inventory (Delivery) | Sales (Invoice) | SL-06, F-01 | A completed delivery becomes invoiceable, at most once; several deliveries may consolidate onto one invoice | `invoice_delivery_links` (many-to-one, unique `inventory_operation_id`) plus `InvoiceService::createFromDeliveries()` now consolidate several completed deliveries for one customer onto one invoice while keeping each delivery invoiced at most once | **OK** (resolved 2026-09-12 — GAP-MW-13) |
| Sales (Invoice) | Accounting (Ledger) | SL-06, AC-02, F-01 | One balanced entry on issuance: debit receivable, credit revenue, **credit deferred tax — never tax payable** | `InvoicePostingService::post` writes exactly that entry, source-linked to the invoice | **OK** |
| Payments (Collection) | Tax (Recognition) | SL-08, AC-03, F-05 | Tax moves from deferred to payable **in proportion to the collected share**, with the exact remainder on settlement and no rounding drift | `TaxRecognitionService::recognise` computes `min(remaining, allocation/total × taxTotal)` and settles the exact remainder against `total − credited` | **OK** |
| Payments (Collection) | Sales (Invoice state) | SL-08, F-05 | Paid amount advances; state derives to partially paid or paid; allocation never exceeds payment or outstanding | `PaymentAllocationService` caps per invoice and per payment, refuses cross-customer and duplicate allocation; `InvoiceBalanceService` syncs invoice and order | **OK** |
| Sales (Credit note) | Accounting (Ledger) | SL-12, AC-04, F-03 | One balanced entry: debit revenue; debit deferred tax and/or tax payable split by the already-recognised ratio; credit receivable | `CreditNotePostingService::post` writes the split reversal and bumps `invoices.credited_amount` | **OK** |
| Sales (Credit note) | Inventory (Return) | SL-12 step 6, IN-13 step 5, F-03 | Two distinct documents that are **linked**, so a credit can be tied to the goods that came back — or explicitly to none | `credit_notes.inventory_return_id` plus a unique `[credit_note_id, inventory_return_line_id]` pair on credit note lines now link the two documents at line level; `CreditNoteService` caps credited quantity against the linked return line | **OK** (resolved 2026-09-12 — GAP-BW-01) |
| Accounting (Credit note) | Accounting (Refund) | AC-12, F-13 | Refund draws only on available credit, is approved by someone other than the recorder, and reverses recognised tax proportionally | `RefundService` links to `credit_note_id`/`invoice_id`, computes available credit from collections, invoice claims, standalone credit notes and reserved refunds, enforces maker ≠ checker by name, and un-recognises tax on payment | **OK** |
| Sales (Cancellation) | Inventory (Reservation) | SL-11, F-15 | Cancelling an uncommitted document releases its reservations; availability rises immediately | `InventoryOperationService::cancel` → `releaseOperation`; cancel after dispatch correctly refuses to restore source stock | **OK** |
| Sales (Quotation validity) | Sales (Pipeline) | SL-02, SL-16, F-18 | Expiry is enforced on decision **and** derived for display, so a stale offer never looks live | `sales:quotations:expire` is scheduled daily; `ExpireQuotationsCommand` sweeps every `Sent` quotation past `expires_at` and calls `QuotationService::expire()` | **OK** (resolved 2026-09-12 — GAP-BW-07) |

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
| Purchasing (Bill) | Accounting (Payable) | PU-09, AC-07, F-21 | Approval recognises the payable; the supplier's own invoice number is unique per supplier — the **primary duplicate-payment control** | `normalizeSupplierReference()` now throws `SupplierReferenceRequired` on a blank reference; a DB-level unique index on `supplier_reference` closes the concurrent-write race, with the violation caught and logged | **OK** (resolved 2026-09-12 — GAP-BW-05) |
| Purchasing (PO) | Accounting (Ledger) | PU-02, AC-16 | A purchase order commits money but creates **no** accounting entry | Verified: `JournalPostingService` has no Purchasing caller | **OK** |
| Accounting (Payment) | Purchasing (Bill state) | PU-10, AC-08, F-21 | Supplier payment allocates across bills, debits payable control, credits the method's account, advances each bill's state | `AccountingDocumentService::paySupplierPayment` posts the entry with remaining-balance and same-supplier guards, then recomputes bill status | **OK** |
| Inventory (Supplier return) | Purchasing (Debit note) | IN-15, F-08 | The physical return is matched by an explicit supplier credit or debit note, linked to it | `SupplierDebitNoteService::createForReturn` derives lines via `InventoryReturnLine::originalOperationLine` → `purchase_order_line_id` → matching `BillLine`, throwing a `DomainException` on any missing hop; `confirm()` posts Dr Payable/Cr GRNI/Cr Input-Tax via `JournalPostingService::postNew()`, writes a `TaxRecognitionEntry`, and increments `Bill.supplier_credit_total`. Tested in `SupplierDebitNoteServiceTest` | **OK** (ADR 0015) — creation/confirmation is a deliberate, permission-gated Filament action (`ViewReturn`'s `selectSupplierOutcome`/`createSupplierDebitNote`, `ViewSupplierDebitNote`'s confirm/reverse), not an automatic consequence of posting the return; `awaitingSupplierCreditQuery()` is a reporting query only, not an automated alert |

---

## 3. CRM ↔ Sales

Flows F-17 (campaign to first sale), F-11 (below-floor approval), F-04 (AI opportunity).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| CRM (Campaign) | CRM (Lead) | CR-06, CR-01, F-17 | A campaign builds a recipient list, records per-recipient sends and responses, and interested responses become leads attributable back to the campaign | `Campaign`, `CampaignRecipient`, `CampaignResponse`, `Lead`, `Interaction` models exist with real services (`CampaignService`, `CampaignDispatchService`, `CampaignResponseService`, `LeadService`); `crm:campaigns:dispatch-due` is scheduled every minute | **OK** (resolved 2026-09-12 — GAP-MW-01) |
| CRM (Lead) | CRM (Customer) | CR-03, MD-01, F-17 | Conversion carries the lead's interaction history onto the customer and marks the lead converted, pointing at what it became | `LeadConversionService` exists alongside `CustomerOnboardingService::register` | **OK** (resolved 2026-09-12 — GAP-MW-01) |
| CRM (Opportunity) | Sales (Quotation) | CR-04, SL-01, F-04 | An opportunity with a party, value, stage and expected close date produces a quotation carrying its summary forward | `voice_note_transcription_id` is now nullable; `sales_opportunities` gained `customer_id`, `lead_id`, `title`, `estimated_value_minor`, `expected_close_date`, `stage`, `owner_id`, `close_reason`, `close_note`. `OpportunityService::create()` requires only a customer or a lead | **OK** (resolved 2026-09-12 — GAP-MW-02) |
| CRM (Pricing tier) | Sales (Quotation line) | MD-12, PM-04, F-11 | Deterministic, non-stacking resolution; the floor is a hard block with a logged System Admin override | `PriceResolver` resolves customer-specific → lowest product-scoped → general → base with an id tie-break; `assertAtOrAboveFloor` blocks; `price_floor_overrides` records the approval | **OK** |
| CRM (Customer) | All transacting modules | CR-05, MD-01 | One customer, one timeline — quotations, orders, invoices, payments, tickets, visits and maintenance visible from the customer record | `CustomerProfile` now declares relations to leads, opportunities, quotations, orders, invoices, payments, credit notes, refunds, tickets, visits, maintenance records, write-offs, and interactions; `CustomerTimelineService` aggregates them into a Filament UI tab | **OK** (resolved 2026-09-12 — GAP-UI-03) |
| CRM (Funnel) | Accounting (Revenue) | CR-07, SL-15, F-17 | The funnel is traceable to invoiced and collected revenue, not just to "interested" | `CrmFunnelReportService` and `SalesReportService` (with `SalesReportType::{QuotationFunnel,WinLossAnalysis,ConversionVelocity}`) now trace the funnel through to invoiced/collected revenue | **OK** (resolved 2026-09-12 — GAP-MW-01, GAP-MW-17) |

---

## 4. Support ↔ Maintenance ↔ Inventory ↔ Accounting

Flows F-06 (chargeable ticket to service revenue), F-07 (warranty service at zero revenue), F-12
(serialised device life).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Support (Ticket) | Maintenance (Request) | SU-08, MT-01, F-06 | The ticket raises a maintenance request carrying customer, product, serial and problem; the two stay linked in both directions | `MaintenanceRecordService::createFromTicket` links the record to the ticket, and `TicketLifecycleService` blocks resolution while a non-terminal maintenance record is open | **OK** |
| Maintenance (Request) | Inventory (Serialised unit) | MT-01, PM-10, F-12 | Linking to a serialised unit happens only on a real identifier match; warranty status is explicit and never guessed | `resolveEquipmentAndWarranty()` validates the serialised unit and throws on mismatch; `WarrantyStatus` includes an explicit `Unknown` | **OK** |
| Maintenance (Service record) | Inventory (Consumption) | MT-03, IN-12, F-07 | Parts decrease stock through the canonical posting path, with allocations for tracked variants, and each consumption is paired with the movement it produced | `ServiceRecordPartService::consume` posts `MovementType::ServiceConsumption` through `InventoryPostingService` with tracking allocation; `reverse()` posts the compensating movement | **OK** |
| Inventory (Consumption) | Maintenance (Job cost) | MT-05, F-07 | The part's cost attaches to the service record, building the job's cost alongside labour and third-party cost | `service_record_parts` now carries `unit_cost_minor`/`total_cost_minor`; `maintenance_labour_entries` and `maintenance_third_party_costs` tables exist; `MaintenanceCostService::jobCost()`/`marginFor()` compute cost and margin, including nil-revenue warranty jobs | **OK** (resolved 2026-09-12 — GAP-MW-09) |
| Maintenance (Completed work) | Sales (Invoice) | MT-06, F-06 | Completed chargeable work produces a quotation or invoice and follows the standard invoice → collection → tax path | `MaintenanceRecord` now links `quotation_id`/`invoice_id`; `MaintenanceBillingService::createQuotation()`/`createInvoice()` delegate to the same `QuotationService`/`InvoiceService` Sales uses, following the standard tax-recognition path | **OK** (resolved 2026-09-12 — GAP-MW-10) |
| Support (Ticket payment) | Accounting (Ledger + Tax) | SU-02, MT-06, F-06 | Ticket revenue is recognised through the **same** invoice, collection and tax machinery as goods revenue | `TicketPaymentService::settle()` calls `InvoiceService::createStandalone()` then `issue()` (posts Dr Receivable/Cr Revenue/Cr Deferred Tax via `InvoicePostingService`, no branching on invoice origin), then `PaymentService::createDraft()`/`post()` with allocation — `PaymentAllocationService::allocate()` and `TaxRecognitionService::recognise()` run exactly as for any other payment. `ticket_payment_links` carries `invoice_id`/`payment_id`. Tested in `TicketPaymentTest` | **OK** (ADR 0014) — cash, ledger and tax register all see it through the same machinery as goods revenue |
| Maintenance (Schedule) | Maintenance (Due request) | MT-07 | Serviceable equipment carries an interval; due requests are raised in advance; a missed service is visible as missed | `MaintenanceSchedule`/`MaintenanceScheduleOccurrence` carry interval/lead-time/next-due; `GenerateMaintenanceSchedulesCommand` (`maintenance:schedules:generate`) is scheduled daily and marks missed occurrences | **OK** (resolved 2026-09-12 — GAP-MW-08) |

---

## 5. Employees ↔ CRM ↔ Sales ↔ Payroll

Flows F-04 (AI opportunity), F-20 (field sale from a van warehouse), F-14 (month-end).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Employees (Voice note) | AI (Transcription) | EM-05, EM-06, F-04 | Failure never blocks visit completion; confidence carries an explicit provenance label and is never fabricated | `TranscribeVoiceNoteJob` records `VoiceNoteStatus::Failed` and changes nothing else; `TranscriptionConfidenceSource` distinguishes `ProviderReported` / `DerivedFromLogProb` / `Unavailable` | **OK** |
| AI (Transcription) | CRM (Opportunity draft) | EM-07, F-04 | The draft names the transcript and the matched rules; no AI output takes effect without a recorded human decision; a rejected draft is retained as evidence | `voice_note_transcription_id` is now `nullOnDelete()` (not cascade), with an `origin_summary` snapshot backfilled from the transcript so the human decision survives transcript deletion | **OK** (resolved 2026-09-12 — GAP-BW-08) |
| Employees (Field) | Employees (Visit capture) | EM-03, EM-04, F-20 | The employee checks in with GPS, records the visit, and checks out; captured data is not editable by a reviewer | `EmployeeChannelController::checkIn`/`checkOut` → `EmployeeChannelService` → `EmployeeVisitFieldService` writes real `VisitGpsLog` rows with lat/long over Sanctum-authenticated `/v1/employee/*` routes — the capture channel exists | **OK** (ADR 0012) — GPS-verified check-in can now be captured |
| Employees (Visit) | Sales (Quotation / Delivery) | SL-14, F-20 | A field sale obeys the same floor controls, availability checks, reservation and posting rules; the van is a warehouse | `EmployeeChannelController::createVanSale` → `EmployeeVanSaleService::create` runs the identical `OrderFulfillmentService`/`InventoryOperationService`/`InvoiceService` pipeline Filament uses — not a parallel implementation | **OK** (ADR 0012) — F-20 runs through the canonical pipeline |
| Employees (Visit / Task) | Payroll (Score) | EM-09, EM-10, F-14 | Scoring is deterministic and reproducible, with a full breakdown snapshotting weights, thresholds and inputs so a later edit cannot rewrite history | `PerformanceScoringService` computes from task status, executed visits, due dates and check-in/out durations, storing the full breakdown; `SalaryCalculationService` copies the payable base onto the calculation, and correction is supersession rather than edit | **OK** |
| Payroll (Score) | Payroll (Salary) | EM-10, EM-11 | Only approved bonuses contribute; confirmation and calculation are separate permissions | `SalaryCalculate` and `SalaryConfirm` are distinct; `BonusApprovalService` gates contribution on approval; supersession chain preserved | **OK** |

---

## 6. Inventory internal seams

Flows F-09 (inter-warehouse transfer), F-10 (lot recall and expiry write-off), F-16 (cycle count).

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Inventory (Warehouse A) | Inventory (Warehouse B) | IN-05, F-09 | Dispatch removes source custody into in-transit; receipt adds destination custody; lot and serial identity survive; discrepancies are dispositioned, never absorbed | `dispatch()` → `transferOut` + `InTransit`; `receiveTransfer()` → `transferIn`, holding `PartiallyReceived` until filled; `TransferDiscrepancyDisposition` (`Shortage` / `Damaged` / `Cancelled`) raises alerts | **OK** |
| Inventory (Balance) | Inventory (Movement ledger) | IN-01…IN-16, XC-07 | Every stock-changing operation writes its movement in the same transaction; there is exactly one posting path | `InventoryBalanceService` is the sole stock writer and is reachable only through `InventoryPostingService`; nine service callers; enforced by `ArchTest` and `InventoryDomainContractTest` | **OK** — the architectural spine of the system |
| Inventory (Saleable) | Inventory (Quarantine) | IN-14, IN-01 step 3 | Stock enters quarantine and is dispositioned out by a named inspector: release, downgrade, dispose, or return to supplier | `InventoryConditionChangeService::draftQuarantineDisposition()` moves stock out of `Quarantine` to `Saleable`, `Damaged`, `Disposed`, or back to the supplier via `InventoryReturnService` — all four dispositions | **OK** (resolved 2026-09-12, Phase 5 WP-5.2 — GAP-MW-03) |
| Inventory (Damage) | Inventory (Recovery / Disposal) | IN-07, IN-08, IN-09 | Each condition change is a document with a cause and an actor; recovery is a **new linked document** referencing the original damage; disposal carries evidence | Damage/recovery/disposal post through `InventoryConditionChangeService` as an `InventoryConditionChange` document, surfaced by a full Filament resource; recovery references the original damage via `reverses_condition_change_id`; disposal requires a distinct authoriser and at least one evidence attachment | **OK** (resolved 2026-09-12, Phase 5 WP-5.2 — GAP-UI-06) |
| Inventory (Count) | Inventory (Adjustment) | IN-06, F-16 | A scoped count records counted quantity at lot and serial identity; variance is valued; a **separate person** confirms | `InventoryCountService::open()` scopes a count (category/lot/variant-set) and `generateLinesForVariant()` builds a lot/serial worksheet with computed variance; `confirm()` on `InventoryAdjustmentService` now rejects same-actor create+confirm via `SelfConfirmationRejected` | **OK** (resolved 2026-09-12 — GAP-MW-06, GAP-WL-02, GAP-WL-03) |
| Inventory (Posted document) | Inventory (Correction) | IN-16 | Every post-commit change is a new document referencing the original — for receipts, deliveries and transfers alike | `InventoryCorrectionType` now has `Receipt`, `Delivery`, and `Transfer` cases, each with its own correction/posting flow in `InventoryCorrectionService` | **OK** (resolved 2026-09-12 — GAP-BW-02) |
| Inventory (Reservation) | Inventory (Availability) | IN-03, IN-18, F-15 | A reservation is consumed, released, or expires exactly once; availability rises immediately on release | Consume and release-by-cancellation run correctly from `InventoryOperationService`. `expire()` is scheduled hourly via `inventory:reservations:expire`; `release()` is reachable through a permissioned, audited row/bulk action on `InventoryReservationsTable`, which also links each reservation to its source document | **OK** (resolved 2026-09-12, Phase 5 WP-5.1 — GAP-MW-04, GAP-MW-05, GAP-UI-01) |
| Inventory (Lot) | Inventory (FEFO / expiry) | IN-10, F-10 | Lots ordered by nearest expiry; expiring lots surface as a work queue and alerts; expired stock is non-saleable, with an audited override | `InventoryLotService` (`assertReservable`, `availableLots`), `InventoryAlertType::Expiry` and `ExpiredStockReleased`, `ExpiredStockOverride` permission, `inventory:alerts:reconcile` daily | **OK** |

---

## 7. Cross-cutting seams

Flows F-14 (month-end close) and the XC scenarios that every other flow depends on.

| From Module | To Module | Scenario | Expected | Current | Gap |
|---|---|---|---|---|---|
| Every module | Audit trail | XC-02 | Every financial, inventory, pricing, payroll and access change records actor, record, time, channel and before/after values | `InventoryOperationService` now logs `markReady`, `dispatch`, `complete`, and `receiveTransfer` through a shared `logOperationActivity()` helper, alongside the existing `cancel` entry — every lifecycle transition is audited, not just cancellation | **OK** (resolved 2026-09-12 — GAP-BW-06) |
| Every module | Notification | XC-03 | Invoice issued, payment received, task assigned, SLA at risk, stock low, lot expiring, approval pending — delivered per party per channel, every attempt logged and retried | The engine lives at `app/Services/Notifications/`: `NotificationDispatcher` logs one `NotificationDelivery` row per attempt with retry (capped at 3); scheduled commands fire real, deduplicated reminders for overdue invoices, expiring lots, pending approvals, and visits due | **OK** (resolved 2026-09-12 — GAP-MW-12) |
| Sales | Reporting | SL-15, XC-04 | Win rate, conversion velocity, **delivered-not-invoiced**, **invoiced-not-collected**, tax position, margin, discount and floor-override incidence | `SalesReportResource` + `SalesReportService` now cover all of it, including `DeliveredNotInvoiced` and `InvoicedNotCollected`; `ExportsSalesDocuments` adds CSV export to every sales resource | **OK** (resolved 2026-09-12 — GAP-MW-17, GAP-UI-05) |
| Accounting (AR) | Reporting | AC-05, F-14 | Per customer: invoiced, collected, credited, outstanding; **aged**; reconciled to the receivable control account as displayed proof | `ListAccountsReceivable` now calls `AccountsReceivableService::aging()`, `reconciliation()`, `customerDetail()`, `toCsv()`, and `statement()` — matching AP's capability set one-to-one | **OK** (resolved 2026-09-12 — GAP-UI-02) |
| Tax | Reporting | AC-06, MD-07, F-14 | Per period: tax deferred on issuance, recognised on collection, reversed by credits and refunds, input tax on approved bills — reconciling to both tax accounts | `Taxes/Pages/ViewTaxRegister.php` now provides period grouping, deferred-vs-payable summary, reconciliation to both tax accounts, and CSV export, alongside the original `ListTaxes` trace | **OK** (resolved 2026-09-12 — GAP-UI-04) |
| Inventory | Reconciliation proof | IN-17, F-14 | Aggregate = sum of lots; reserved = sum of allocations; serialised custody agrees; the ledger replays to the balance — proved continuously, divergence shown as an error | `inventory:lots:reconcile --scheduled` runs daily (incremental) and `--full` weekly; `InventoryReportType::Reconciliation` surfaces it as a report with a "run reconciliation" UI action | **OK** (resolved 2026-09-12 — GAP-MW-16, GAP-BW-04) |
| Reconciliation | Accounting (Period close) | AC-10, F-14 | A period cannot close while a mandatory reconciliation shows a difference | `FiscalPeriodService::close()` now runs an 8-check `PeriodCloseChecklistService` (trial balance, AR/AP control-account agreement, stock ledger reconciliation, and more) and blocks on failure unless a separately-permissioned, separately-logged override is used | **OK** (resolved 2026-09-12 — GAP-MW-18) |
| Accounting (AR) | Accounting (Bad debt) | AC-13 | An authorised write-off reverses the receivable against bad-debt expense, with reason and approver; the invoice reads written off, never paid | `ReceivableWriteOff` (model + `WriteOffStatus`), `ReceivableWriteOffService`, `WriteOffPostingService`, and a full Filament resource now exist | **OK** (resolved 2026-09-12 — GAP-MW-07) |
| Every module | Customer / Employee channel | SL-13, SL-14, EM-03, SU-01, F-19, F-20 | An in-app decision and a staff-recorded decision produce the **same** business record, so the flow does not fork by channel | `routes/api.php` defines Sanctum-authenticated `/v1/customer/*` and `/v1/employee/*` routes; `CustomerChannelService`/`EmployeeChannelService` call the same domain services (`PriceResolver`, `QuotationService`, `AccountsReceivableService`, `TicketIntakeService`, `LeadService`, `InteractionService`, `OpportunityService`) Filament pages already use; `ApiArchitectureTest` enforces no direct model access from API controllers. `tests/Feature/Api/ChannelParityTest.php` (added Phase 5 WP-5.0, 2026-09-12) now proves each write-capable endpoint reaches the same business outcome as the domain service invoked directly | **OK** (ADR 0012, parity test added 2026-09-12 — Phase 5 WP-5.0) |

---

## 8. What the matrix shows

**The transactional core is sound**, and has been since the `2026-09-03` baseline. Every seam where a
*document* hands off to another *document* holds: quotation → order → delivery → invoice → payment → tax →
ledger runs end to end with correct locking, correct posting, correct proportional tax recognition, and a
test-guarded invariant that no workflow can change a stock balance without writing its movement. The
purchase-order receipt seam — where over-receipt is rejected under a row lock inside the inventory
transaction, rolling back the stock movement with it — is better than most production ERPs manage.

**The accountability layer around it has since caught up.** At the `2026-09-03` baseline, 27 of the 60
seams were impaired, clustering into four recognisable shapes: evidence that didn't cross between two
individually well-built sides (a return and its credit note, a price and the rule that produced it, a job
and its cost, a damage and its recovery); machinery that was built and never invoked (reservation expiry
and release, the stock reconciliation command); controls documented but not enforced (adjustment
maker/checker, the duplicate-bill guard's blank-reference escape, the unguarded period close); and whole
modules scoped but not built (CRM leads and campaigns, opportunity management, sales reporting,
notifications, bad-debt write-off). GAP-MW-03 — quarantine having an entrance and no exit — was flagged as
deserving separate mention: the only gap where a routine, correct business action put company assets
permanently beyond reach with no in-system remedy.

**As of 2026-09-12, every one of those 27 rows verifies OK** (see the seam health summary above and each
row's own evidence) — most were found already resolved in the source tree during a Phase 5 gap-audit sweep
that treated this document's own prose as a hypothesis to re-verify, not a fact. `BUSINESS_LOGIC_GAPS.md`
carries the full gap-by-gap evidence trail; this document's rows above were updated in place rather than
deleted so the shape of what was once broken, and how each piece closed, stays legible. GAP-WL-05
(drill-through from a stock row to the documents explaining "why is this quantity unavailable") was logged
mid-sweep as only partially closed — the reservation half resolved alongside GAP-MW-04/05, the
quarantine/damage half believed still open — but a second check of `StockAvailabilityExplainer` found that
half already built too: every named cause resolves to its holding documents with a direct link. All 40
gaps in `BUSINESS_LOGIC_GAPS.md` are now resolved.

---

*Companion document:* `BUSINESS_LOGIC_GAPS.md` — gap definitions, impact, priority, and remediation
sequence.

*End of document.*
