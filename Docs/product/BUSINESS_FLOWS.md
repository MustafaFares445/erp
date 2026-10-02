# IERP Canonical Business Flows

---
status: canonical
owner: product
last_verified: 2026-10-02
verified_against: current domain services, event/listener wiring, lifecycle enums and cross-module feature tests
---

## Purpose

This document is the canonical cross-domain workflow map for the implemented IERP system.

It describes how business facts move between domains without making one domain the owner of another domain's data.

For domain-local detail, follow the links to `Docs/domains/*`.

## Cross-Domain Ownership Rules

1. **Origin domain owns its business fact.**
2. **Inventory owns physical stock truth.**
3. **Accounting owns ledger truth.**
4. **Payments owns collection/allocation/provider money-movement behavior.**
5. **Purchasing owns supplier commercial commitments, not stock.**
6. **Sales owns customer commercial documents, not physical custody or the ledger.**
7. **Logistics owns fulfillment/shipment execution around Inventory operations.**
8. **CRM/Support/Employees may initiate downstream work but use the owning domain's services.**
9. **Reports read facts; they do not silently correct them.**
10. **Planned API/mobile behavior is not current runtime behavior until routes/controllers exist.**

## Cross-Domain Event Bridges

The current modular monolith uses synchronous/domain events at several ownership boundaries:

| Event | Producer | Important current consumers/effect |
|---|---|---|
| `SalesOrderReleased` | Sales | Synchronizes Sales procurement requirements from released demand. |
| `InventoryOperationCompleted` (Receipt) | Inventory | Advances PO/inbound received quantities and refreshes Sales procurement coverage. |
| `InventoryOperationCompleted` (Delivery) | Inventory | Moves related planned Shipment to In Transit. |
| `ShipmentArrived` | Shipments | Refreshes Sales order completion window/eligibility. |
| `PurchaseOrderAccepted` | Purchasing first-send activation | Business notification; event carries the provisioned draft Bill. |
| `PaymentReceived` | Payments after posted collection | Business notification. |
| `CustomerDepositApplied` | Payments | Records downstream notification/integration point for deposit application. |
| `OrderClosed` | Sales | Signals customer/system order completion. |

A listener must not make its source domain responsible for the consumer's internal rules.

---

# F-01 - Normal Sale From Stock

## Domain chain

`CRM/Customer -> Sales -> Logistics -> Inventory -> Sales Invoice -> Accounting -> Payments -> Accounting`

## Current sequence

1. A customer/CRM context exists.
2. Sales creates a Quotation or a direct Sales Order.
3. Quotation path: Draft -> Sent -> Accepted -> Convert.
4. Conversion creates a **Confirmed** Sales Order.
5. Sales releases the order.
6. Logistics plans fulfillment against available stock.
7. Inventory delivery is prepared.
8. Inventory Ready reserves stock but does not reduce on-hand.
9. Inventory completion removes warehouse custody.
10. `InventoryOperationCompleted` moves the related Shipment from Planned to In Transit.
11. Shipment arrival is confirmed by customer/admin/system.
12. Sales creates an Invoice from completed delivery/deliveries where applicable.
13. Sales issues the Invoice.
14. Invoice issue posts the approved invoice accounting entry through Accounting.
15. Payment is recorded/settled and allocated.
16. Payments posts collection/deposit effects and recognizes tax proportionally.
17. Shipment arrival/fulfillment/procurement facts allow the Sales completion window to run.
18. Customer evidence or system auto-close completes the Order.

## Invariants

- Quotation does not move stock or post the ledger.
- Ready delivery does not reduce on-hand.
- Completed delivery changes stock exactly once.
- Invoice state and financial/payment state are separate.
- Ledger writes go through `JournalPostingService`.
- Tax recognition follows collection/allocation, not merely invoice issuance.

## Canonical domain docs

- [Sales](../domains/sales/README.md)
- [Logistics](../domains/logistics/README.md)
- [Inventory](../domains/inventory/README.md)
- [Payments](../domains/payments/README.md)
- [Accounting](../domains/accounting/README.md)

---

