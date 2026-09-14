# Purchase Order End-to-End Workflow Implementation Plan

**Project:** IERP  
**Target baseline:** current `dev` working tree as inspected on 2026-09-14  
**Source specification:** `PURCHASE_ORDER_WORKFLOW_UI_SPEC(1).md`  
**Primary modules:** Purchasing → Inventory/Logistics → Accounting → Payments  
**Plan type:** implementation plan only; no production code is changed by this document.

## 1. Goal

Implement the Purchase Order as one business workspace without turning it into a cross-module god object.
The user must be able to understand what was ordered, what the supplier committed to, where goods will arrive,
what has been received, what remains, the bill/match/payment position, exceptions, and the next valid action.

The final experience must be workflow-driven rather than CRUD-driven:

```text
Draft → Approval → Accepted → Supplier Confirmation → Warehouse Allocation
      → Goods Receiving → Bill Matching/Approval → Payment → Completed
```

Supplier returns branch from physical receipts and keep physical, commercial, and financial ownership separate.

## 2. Non-negotiable architecture rules

1. `PurchaseOrder` remains the commercial commitment and owns no warehouse.
2. `PurchaseInbound` / allocations own post-acceptance warehouse routing.
3. Inventory stock changes only through canonical Inventory posting/operation services.
4. PO submit/approve/accept and allocation never change stock.
5. Accounting owns Bills, payables, journal entries, supplier payments, tax, credits, and refunds.
6. `sent_at` stays communication metadata; `Accepted` is the activation point.7. Do not duplicate supplier, stock, received quantity, payable, or payment facts merely to simplify the UI.
8. Keep append-only evidence for supplier answers, approvals, posted receipts, returns, and payments.
9. Do not add every cross-module state to `PurchaseOrderStatus`.
10. Build a presentation/workflow projection that derives business milestones from domain-owned facts.
11. Existing multi-warehouse receipt provenance and over-receipt protections must remain intact.
12. Three-way matching is advisory; accounting approval remains the financial control.

## 3. Current baseline found in the repository

### Already implemented and must be preserved

- PO Draft creation is supplier-scoped and does **not** require a warehouse.
- Approval threshold, explicit approval, self-approval protection, rejection, cancellation and short-close exist.
- `Accepted` triggers `PurchaseOrderAcceptanceOrchestrator`.
- Acceptance provisions one `PurchaseInbound` and its lines.
- Multi-warehouse `PurchaseInboundAllocation` with exact base quantities is implemented.
- Receipt lines carry `purchase_inbound_allocation_id` provenance.
- `PurchaseOrderReceivingService` starts receipts from PO/allocation context.
- `AdvancePurchaseOrderOnOperationCompleted` prevents PO and allocation over-receipt under row locks.
- Completed Inventory receipts alone change stock and advance received quantities.
- Partial receiving across one or many warehouses is implemented and tested.
- Acceptance can provision one Accounting-owned Draft Bill with PO-line provenance.
- `BillLine` already exposes ordered/received/billed and price/quantity variance helpers.
- Supplier payments and bill allocations exist in Accounting.
- Physical supplier returns exist through `InventoryReturnService`, including receipt/lot/serial provenance.
- Activity logging, Spatie permissions, and purchasing policies already cover core lifecycle actions.

### Gaps to close

- Supplier confirmations do not store confirmed/backordered quantities.- PO acceptance creates header-only confirmations when supplier confirmation is required.
- Allocation is capped by ordered base quantity, not effective supplier-confirmed quantity.
- No canonical workflow projection produces business milestone, Next Action, or blockers.
- PO UI exposes useful pieces but not one coherent end-to-end workspace.
- PO line UI does not show Ordered / Confirmed / Allocated / Received / Remaining together.
- The PO infolist still references a legacy singular allocation in one place.
- Bills, returns and payments are not summarized as related documents from the PO workspace.
- Warehouse users do not have a dedicated inbound-facing UI stripped of commercial prices.
- Supplier returns have no Replacement / Credit / Refund expected-outcome workflow.
- No `SupplierDebitNote` exists; repository documentation explicitly marks this as ADR-deferred.
- Some older cross-module documentation still describes one-warehouse and Sent-gated behavior.