# F-02 - Stock-Shortage Sale / Back-to-Back Procurement

## Domain chain

`Sales -> Inventory availability -> Sales procurement requirement -> Purchasing -> Inventory receipt -> Sales procurement -> Logistics -> Inventory delivery`

## Current sequence

1. Sales Order is Released.
2. `SalesOrderReleased` triggers procurement-requirement synchronization.
3. Sales compares remaining demand with available Inventory.
4. Uncovered base quantity becomes an open `SalesProcurementRequirement`.
5. Purchasing selects an eligible supplier with active product reference in the selected currency.
6. Purchasing creates PO draft(s) linked one-to-one to the open requirement(s).
7. PO follows submit/approve/first-send lifecycle.
8. First Send provisions inbound execution and Accounting draft Bill.
9. Purchasing allocates inbound quantity to warehouse(s).
10. Purchasing opens Inventory receipt(s) against those allocations.
11. Inventory completion posts physical received stock.
12. `InventoryOperationCompleted` synchronously:
    - validates allocation/PO ceilings;
    - updates PO received quantity/status;
    - updates inbound status;
    - refreshes replenishment coverage;
    - refreshes Sales procurement fulfillment.
13. Newly available stock can then be planned into outbound fulfillment.

## Failure/re-source behavior

If a PO is cancelled or short-closed, Sales preserves the historical requirement as superseded and requeues only the outstanding quantity for another sourcing attempt.

## Invariants

- Purchasing cannot over-receive a PO line or warehouse allocation.
- Draft/Ready receipt is not a physical receipt.
- Inventory alone posts received stock.
- Failed supplier attempts remain auditable.

---

# F-03 - Customer Return -> Inventory Return -> Credit / Refund

## Domain chain

`Customer/CRM -> Inventory -> Sales Credit Note -> Accounting/Payments`

## Current sequence

1. Customer return request must reference the customer's own completed delivery.
2. CRM request: Submitted -> Under Review -> Approved.
3. Approval alone does not move stock.
4. CRM converts the approved request into a **Draft Inventory Return**.
5. Inventory validates quantities against what was actually delivered/returnable.
6. Inventory Return is inspected/dispositioned and posted.
7. A posted return can be linked explicitly to a Sales Credit Note using:
   - `inventory_return_id`;
   - return-line provenance where goods were returned.
8. Credit Note confirmation validates that invoice value/quantity has not already been over-credited.
9. Credit Note accounting/tax effects are posted through Accounting.
10. Where money must leave the business, an Accounting Refund is approved and paid.
11. Provider refund execution, when Stripe is used, is delegated to Payments and only settles the ERP Refund after provider success.

## Stock-consequence rule

A Credit Note explicitly records its stock consequence. Financial credit does not itself imply a stock receipt.

## Invariants

- CRM conversion into Inventory Return does not move stock.
- Inventory decides physical return eligibility/disposition.
- Credit cannot exceed remaining uncredited invoice quantity/value.
- Refund availability cannot exceed customer credit/deposit/source credit.
- Return, credit and cash refund remain separately auditable facts.

---

# F-04 - Employee Visit -> Voice/AI -> Opportunity

## Current implemented backend chain

`Employees Visit -> Voice Note -> Transcription -> Keyword detection -> reviewable Sales Opportunity evidence`

1. Employee/visit context exists.
2. Voice note enters the Employees intake service.
3. Transcription runs through the transcriber contract.
4. Current implementations include OpenAI Whisper and a fake test transcriber.
5. Transcript success/failure is recorded independently from Visit status.
6. Keyword detection derives opportunity evidence.
7. Opportunity review is an explicit human-review step.

## Current exposure status

The backend/domain workflow exists and is tested.

The active Employee Visit/AI API plan describes future mobile API exposure. The current runtime has no `api/*` routes, so this flow must **not** be documented as a currently exposed employee-mobile API.

---

# F-05 - Partial Payment and Proportional Tax Recognition

## Domain chain

`Sales Invoice -> Payments -> Accounting`