## 4. Target state model: keep canonical states small

Do **not** expand `PurchaseOrderStatus` into a combined status machine for supplier, inventory, billing and payment.
Keep the canonical PO lifecycle focused on PO ownership, for example:

```text
Draft
Pending Approval
Accepted
Partially Received
Received
Closed
Cancelled
```

Expose richer user-facing milestones through a derived workflow projection:

```text
Draft
Pending Approval
Accepted / Awaiting Supplier Confirmation
Ready for Warehouse Allocation
Awaiting Delivery
Partially Received
Awaiting Supplier Backorder
Fully Received
Billing in Progress
Payment Pending
Completed
Cancelled / Short-Closed
```

The projection must never become a second source of truth in the database.## 5. Phase 0 — Governance, baseline and documentation alignment

### Objective
Freeze the architectural rules before changing code so parallel agents cannot reintroduce superseded behavior.

### Work

1. Treat ADR 0011/0012 and current canonical Inventory posting rules as authoritative.
2. Update `docs/CROSS_MODULE_BUSINESS_FLOWS.md` F-21 to remove the stale "one warehouse" statement.
3. Update F-21 so `sent_at` is communication evidence, not a lifecycle gate.
4. Remove any statement implying receipt overwrites the commercial PO unit cost unless explicitly required by the active ADR.
5. Add a link from F-21 and F-08 to this implementation plan.
6. Record Supplier Debit Note work as an explicit ADR decision gate before implementing Accounting schema.
7. Capture baseline test results before the first migration.

### Validation gate

Run the relevant existing suites and save failures that already exist in the dirty baseline separately from new failures:

```powershell
php artisan test tests/Feature/Purchasing
php artisan test tests/Feature/Inventory/InventoryReturnServiceTest.php
php artisan test tests/Feature/Filament/PurchasingDashboardTest.php
php artisan test tests/Feature/Filament/BillSupplierReferenceControlTest.php
```

Do not mix unrelated dirty-working-tree repairs into this workstream.

## 6. Phase 1 — Itemized supplier confirmation quantities

### Problem

`SupplierConfirmationItem` currently knows `requested_quantity` and an item status but cannot represent
"100 ordered, 70 confirmed, 30 backordered". Therefore the allocation layer cannot enforce supplier commitment.

### Database changes

Create a forward-only migration extending `supplier_confirmation_items` with:

- nullable `purchase_order_line_id` FK → `purchase_order_lines.id`, restrict on delete;
- nullable `requested_base_quantity decimal(20,6)`;
- nullable `confirmed_base_quantity decimal(20,6)`;
- nullable `backordered_base_quantity decimal(20,6)`.Do not store an additional `unavailable_quantity`; derive it as:

```text
unavailable = requested_base - confirmed_base - backordered_base
```

Use service-layer validation for decimal invariants and retain the PO line UOM snapshot as the conversion authority.

### Confirmation semantics

For a PO-linked confirmation item:

- `Pending`: no final quantity response yet.
- `Confirmed`: confirmed quantity equals the requested snapshot quantity.
- `Partial`: confirmed quantity is positive but lower than requested; remainder may be backordered and/or unavailable.
- `Rejected`: confirmed quantity is zero.

Validation:

```text
0 <= confirmed_base <= requested_base
0 <= backordered_base <= requested_base - confirmed_base
confirmed_base + backordered_base <= requested_base
```

`SupplierConfirmationStatus::Partial` must become a valid answered outcome at item level.
Header aggregation stays derived from the child items; a header with pending items remains Pending.

### Append-only revision model

Do not edit an answered confirmation. Add nullable `supersedes_confirmation_id` on `supplier_confirmations`.
An updated supplier promise creates a new confirmation snapshot that supersedes the previous snapshot.
The latest fully answered snapshot is the effective commitment; a newer pending revision is displayed as pending
but does not erase the last answered commitment.

### Service changes

Extend `SupplierConfirmationService` with explicit PO methods such as:

```php
createPurchaseOrderSnapshot(User $actor, PurchaseOrder $order): SupplierConfirmation
answerPurchaseOrderItems(User $actor, SupplierConfirmation $confirmation, array $responses): SupplierConfirmation
createRevision(User $actor, SupplierConfirmation $previous): SupplierConfirmation
```

The accepted PO snapshot must create one item per PO line with PO-line provenance and canonical base quantity.### Acceptance-orchestrator change

When `supplier.requires_confirmation = true`, `PurchaseOrderAcceptanceOrchestrator` must create the itemized
snapshot instead of the current header-only confirmation. When confirmation is not required, no fake confirmation
record is needed; the allocatable cap is the ordered quantity.

### Legacy data/backfill rules

Do not invent detail that historical evidence cannot prove.

- Legacy header `Confirmed` with no items: create line snapshots with confirmed = ordered.
- Legacy header `Rejected` with no items: create line snapshots with confirmed = 0 and unavailable = ordered.
- Legacy header `Pending` with no items: create pending line snapshots only.
- Legacy header `Partial` with no items: flag for manual detail; do not infer quantities.
- Existing item confirmations without PO-line IDs: map only when supplier + variant maps unambiguously to one PO line.
- Ambiguous legacy rows go to a reconciliation report/command; migration must not guess.

### Files expected to change

- `app/Models/SupplierConfirmation.php`
- `app/Models/SupplierConfirmationItem.php`
- `app/Enums/SupplierConfirmationStatus.php`
- `app/Services/Purchasing/SupplierConfirmationService.php`
- `app/Services/Purchasing/PurchaseOrderAcceptanceOrchestrator.php`
- Supplier confirmation factories/migrations/tests
- `SupplierConfirmationActions` and PO confirmation relation UI

### Phase acceptance tests

- full confirmation 100/100;
- partial confirmation 70 confirmed + 30 backordered;
- partial with unavailable remainder;
- full rejection;
- per-item mixed outcomes;
- revision from 70/30 to 100/0 without rewriting history;
- latest pending revision does not erase prior answered commitment;
- base-UOM correctness when PO transaction UOM differs from base UOM;
- legacy header-only backfill cases.

## 7. Phase 2 — Effective supplier commitment resolver

Create one domain service as the only authority for "how much may currently be allocated".### Proposed service

`app/Services/Purchasing/PurchaseOrderSupplierCommitmentService.php`

Per PO line it returns a typed snapshot containing:

- ordered base quantity;
- confirmation required?;
- latest answered confirmation ID;
- newer pending revision ID, if any;
- confirmed base quantity;
- backordered base quantity;
- unavailable base quantity;
- already allocated base quantity;
- already received base quantity;
- currently allocatable base quantity;
- inconsistency/blocker codes.

Rules:

```text
if supplier confirmation is not required:
    commitment_cap = ordered_base
else if no answered snapshot exists:
    commitment_cap = 0
else:
    commitment_cap = latest_answered_confirmed_base

currently_allocatable = max(0, commitment_cap - allocated_base)
```

If a later supplier revision lowers commitment below already allocated or received quantity, preserve historical
allocations/receipts and emit `supplier_commitment_below_committed_quantity`. Never delete evidence automatically.
No new allocation may increase an already over-cap line; reductions toward the cap remain allowed if receipt
commitments are respected.

## 8. Phase 3 — Enforce confirmation-aware warehouse allocation

### Service integration

Inject the commitment service into `PurchaseInboundService`.
Replace the ordered-quantity cap in `assertAllocationFits()` with the effective supplier commitment cap when
confirmation is required. Preserve ordered quantity as the absolute upper bound.

The legacy no-quantity `allocate()` path must allocate only the currently allocatable commitment, not blindly the
full PO quantity. If the cap is zero, return a business exception explaining that supplier confirmation is required.

Do not change receipt posting mathematics: receiving remains capped by the selected allocation.
This preserves the current strong allocation provenance and over-receipt implementation.### Inbound status rule