1. Sales issues Invoice and posts receivable/revenue/deferred-tax accounting.
2. Payment is created.
3. Payment allocations target issued invoices of the same customer.
4. Payment posts:
   - debit collection account;
   - credit AR for allocated amount;
   - credit Customer Deposits for unallocated remainder.
5. Each allocation recognizes the proportional tax amount.
6. Final settling allocation absorbs rounding residue.
7. Invoice financial status synchronizes independently of document status.

## Invariants

- Allocation cannot exceed outstanding balance.
- Same payment cannot allocate twice to the same invoice.
- Confirmed credits reduce the tax claim that can ever become payable.
- Tax register and journal remain reconcilable.
- Reversal unwinds allocation/tax/deposit accounting unless later credit/refund dependencies block it.

---

# F-06 - Chargeable Support / Maintenance to Service Revenue

## Domain chain

`Support -> optional ticket Payment -> Sales Quotation/Invoice -> Payments -> Accounting`

There are multiple supported charging paths.

## Customer-responsibility quotation path

1. Ticket/maintenance is triaged and work/coverage assessed.
2. Support computes customer responsibility.
3. `MaintenanceBillingService` creates a Sales Quotation.
4. Customer quotation must be decided.
5. Final invoice is blocked if it exceeds the latest accepted quotation amount.
6. Support creates a standalone Sales Invoice linked back to the maintenance record.
7. Sales/Payments/Accounting own invoice/payment/ledger behavior.

## Pre-settled ticket-fee path

1. Ticket Payment Link is settled.
2. The related Payment must already be Posted.
3. Closed maintenance is marked Ticket Settled.
4. Support creates and issues the service Invoice.
5. Existing posted customer deposit/payment is applied to the Invoice.
6. The resulting Invoice must be fully settled or the transition is rejected.

## Invariants

- Support does not create a parallel invoice/ledger system.
- Customer responsibility may not silently exceed accepted quoted responsibility.
- Settled ticket money must map to a posted Payment before being treated as recovered revenue.

---

# F-07 - Warranty / Covered Service at Zero Customer Revenue

## Domain chain

`Shipment -> Support warranty entitlement -> Maintenance -> coverage decision -> optional supplier recovery`

1. Shipment reaches Arrived.
2. Shipment service activates eligible warranty entitlement(s).
3. Later service/maintenance resolves warranty coverage for the relevant equipment/serial.
4. Diagnosis and coverage decision are recorded.
5. Fully covered/goodwill/third-party/service-contract decisions can result in a covered billing classification with no customer responsibility.
6. Third-party/supplier recovery can be tracked separately as a Warranty Recovery claim.
7. If a previously covered case must become customer-paid, an explicit audited reclassification returns it to billable state.

## Invariants

- Arrival/eligibility activates entitlement; a ticket does not fabricate warranty.
- Customer billing and supplier/third-party recovery are separate facts.
- Reclassification requires an explicit reason.

---

# F-08 - Supplier Return

## Implemented physical chain

`Purchasing receipt provenance -> Inventory supplier return -> supplier custody`

1. Inventory creates a Supplier Return tied to supplier and optional completed receipt provenance.
2. Return line quantity cannot exceed still-returnable quantity from the source receipt.
3. Lot/serial/source-condition requirements are enforced.
4. Posting removes eligible warehouse stock and records supplier custody/provenance.

## Current accounting status

The physical supplier-return workflow is implemented.

The current codebase does **not** expose a dedicated `SupplierCredit` model/service found during the 2026-10-02 audit. Therefore the old documentation's combined â€œsupplier return + supplier creditâ€ flow overstates the implemented accounting side.

Until a supplier-credit document/workflow exists, canonical docs must describe supplier return as an Inventory/Purchasing provenance flow and document any AP adjustment only where an actual Accounting implementation exists.

---

# F-09 - Inter-Warehouse Transfer

## Domain chain

`Inventory only, with downstream availability consequences`

1. Create Internal Transfer.
2. Ready reserves source quantity.
3. Dispatch removes source custody and sets In Transit.
4. Destination records actual received quantity.
5. Partial receipt -> Partially Received.
6. Any unreceived quantity requires discrepancy disposition/reason.
7. Destination receives only what was physically confirmed.
8. When all lines are settled -> Done.