Do not make `PurchaseInboundStatus` pretend that supplier confirmation is an Inventory state.
For example, if 100 were ordered, 70 confirmed, 70 allocated and 70 received, the canonical PO stays
`PartiallyReceived`; the business projection should say `Awaiting Supplier Backorder`, even if the raw inbound
aggregate still reports an allocation-oriented internal state.

When a later revision confirms the remaining 30, it becomes allocatable and the existing inbound can receive an
additional warehouse split. No second PO and no destructive reopening of old receipts is required.

### Allocation tests

- confirmation required + no answer → allocation blocked;
- 70 confirmed of 100 → total allocation cannot exceed 70;
- split 40/30 across two warehouses succeeds;
- attempt 40/40 fails with a business-friendly limit;
- supplier not requiring confirmation → 100 remains allocatable;
- revision from 70 to 100 exposes 30 additional allocatable quantity;
- revision below already committed quantity flags exception and blocks increases;
- allocation edit cannot move committed receipt provenance to another warehouse.

## 9. Phase 4 — Purchase Order workflow projection

### Purpose

Create a read-only projection that translates canonical facts into UI language. It must not persist duplicate
statuses and must be reusable by Filament, reports and future APIs.

### Proposed data objects

- `app/Data/Purchasing/PurchaseOrderLineProgressData.php`
- `app/Data/Purchasing/PurchaseOrderMilestoneData.php`
- `app/Data/Purchasing/PurchaseOrderBlockerData.php`
- `app/Data/Purchasing/PurchaseOrderWorkflowStateData.php`

### Proposed services

- `PurchaseOrderWorkflowService`: computes facts, milestones and aggregate business stage.
- `PurchaseOrderNextActionResolver`: combines workflow facts with a `User` and permissions.

The workflow service should eager-load required relations in bounded queries rather than execute one query per
line/section.

### Line progress projection

Every PO line must expose:

```text
Ordered | Confirmed | Backordered | Allocated | Received | Remaining | Currently Allocatable
```

`Remaining` is the commercial PO balance (`ordered - received`), while `Currently Allocatable` is constrained by
the latest supplier commitment. They must not be conflated.### Business stage examples

- Draft → `Draft`
- Pending approval → `Pending Approval`
- Accepted + required confirmation unanswered → `Awaiting Supplier Confirmation`
- Confirmed quantity available but unallocated → `Ready for Warehouse Allocation`
- Allocated and no receipt → `Awaiting Delivery`
- Some received and more confirmed quantity remains → `Partially Received`
- All current commitment received but supplier backorder remains → `Awaiting Supplier Backorder`
- Commercial order fully received → `Fully Received`
- Receipt complete + Draft/Approved Bill exists → `Billing in Progress`
- Approved bill with outstanding amount → `Payment Pending`
- Received/short-closed and all relevant bills settled → `Completed`

### Blocker codes

Use stable internal codes with translated business copy, for example:

- `approval_required`
- `supplier_confirmation_required`
- `supplier_revision_pending`
- `supplier_rejected`
- `supplier_commitment_below_allocated`
- `unallocated_confirmed_quantity`
- `warehouse_inactive`
- `receipt_in_progress`
- `billing_quantity_variance`
- `billing_price_variance`
- `supplier_return_outcome_pending`

Blockers are read models, not exception messages copied directly from low-level services.
### Projection query strategy

The workflow service should batch-load supplier confirmations, inbound allocations, active/completed receipt quantities, Bills, payment allocations, and supplier returns. It must not execute one query per PO line or one query per Filament column.

Add tests for every milestone and for milestone precedence conflicts. The projection is a read model only; it must never persist a duplicate workflow status.

## 10. Phase 5 — Purchase Order workflow workspace

### Objective

Rebuild `ViewPurchaseOrder` as the main business workspace. Existing relation managers can remain underneath, but the user should not need to understand technical relationships to operate the PO.

### Header summary

Show:

- PO number and supplier;
- canonical PO status and derived workflow milestone;
- total amount and currency;
- ordered date and expected date;
- latest supplier promised date;
- total ordered / confirmed / allocated / received progress;
- linked Bill status;
- paid and outstanding payable amount.

Do not show a warehouse field on the PO header because warehouse routing belongs to inbound allocations.
### Workflow stepper

Render the major stages as a desktop horizontal / mobile vertical stepper:

```text
Order → Approval → Supplier Confirmation → Allocation → Receiving → Bill → Payment
```

Each step is one of `complete`, `current`, `waiting`, `blocked`, or `not applicable`, derived only from `PurchaseOrderWorkflowService`.

### Next Action panel

Add a prominent panel directly under the summary. Example:

```text
Next action: Allocate 30 confirmed units to a warehouse
Why: Supplier confirmed 100 units; 70 are already allocated.
Owner: Inventory / Logistics
```

Candidate actions include Submit, Approve, Record Supplier Response, Allocate Quantity, Receive Goods, Review Bill Variance, Approve Bill, Record Supplier Payment, and Resolve Supplier Return Claim.

`PurchaseOrderNextActionResolver` must combine business state with the current user's authorization. If the user cannot perform the next action, show the owning role/module rather than hiding the waiting state.

### Blockers panel

Blockers appear before secondary details and include severity, business copy, affected line(s), owning module, and an optional deep link. Never expose raw exception class names.
### Unified line progress

Render one line table with:

| Item | Ordered | Confirmed | Backordered | Allocated | Receiving | Received | Remaining | Action |
|---|---:|---:|---:|---:|---:|---:|---:|---|

Use transaction UOM for the commercial ordered quantity, with a clear base-UOM secondary detail when conversion exists. Never imply that allocated quantity is physically received quantity.

### Workspace sections

Recommended tabs/sections:

1. `Overview`
2. `Supplier Confirmation`
3. `Warehouse Allocation`
4. `Receipts`
5. `Bill & Payment`
6. `Supplier Returns`
7. `Audit Trail`

### PO list upgrades

Add operational columns/filters for workflow milestone, confirmation state, allocation progress, receipt progress, Bill state, payment outstanding, and blocker count. Keep the canonical PO status filter too.

The user should be able to answer from this workspace: what is waiting, why, how much is confirmed, where it is going, what arrived, what remains, whether the Bill is matched/approved, and what is still unpaid.
## 11. Phase 6 — Supplier confirmation UX

### Objective

Make partial supplier responses practical, quantitative, and append-only.

For each PO line the response form shows Ordered, Confirmed, Backordered, derived Unavailable, Promised Date, and Notes.

Prefer deriving the item outcome from quantities:

```text
confirmed == requested                         → Confirmed
confirmed == 0 and backordered == 0            → Rejected
all other valid answered quantity combinations → Partial
```

Require a reason when a line is fully rejected, and optionally when unavailable quantity is non-zero.

### Revised supplier response

An answered confirmation gets `Record revised supplier response`, never Edit. The action creates a new snapshot referencing `supersedes_confirmation_id`, preserving prior evidence.

Display history with revision number, superseded record, recorded by/at, line quantities, promised dates, and notes.

### Phase acceptance

- no edit/delete action on answered evidence;
- mixed per-line responses render correctly;
- newer Pending revision is visible without erasing the last answered commitment;
- promised dates cannot precede the PO document date under the existing rule.
## 12. Phase 7 — Warehouse allocation workspace

### Objective

Give Inventory/Logistics users a focused inbound task surface without leaking Purchasing or Accounting authority.

Show PO number, supplier, SKU, confirmed inbound quantity, allocated quantity, currently allocatable quantity, warehouse splits, receipt-in-progress quantity, received quantity, remaining physical work, and promised/expected dates.

Do not expose PO unit cost, Bill amounts, or Supplier Payment data unless that role independently holds the required commercial/accounting permission.

### Allocation actions

Support Add Split, Edit Uncommitted Split, Remove Unused Split, and Allocate Remaining Confirmed Quantity.

Example form guidance:

```text
Ordered: 100
Supplier confirmed: 70
Already allocated: 40
Available to allocate now: 30
```

The UI max is advisory convenience; `PurchaseInboundService` remains authoritative.

### Allocation completion

When confirmation is required, user-facing allocation is complete for the **current effective supplier commitment** when `allocated == confirmed commitment`, not only when allocated equals the full PO ordered quantity.