## Invariants

- Source stock leaves at dispatch, not at Ready.
- Destination stock arrives only on receipt.
- Serialized units are received atomically.
- Cancellation after dispatch restores source custody through compensating history.

---

# F-10 - Expiry / Quarantine / Disposal

## Implemented chain

`Inventory lot/condition -> block/alert -> explicit override or condition/disposition workflow -> posting`

Current implementation includes:

- lot expiry tracking;
- expired-stock operational blocking;
- explicit expired-stock override permission/audit;
- quarantine/damage/recovery/disposal condition-change workflows;
- supplier-return disposition where that is the selected path.

## Scope correction from legacy docs

The old flow title â€œLot recall and expiry write-offâ€ is broader than the current evidence. The code clearly supports expiry/condition/disposal controls; a distinct product-recall business workflow was not identified in the audit.

---

# F-11 - Below-Floor Price Approval

## Domain chain

`Catalog/Pricing -> Sales`

1. Catalog/Pricing resolves candidate selling price.
2. Floor is checked.
3. A below-floor commercial price cannot silently pass.
4. Explicit `PriceFloorOverride` approval captures the exception.
5. Orders/Invoices retain the linked override/approver provenance.

Shared business constraints also govern pricing-tier discount ceilings.

---

# F-12 - Serialized Product Sale -> Shipment -> Warranty / Service History

## Domain chain

`Catalog -> Inventory serialized custody -> Sales/Logistics -> Shipment -> Support Warranty -> Service`

1. Serialized variant is stocked with unique serial identity.
2. Delivery uses serialized Inventory rules and custody changes.
3. Shipment arrival confirms customer-side delivery.
4. Warranty entitlement is activated for eligible serialized equipment.
5. Later Support maintenance/service references the equipment/entitlement.
6. Parts/service/warranty decisions remain linked through their own domain records.

Serialized custody is Inventory-owned; warranty/service semantics are Support-owned.

---

# F-13 - Overpayment / Customer Deposit -> Later Application or Refund

## Domain chain

`Payments -> Accounting -> later Sales Invoice or Refund`

1. Posted Payment may exceed its invoice allocations.
2. Unallocated amount is credited to Customer Deposits.
3. Later eligible Invoice can consume the deposit via explicit allocation.
4. Applying deposit transfers value from Customer Deposits to AR and runs tax recognition.
5. Alternatively, available unallocated customer deposit can support an Accounting Refund.
6. Approved deposit refund debits Customer Deposits and credits the collection/cash account when paid.

## Invariants

- Deposit is not revenue by itself.
- Refund reserves/availability checks prevent paying the same credit twice.
- Later payment reversal is blocked where refund/credit dependencies would double-unwind money.

---

# F-14 - Period / Month-End Close

## Domain chain

`Accounting reports/checklist -> Fiscal Period`

1. Open Fiscal Period exists.
2. Period-close checklist runs and persists evidence.
3. Mandatory failure blocks close.
4. Authorized override requires separate permission and written reason.
5. Close is audited.
6. Once closed, journal posting/reversal cannot be dated into that period.
7. Reopen is explicit and audited.

Financial reports remain read-only; they surface facts rather than repairing them.

---

# F-15 - Cancelled Sales Order / Released Demand

## Current behavior

1. Draft/Confirmed/Released non-terminal Sales Order may be cancelled only through Sales authorization.
2. Cancellation requires a reason.
3. Sales refuses cancellation while non-cancelled delivery execution exists.
4. Fulfillment/reservation cleanup must occur through Logistics/Inventory owning workflows first.
5. Procurement attempts already created for a released order remain historically attributable; supply requeue/cancellation follows procurement-specific services.

The Sales cancellation action does not directly edit Inventory balances.

---

# F-16 - Physical Count -> Variance -> Inventory Correction

1. Open Inventory Count against selected scope.
2. Snapshot system quantities.
3. Record counted quantity.
4. Request recount / accept variance where applicable.
5. Submit for review.
6. Confirm.
7. Approved variance becomes explicit Inventory correction/posting history.
8. Reconciliation/reporting reflects the resulting facts.