A later supplier revision can expose more allocatable quantity without rewriting historical allocations.
## 13. Phase 8 — Receiving execution UX

### Objective

Keep the existing canonical Inventory receiving path, but make the PO/inbound handoff explicit.

From the PO or inbound workspace: select an allocation, default the receivable remainder, create a Draft `InventoryOperation` Receipt, then continue in Inventory for lot/serial/condition capture. Only Inventory completion changes stock.

### Receipt display

Show operation number, warehouse, stage, allocation provenance, quantity, lot/serial details when relevant, condition/quarantine evidence, completed by, and completed at.

Partial receiving must be obvious, for example:

```text
Warehouse A: allocated 60, received 20, remaining 40
Warehouse B: allocated 40, received 15, remaining 25
```

### Exceptions to surface

- inactive warehouse;
- missing allocation provenance;
- receipt greater than allocation remainder;
- PO-line over-receipt;
- lot/serial validation failure;
- cancelled receipt;
- receipt stuck in Draft/Ready.

Do not silently repair any of these in the UI.

### Short close

Use the existing PO Close action with a required reason when the supplier will not deliver the remaining quantity after partial receipt. Do not reduce ordered quantity or erase supplier backorder history. The derived milestone becomes `Short-Closed`, and the unreceived quantity remains reportable.
## 14. Phase 9 — Bill and three-way-match workflow

### Objective

Reuse the Accounting domain already implemented and expose its state in the PO workflow without moving financial ownership into Purchasing.

### Draft Bill provisioning

Keep the ADR-approved behavior: accepting a PO may provision one Accounting-owned Draft Bill with PO-line provenance.

The current `PurchaseOrderDraftBillService` uses a synthetic `PO-AUTO:<number>` supplier reference because `Bill` currently requires a reference before the real supplier invoice is available. Replace this ambiguity.

Preferred design:

- allow `supplier_reference` to be null while a PO-linked Bill is Draft;
- require a real supplier invoice reference before Bill approval;
- preserve unique-per-resolved-supplier validation for non-null references;
- keep standalone Bill creation requiring a real reference unless Accounting explicitly changes that rule.

If Accounting rejects nullable Draft references, add a separate internal provisional-reference field; do not overload the supplier-provided reference field with two meanings.

Files affected include `Bill`, `AccountingDocumentService`, `PurchaseOrderDraftBillService`, `BillResource`, migrations, and duplicate-reference tests.

### Draft Bill editing

Accounting records the supplier invoice reference, bill date, payment term/due date, billed quantities, billed unit prices, tax, and account mapping before approval.
### Three-way match

For every PO-linked Bill line show:

```text
Ordered quantity
Received quantity
Cumulative billed quantity
Current Bill quantity
PO unit cost
Supplier invoice unit price
Quantity variance
Price variance
```

Match remains advisory. Accounting approval remains the control point. If configured tolerance is exceeded, require explicit confirmation/variance note rather than silently blocking every legitimate variance.

### Multiple Bills decision

The current acceptance workflow assumes one provisioned Draft Bill. Before supporting staged supplier invoices, make an explicit decision whether one PO may have many active Bills.

If multiple Bills are allowed, define cumulative billed caps/tolerances and update the workflow projection accordingly. Do not accidentally create a second Bill path while the acceptance orchestrator assumes one.

### Financial milestones

- Draft Bill or unresolved invoice data → `Billing In Progress`;
- Approved Bill with remaining balance → `Payment Pending`;
- Partially Paid Bill → `Payment Pending`;
- all relevant Bills Paid → financial side complete.

PO `Completed` is derived only when the physical commitment is Received/validly Short-Closed and the required financial documents are settled under the chosen policy.
## 15. Phase 10 — Supplier payment integration

### Objective

Expose payment progress from the PO without making Purchasing own payment execution.

`AccountingDocumentService::paySupplierPayment()` already locks the payment and Bills, requires exact allocation totals, validates supplier ownership/open balances, prevents over-allocation, posts settlement, and advances Bill state. Reuse it unchanged unless a specific defect is found.

### PO financial projection