Never rewrite prior stock movements to â€œmake the count match.â€

---

# F-17 - Campaign -> Lead/Customer -> Sale

## Domain chain

`CRM Campaign -> Notifications -> CRM response/lead -> Customer -> Sales`

1. Campaign created.
2. Recipients built.
3. Campaign scheduled/queued.
4. Notifications dispatch per configured channel/template/preferences.
5. Responses can be attributed.
6. CRM lead progresses New -> Contacted -> Qualified.
7. Lead converts into customer context or is disqualified.
8. Approved customer can enter quotation/order Sales flows.

CRM reporting may attribute downstream revenue but does not own the Sales documents.

---

# F-18 - Quotation Expiry / Re-Quote

1. Quotation is Sent with validity.
2. Unaccepted quotation may expire.
3. Expired/rejected/etc. quotation does not create an order.
4. Re-quote creates a new quotation with provenance to the prior quotation.
5. Only an Accepted eligible quotation can convert to an order.
6. Conversion is one-time.

---

# F-19 - Direct Customer Ordering

## Implemented backend policy

The code supports:

- `CustomerOrderingPolicyService::canDirectOrder()`;
- customer-level `allow_direct_orders`;
- direct-order line pricing service/observer;
- Sales Order creation/confirmation/release services.

A customer must be approved and explicitly allowed for direct ordering.

## Exposure status

The current runtime has no customer `api/*` route surface. Therefore the legacy â€œcustomer self-service direct orderâ€ is **not an implemented end-to-end customer API flow yet**.

Document it as backend capability/future app integration until the customer API exists.

---

# F-20 - Field Sale From Van Warehouse

No dedicated van-warehouse or field-sale workflow was found in the current implementation audit.

Employee profiles may represent field-sales staff, but that does not establish a van-stock/field-sale transaction flow.

The old F-20 flow is therefore **not canonical current behavior**.

---

# F-21 - Procure to Pay

## Domain chain

`Purchasing -> Inventory -> Accounting -> Supplier Payment`

1. Create PO Draft and lines.
2. Submit / approve according to threshold and maker-checker rules.
3. First Send activates downstream work.
4. Purchase Inbound is created.
5. Optional supplier confirmation is requested.
6. Accounting Draft Bill is provisioned.
7. Inbound quantities are allocated to warehouse(s).
8. Inventory receipt operation is created.
9. Inventory completion posts stock.
10. Completion event advances inbound and PO received status atomically with validated provenance/over-receipt guards.
11. Bill follows Accounting approval/AP lifecycle.
12. Supplier payment follows Accounting payment/allocation/posting lifecycle.

## Invariants

- PO approval is not physical receipt.
- First Send is downstream activation.
- Stock is not posted by Purchasing.
- Supplier Bill/AP is not posted by Purchasing.
- Cancellation is blocked once physical receipt/open receipt/active bill constraints make it inconsistent.
- Supplier reference uniqueness and payment allocation remain Accounting-owned safeguards.

---

# Additional Canonical Cross-Flow Rules

## Idempotency / duplicate action resistance

The code/tests explicitly guard repeated high-impact operations such as:

- completing the same delivery twice;
- converting the same accepted quotation twice;
- settling the same provider transaction twice;
- confirming the same Credit Note twice;
- provider refund retries.

## Transaction boundaries

High-impact cross-domain transitions use database transactions and row locks so provenance/state/financial or stock changes either commit consistently or fail together.

## UOM snapshots

Commercial and physical documents retain transaction/base-UOM snapshots where later catalog changes must not rewrite historical meaning.

## Corrections instead of destructive edits

Posted stock and accounting facts use explicit correction/reversal documents. Historical facts are not silently overwritten.

## Permission isolation

A user's permission in one domain must not leak into another. Cross-module permission-leak tests exist for Inventory, Purchasing, Sales, Employees and Support.

## Current API boundary

As of 2026-10-02, the route table exposes no `api/*` routes. Employee/customer mobile/API plans and service capabilities must remain clearly marked planned/not-exposed until implemented.