Resolve payment facts through:

```text
PurchaseOrder → Bill(s) → SupplierPaymentAllocation → SupplierPayment
```

Expose total billed, approved payable, total paid, outstanding payable, latest payment date/reference, and Bill-level payment allocations.

Do not add `supplier_payment_id` to `purchase_orders`.

If the acting user has Accounting permission, offer a deep link/pre-scoped Accounting action. If not, show:

```text
Next owner: Accounting
Waiting for: supplier payment of <amount>
```

Do not hide a financial blocker simply because the Purchasing user cannot execute it.

### Tests

Cover full payment, partial payment, multiple payments, one payment across multiple Bills, cancelled Draft payment, cross-supplier rejection, and Purchasing-role denial.
## 16. Phase 11 — Supplier return claim and expected outcome

### Objective

Keep the physical return in Inventory while adding the missing Purchasing conversation: what the supplier owes after the goods leave company custody.

`InventoryReturn` should continue to own warehouse, stock condition, lot/serial, source receipt/PO, posted movement, and physical quantity. Replacement / Credit / Refund is a supplier-commercial claim, not an Inventory status.

### Proposed Purchasing model

Create `SupplierReturnClaim` (name may be finalized during implementation) with:

- unique `inventory_return_id` referencing a supplier-type Inventory Return;
- `expected_outcome`: `replacement`, `credit`, `refund`;
- claim status: `open`, `supplier_accepted`, `supplier_rejected`, `resolved`, `cancelled`;
- optional supplier claim/reference number;
- expected resolution date;
- accepted/resolved timestamps;
- notes and blameable fields.

Avoid duplicating supplier/PO values when they can be derived reliably from the Inventory Return. If reporting needs a denormalized key, document and enforce consistency.

### Claim creation

After a supplier return is posted, create/open one claim, choose the expected outcome, and record supplier-facing evidence. The posted physical return remains immutable regardless of later claim decisions.
### Replacement outcome

Do not receive a replacement as an extra normal receipt against an already fully received PO. Current PO over-receipt protection would correctly reject it.

Recommended design:

- create a replacement/claim-resolution record linked to `SupplierReturnClaim`;
- cap replacement quantities by the quantities physically returned;
- initiate a canonical Inventory Receipt whose `source_document` is the replacement/claim record, not the original PO;
- retain links back to supplier, original PO, original receipt, Inventory Return, and lot/serial evidence;
- stock changes only on Inventory completion;
- replacement receipt must not increment original PO received quantity beyond ordered quantity.

The PO workspace displays the replacement as an exception document linked to the PO.

### Credit outcome

The repository currently has no Supplier Debit Note / Supplier Credit document suitable for reducing the payable. This is an Accounting design gate, not a field to improvise in Purchasing.

Before implementation, approve an Accounting ADR defining document direction/name, Bill/PO/Return linkage, quantity/value caps, posting accounts, tax reversal, approval/immutability, and effect on Bill balance and payment eligibility.

Until that exists, the claim may show `Credit expected` but must not fabricate an Accounting posting or mark the financial side resolved.
### Refund outcome

A supplier refund also requires an Accounting-owned money-receipt path. Define whether it settles a supplier credit balance, which account is posted, whether a prior credit document is mandatory, and how input tax is handled.

Do not model supplier refund as a negative Supplier Payment unless Accounting explicitly approves that design.

### Supplier rejection

If the supplier rejects the claim, preserve rejection evidence and leave the physical return posted. Route the business to dispute/escalation, approved write-off, or another explicit outcome. Never silently restore stock or value.

### PO claim card

Display return number, warehouse, returned quantity, lot/serial summary, posted date, expected outcome, claim status, expected date, linked replacement/financial resolution, and outstanding blocker.

### Tests

- one active claim per supplier return;
- claim cannot target a customer return;
- Replacement/Credit/Refund are explicit;
- claim changes never edit posted Inventory Return evidence;
- replacement quantity cannot exceed returned quantity;
- replacement receipt uses canonical Inventory posting;
- Credit/Refund cannot resolve without Accounting evidence;
- rejected claim remains auditable.
