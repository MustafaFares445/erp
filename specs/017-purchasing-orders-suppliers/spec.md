# Feature Specification: Purchasing — Purchase Orders and Supplier Confirmations

**Feature Directory**: `017-purchasing-orders-suppliers`

**Created**: 2026-08-18

**Status**: Draft

**Input**: Extract the Purchasing module from the canonical documentation set — `Docs/PRD.md` (feature "Supplier Management", §9 Business Rules "Supplier confirmations are manually updated by admin", §11 Out of Scope "Supplier-facing portal"), `Docs/SDD.md` §Supplier Management, `Docs/database/ERD.md` (tables `suppliers`, `supplier_confirmations`, the `orders` supplier columns, and the `orders` status catalog entry carrying `pending_supplier_confirmation` / `supplier_confirmed` / `supplier_rejected`), and `Docs/database/DFD.md`. The module fills the two reserved-but-unbuilt sidebar slots already declared in `App\Filament\AdminModuleRegistry::groups()` under the `purchasing` group — `admin.resources.purchase_orders` and `admin.resources.supplier_confirmations` — whose translation keys already exist at `lang/en/admin.php:703-704`. The eight decisions (D1–D8) recorded in §Owner Decisions below were taken by the project owner on 2026-08-18 and are binding; this specification encodes them rather than reopening them.

**Governance prerequisite**: This feature is **blocked** until ADR 0006 (`Docs/adr/0006-filament-purchasing-dashboard.md`) is moved from `Proposed` to `Accepted` by the project owner. The constitution (1.6.0), `Docs/PRD.md` §11, and `Docs/database/ERD.md` have all been updated. See §Governance Gate.

## Owner Decisions

These decisions were taken by the project owner on 2026-08-18 and are settled inputs, not open questions.

- **D1 — Purchase Orders plus Supplier Confirmations.** The module delivers a Purchase Order document with lines and a lifecycle, and a Supplier Confirmation record. Goods are received exclusively through the **existing** `InventoryOperation` receipt flow; this feature introduces no second receiving path, no stock-writing service, and no new inventory movement source. Purchase Requisitions and RFQs are out of scope.
- **D2 — Supplier Confirmation is polymorphic.** One confirmation entity attaches either to a customer `Order` (the ERD's back-order flow: "can you supply this?") or to a `PurchaseOrder` (the supplier acknowledges our order, with a promised date). This honours the ERD's `supplier_confirmations.order_id` intent while making the Purchasing sidebar grouping coherent.
- **D3 — Prices and totals, no ledger.** Purchase order lines carry a unit cost, a currency, and line and document totals, defaulting from `SupplierProductReference.purchase_cost`. Receiving writes the actual received cost back to `SupplierProductReference`. **No** journal entry, **no** tax recognition, **no** accounts-payable posting, and **no** supplier bill is created by this feature — all of that is deferred to the unbuilt accounting module.
- **D4 — Approval with a value threshold.** A purchase order follows `Draft → Pending Approval → Approved → Sent`. Orders whose total is at or below a configurable threshold auto-approve on submission; orders above it require a Purchasing Manager. This mirrors the existing Inventory `adjustment.create` / `adjustment.confirm` permission split.
- **D5 — Dashboard-only surface.** The module is delivered as `/admin` Filament surfaces only, following the ADR 0001 / 0002 / 0003 / 0004 precedent. No API surface of any kind, and no supplier-facing portal (explicitly out of scope in `Docs/PRD.md` §11).
- **D6 — English-only UI for this phase**, matching the spec 013, 015, and 016 precedent.
- **D7 — Receiving is partial-capable and over-receipt is blocked.** A purchase order may be received across multiple receipt operations. Cumulative received quantity may never exceed the ordered quantity on any line; the excess is rejected rather than silently absorbed. A short-received order is closed explicitly by a human, never automatically.
- **D8 — No supplier returns in this phase.** Returning received goods to a supplier is out of scope; the existing Inventory adjustment flow remains the only way to write off bad stock. A supplier-return document requires its own specification.

## Governance Gate

`Docs/PRD.md` §11 and the constitution's Product Scope & Boundaries place Filament dashboard implementation out of scope except for modules with an approved ADR. Four exist: ADR 0001 (Inventory), ADR 0002 (CRM), ADR 0003 (Employees), ADR 0004 (Support and Maintenance). ADR 0005 is taken by an unrelated decision (Spatie Activitylog).

Therefore, before **any** implementation task in this feature begins:

1. ⚠ **OPEN** — `Docs/adr/0006-filament-purchasing-dashboard.md` is **drafted but its Status is `Proposed`**. It must be moved to `Accepted` by the project owner. The ADR is scoped to dashboard-only administration of purchase orders, supplier confirmations, supplier-linked receiving, purchasing reports, and purchasing roles and permissions — and explicitly does **not** authorise any API surface, a supplier portal, accounts-payable or general-ledger posting, supplier bills, or purchase-tax recognition.
2. ✅ **DONE** — `.specify/memory/constitution.md` is amended to 1.6.0 (MINOR — Product Scope & Boundaries materially expanded), with a regenerated Sync Impact Report and a Specification Governance note recording that this work has **no** corresponding entry in the documented extraction order and is an owner-prioritised addition.
3. ✅ **DONE** — `Docs/PRD.md` §11 lists the ADR 0006 exception. ADR 0004 was found to be missing from §11 entirely and was added in the same change, so §11 now lists all five approved exceptions.
4. ✅ **DONE** — `Docs/database/ERD.md` carries the `purchase_orders` and `purchase_order_lines` tables, the `supplier_confirmations` polymorphic change with `promised_at` and the dropped `status` column, and the `purchase_settings` singleton, per Principle I ("Database design MUST be finalized before implementation begins"). Entity Groups, Full Entity List, and Relationships were updated to match. The ERD already carried `orders.pending_reason`, so E-7 needed no ERD change — the divergence is in the built table only.

This ordering is a hard constraint of Principle I, not a formality. Step 4 has direct precedent: the constitution's own 1.5.0 Sync Impact Report records the same ERD-first requirement as a blocking follow-up for feature 016.

**Net effect**: item 1 is the only remaining blocker, and it is a project-owner signature rather than a work item.

## ERD Divergence Register

The canonical ERD does not contain a purchase-order entity. It models purchasing solely as an admin-recorded supplier answer against a customer order. This feature deliberately extends the ERD, and each extension is registered here so the divergence is explicit rather than accidental.

| # | Extension | Rationale |
|---|-----------|-----------|
| E-1 | New `purchase_orders` table | The ERD has no document representing goods we order from a supplier. Without one, `InventoryOperation.source_document` — whose docblock already reads *"a purchase order for a receipt"* — has nothing to point at, and received stock has no ordered-quantity baseline to reconcile against. |
| E-2 | New `purchase_order_lines` table | Line-level ordered/received quantities are required by D7's partial-receipt and over-receipt rules. |
| E-3 | `supplier_confirmations.order_id` becomes a `confirmable_type` / `confirmable_id` morph | Required by D2 so one entity serves both the ERD's customer back-order flow and purchase-order acknowledgement. |
| E-4 | `supplier_confirmations` drops the ERD's generic `status` column | The ERD carries both `status` (draft/pending) and `confirmation_status` (pending/confirmed/rejected) on this table, which are redundant. Only `confirmation_status` is kept. |
| E-5 | `supplier_confirmations` gains `promised_at` | A supplier acknowledging a purchase order commits to a date; the ERD's `notes` field cannot be filtered or reported on. |
| E-6 | New `purchase_settings` singleton table | Holds D4's approval threshold. Follows the existing `inventory_settings` singleton-row precedent. |
| E-7 | The built `orders` table lacks the ERD's `supplier_id`, `pending_reason`, `payment_status`, and `grand_total` columns | Pre-existing divergence, not introduced here. This feature adds **only** `pending_reason` (nullable) to support the customer back-order confirmation flow; the financial columns stay unbuilt and belong to the future sales/accounting feature. |

## Scope

This feature adds the Purchasing module to the existing `/admin` Filament dashboard, covering:

- purchase-order creation, numbering, lines, and per-line supplier item references;
- purchase-order costing — unit cost, currency, line totals, and document totals defaulted from supplier product references;
- the purchase-order lifecycle: draft, threshold-based approval, transmission to the supplier, receiving progress, short-close, and cancellation;
- supplier confirmations against either a purchase order or a customer order, recorded manually by an admin, with a promised date;
- receiving a purchase order through the existing `InventoryOperation` receipt flow, with the purchase order as the operation's source document, partial receipts, and over-receipt rejection;
- writing the received unit cost back to the supplier product reference;
- the supplier product-reference catalogue surfaced as a first-class purchasing view rather than only as a nested repeater on the supplier form;
- purchasing search, filtering, reporting, and audit review;
- fixed dashboard roles and permissions for the module.

The feature does **not** add: any API surface (`/api/dashboard/*`, `/api/customer/*`, or otherwise); a supplier-facing portal; purchase requisitions or RFQs; supplier bills, accounts payable, payments to suppliers, journal entries, purchase-tax recognition, or landed-cost allocation across freight and duty; supplier returns or debit notes; multi-currency revaluation or exchange-rate management beyond storing a currency code; moving-average or FIFO cost recalculation of on-hand stock; supplier performance scoring; automatic reorder-point purchasing or demand forecasting; outbound email or EDI transmission of a purchase order to a supplier; blanket or scheduled purchase agreements. Adding any of these later requires its own specification and either a separate ADR or an explicit amendment to ADR 0006.

## User Scenarios and Testing

### User Story 1 - Enforce Purchasing Roles and Permissions (Priority: P1)

An authorized dashboard user's access to every purchase-order, approval, transmission, confirmation, receiving, cost-writeback, report, and audit action is governed by one of four fixed roles, checked consistently everywhere an action can be triggered.

**Why this priority**: Purchase orders commit company money and, once received, write real stock through the Inventory services. Without correct and consistently enforced permission checks, no other capability in this module can be trusted.

**Independent Test**: Sign in as each of the four fixed roles and exercise the same action through a direct page visit, a record action, a bulk action, and a direct service call that bypasses the UI, confirming identical allow/deny behaviour across all four paths.

**Acceptance Scenarios**:

1. **Given** a System Admin, **when** using the dashboard, **then** full management, approval regardless of threshold, transmission, confirmation recording, receiving, short-close, cancellation, restoration, and every override are available.
2. **Given** a Purchasing Manager, **when** using the dashboard, **then** purchase-order creation, editing, approval above the threshold, transmission, supplier-confirmation recording, short-close, cancellation, supplier and supplier-product-reference management, and report viewing are available, and record restoration and dashboard-role assignment are denied.
3. **Given** a Purchasing Officer, **when** using the dashboard, **then** drafting purchase orders, submitting them for approval, recording supplier confirmations, and initiating receipts are available, while approving an order above the threshold, cancelling a sent order, short-closing, and editing the approval threshold are denied.
4. **Given** a Reviewer, **when** using the dashboard, **then** all purchase orders, confirmations, supplier references, reports, and audit entries are viewable, and no create, edit, submit, approve, send, confirm, receive, close, or cancel action is available.
5. **Given** any role, **when** a page is opened or an action is executed (including a bulk action or a direct call that bypasses a hidden button), **then** the same permission boundary is enforced at both checkpoints.
6. **Given** a user whose only role is Purchasing Manager or Purchasing Officer, **when** they attempt to reach any CRM, Employees, or Support dashboard surface, **then** access is denied — purchasing grants no other module's access.
7. **Given** a user whose only role is Purchasing Officer, **when** they initiate a receipt from a purchase order, **then** the receipt is created and completed under the purchasing permission alone, and they still cannot reach the general Inventory Operations, Adjustments, or Stock Levels surfaces.

---

### User Story 2 - Draft a Purchase Order With Supplier Pricing (Priority: P1)

A Purchasing Officer drafts a purchase order against a supplier, adds product-variant lines with quantities and units, and the system defaults each line's unit cost, currency, and supplier item number from the supplier's product reference, then computes line and document totals.

**Why this priority**: The purchase order is the root record of the entire module; approval, transmission, confirmation, and every receipt hang off it.

**Independent Test**: Create, edit, search, filter, and delete a draft purchase order without approving it, without sending it, and without any receipt.

**Acceptance Scenarios**:

1. **Given** a new purchase order, **when** it is saved, **then** it requires an existing active supplier, a destination warehouse, a currency, an order date, and at least one line, and it is rejected with field-level messages when any is missing.
2. **Given** a saved purchase order, **when** it is created, **then** the system assigns a unique, human-readable purchase-order number that is unique across active, cancelled, closed, and soft-deleted orders.
3. **Given** two purchase orders created concurrently, **when** both are saved, **then** each receives a distinct number and neither creation fails on a duplicate.
4. **Given** a line whose product variant has an active supplier product reference for the chosen supplier, **when** the variant is selected, **then** the unit cost, currency, and supplier item number default from that reference, and the reference is recorded as the price's provenance.
5. **Given** a line whose product variant has **no** active reference for that supplier, **when** the variant is selected, **then** the unit cost defaults to zero, no provenance is recorded, and the user may enter a cost manually.
6. **Given** a line, **when** its quantity or unit cost changes, **then** the line total and the document total recompute immediately, and both are stored rather than derived at read time.
7. **Given** a purchase-order line, **when** its quantity is zero or negative, or its unit cost is negative, **then** the line is rejected before any record is written.
8. **Given** two lines for the same product variant and unit on one order, **when** the order is saved, **then** the duplicate is rejected so received quantities can never be ambiguously attributed.
9. **Given** a supplier marked inactive, **when** a new purchase order is drafted, **then** that supplier is not selectable, and existing orders already referencing it remain readable.
10. **Given** the purchase-order list, **when** searched by order number or supplier, or filtered by status, warehouse, currency, or date range, **then** matching results are returned with pagination.
11. **Given** a draft purchase order, **when** it is deleted, **then** it is archived rather than physically removed and its number stays reserved.

---

### User Story 3 - Approve and Transmit a Purchase Order (Priority: P1)

A Purchasing Officer submits a draft purchase order; orders at or below the configured threshold approve automatically, orders above it wait for a Purchasing Manager; once approved, the order is marked as sent to the supplier and becomes immutable.

**Why this priority**: Approval is the financial control point of the module and the boundary after which an order is a commitment rather than a working draft.

**Independent Test**: Submit one order below the threshold and one above it, approve the second, send both, and confirm the immutability boundary — without recording any confirmation or receipt.

**Acceptance Scenarios**:

1. **Given** a draft order whose total is at or below the threshold, **when** it is submitted, **then** it moves directly to approved, records the submitter as the approver, and records the approval timestamp.
2. **Given** a draft order whose total is above the threshold, **when** it is submitted, **then** it moves to pending approval and is not yet sendable.
3. **Given** an order pending approval, **when** a Purchasing Manager approves it, **then** it moves to approved, recording the approver and timestamp.
4. **Given** an order pending approval, **when** a Purchasing Manager rejects it with a reason, **then** it moves to rejected, the reason is stored, and it can be returned to draft for correction.
5. **Given** an order pending approval, **when** the user who submitted it attempts to approve it, **then** the action is denied — the submitter and the approver must be different users unless the actor is a System Admin.
6. **Given** an approved order, **when** it is marked sent, **then** the transmission timestamp is recorded and the order becomes immutable: its supplier, warehouse, currency, lines, quantities, and costs can no longer be edited.
7. **Given** a sent order, **when** an edit to any line is attempted through the UI or through a direct service call, **then** it is rejected at both checkpoints.
8. **Given** an order in any state, **when** its total changes while it is still a draft, **then** the threshold decision is re-evaluated at submission time rather than at draft time.
9. **Given** the approval threshold is changed, **when** an order already pending approval is viewed, **then** it stays pending approval — a threshold change never retroactively approves a waiting order.
10. **Given** an approved or sent order with no receipts against it, **when** it is cancelled with a reason, **then** it moves to cancelled, the reason is stored, and no further action is possible.
11. **Given** an order with at least one completed receipt, **when** cancellation is attempted, **then** it is refused and the user is directed to short-close instead.

---

### User Story 4 - Record a Supplier Confirmation (Priority: P2)

An admin records the supplier's answer — confirmed with a promised date, or rejected — against either a sent purchase order or a customer order that cannot be fulfilled from stock.

**Why this priority**: This is the flow the canonical ERD actually sanctions, and it is the only mechanism by which a customer order blocked on stock can be tracked to resolution. It is P2 rather than P1 because a purchase order can be drafted, approved, sent, and received without it.

**Independent Test**: Record a confirmation against a purchase order and against a customer order, exercise both the confirmed and rejected outcomes, and verify the parent document's status reacts — all without any receipt.

**Acceptance Scenarios**:

1. **Given** a sent purchase order, **when** a confirmation is recorded as confirmed with a promised date, **then** the confirming user and timestamp are stored and the promised date is visible on the purchase order.
2. **Given** a sent purchase order, **when** a confirmation is recorded as rejected with notes, **then** the purchase order remains sent and is flagged as supplier-rejected so a buyer can act on it.
3. **Given** a customer order that cannot be fulfilled from stock, **when** it is marked as pending supplier confirmation with a reason, **then** the reason is stored on the order and a pending confirmation is raised against the chosen supplier.
4. **Given** a customer order with a pending confirmation, **when** the supplier confirms, **then** the order moves to supplier-confirmed; **when** the supplier rejects, **then** the order moves to supplier-rejected.
5. **Given** a confirmation, **when** a promised date earlier than the order date is entered, **then** it is rejected.
6. **Given** a confirmation already recorded as confirmed or rejected, **when** an edit is attempted, **then** it is refused — a correction is recorded as a new confirmation, preserving the original answer.
7. **Given** a document with several confirmations over time, **when** it is viewed, **then** the full confirmation history is shown in chronological order with the latest answer surfaced as the current one.
8. **Given** a confirmation, **when** its target document is neither a purchase order nor a customer order, **then** it is rejected — no other document type is confirmable.
9. **Given** the confirmations list, **when** filtered by status, supplier, or target document type, **then** matching results are returned with pagination.

---

### User Story 5 - Receive Against a Purchase Order (Priority: P1)

A Purchasing Officer initiates a receipt from a sent purchase order; the system creates an Inventory receipt operation pre-filled from the order's open quantities, and completing that operation posts stock and advances the purchase order's receiving progress.

**Why this priority**: Receiving is where a purchase order becomes real inventory. It is the integration point that makes the whole module worth building, and the point where Principle III (Financial & Inventory Integrity) applies most sharply.

**Independent Test**: Receive one order fully in a single receipt, and receive a second order across two partial receipts, verifying stock, movements, and progress after each.

**Acceptance Scenarios**:

1. **Given** a sent purchase order, **when** a receipt is initiated, **then** a draft Inventory receipt operation is created with the purchase order as its source document, the order's supplier and destination warehouse pre-filled, and one line per open order line pre-filled with the remaining quantity.
2. **Given** that receipt operation, **when** it is completed, **then** stock is posted through the existing Inventory operation service — this feature writes no stock itself — and exactly the movements that service normally produces are created.
3. **Given** a completed receipt, **when** the purchase order is viewed, **then** each line's received quantity has increased by the received amount and the order's status reflects partial or full receipt.
4. **Given** a purchase order fully received across one or more receipts, **when** the last receipt completes, **then** the order moves to received and no further receipt can be initiated from it.
5. **Given** a receipt whose line quantity would push cumulative received quantity above the ordered quantity, **when** completion is attempted, **then** it is rejected with a message naming the offending line — over-receipt is never silently absorbed.
6. **Given** a partially received purchase order, **when** it is short-closed with a reason, **then** it moves to closed, the reason is stored, the outstanding quantity is abandoned, and no further receipt can be initiated.
7. **Given** a receipt operation created from a purchase order, **when** that operation is cancelled before completion, **then** the purchase order's received quantities are unchanged and a new receipt can be initiated.
8. **Given** a purchase order that is draft, pending approval, rejected, cancelled, or closed, **when** a receipt is initiated, **then** it is refused — only sent and partially received orders are receivable.
9. **Given** a completed receipt, **when** its received unit cost differs from the ordered unit cost, **then** the purchase order line records both, and the variance is visible on the order.
10. **Given** a completed receipt whose lines carry lots, serial numbers, or expiry dates, **when** it completes, **then** those are captured by the existing Inventory receipt behaviour unchanged — this feature adds no parallel lot, serial, or expiry handling.
11. **Given** two receipts against the same purchase order completing concurrently, **when** both attempt to advance the same line, **then** the received quantity is correct and neither receipt double-counts.

---

### User Story 6 - Maintain Supplier Product References and Costs (Priority: P2)

A Purchasing Manager manages the catalogue of supplier product references — which supplier sells which variant, under what item number, at what cost — and completed receipts keep those costs current automatically.

**Why this priority**: Reference costs are what make purchase-order drafting fast and accurate. It is P2 because a purchase order can be drafted with manually entered costs.

**Independent Test**: Manage references through a dedicated list surface, then complete a receipt at a different cost and verify the writeback — independent of approval and confirmation.

**Acceptance Scenarios**:

1. **Given** the supplier product references list, **when** searched by supplier, variant SKU, supplier item number, or manufacturer, **then** matching results are returned with pagination.
2. **Given** a supplier and a product variant, **when** a second active reference for that same pair is created, **then** it is rejected — one active reference per supplier and variant.
3. **Given** a completed receipt line whose unit cost differs from the reference cost, **when** the receipt completes, **then** the reference's purchase cost is updated to the received cost and the previous value is retained in the audit trail.
4. **Given** a completed receipt line whose product variant has no reference for that supplier, **when** the receipt completes, **then** a new active reference is created from the received line.
5. **Given** a receipt completed in a currency differing from the reference's currency, **when** the writeback runs, **then** the reference's currency is updated alongside the cost — no conversion is attempted.
6. **Given** a reference marked inactive, **when** a purchase-order line for that supplier and variant is drafted, **then** no cost defaults from it.

---

### User Story 7 - Report On and Audit Purchasing Activity (Priority: P3)

A Reviewer inspects purchasing activity — open commitments, receiving progress, supplier responsiveness, and cost variance — and traces every state change back to the user who made it.

**Why this priority**: Reporting depends on all other stories having produced data. It is genuinely valuable but strictly last.

**Independent Test**: With purchase orders in every status, open each report and the audit view and confirm figures reconcile against the underlying records.

**Acceptance Scenarios**:

1. **Given** purchase orders in every status, **when** the open-commitments report is opened, **then** it shows outstanding ordered-minus-received value per supplier and per warehouse, excluding cancelled, closed, and fully received orders.
2. **Given** completed receipts, **when** the receiving-performance report is opened, **then** it shows promised versus actual receipt dates per supplier.
3. **Given** receipts whose costs differed from their orders, **when** the cost-variance report is opened, **then** it shows ordered versus received cost per line with the variance.
4. **Given** any report, **when** a role without report permission opens it, **then** access is denied.
5. **Given** any purchase order, **when** its audit trail is viewed, **then** every status change, approval, transmission, confirmation, receipt, short-close, and cancellation is listed with actor and timestamp.
6. **Given** a purchasing report, **when** it is exported, **then** the export honours the same permission boundary as the on-screen report.

---

### Edge Cases

- What happens when a purchase order's supplier is soft-deleted after the order is sent? The order stays readable and receivable; the supplier is shown as archived.
- What happens when a purchase-order line's product variant is soft-deleted or archived after the order is sent? The line stays readable and receivable; the variant is shown as archived, and no new line may reference it.
- What happens when the destination warehouse is deactivated between sending and receiving? Receipt initiation is refused with a message naming the warehouse.
- What happens when a purchase order has lines in units that differ from the variant's stock unit? The existing Inventory receipt behaviour governs conversion unchanged; the purchase order records the ordered unit and reconciles received quantity in that same unit.
- What happens when the approval threshold is unset or zero? Every submission requires explicit approval.
- What happens when two users approve the same pending order concurrently? Exactly one approval succeeds; the second is rejected as already approved.
- What happens when a receipt is initiated from an order that another user short-closes before the receipt completes? Completion is refused because the order is no longer receivable.
- What happens when a purchase order is fully received and a further receipt operation still references it as source document? Initiation is refused; an already-open draft receipt completes only if its quantities still fit within the open amounts.
- What happens when a customer order's back-order confirmation is recorded but that order is later cancelled? The confirmation history is preserved; no new confirmation can be recorded.
- What happens when a purchase order's currency differs from the supplier reference's currency at drafting time? The order's currency wins for every line; the mismatch is surfaced as a warning, not an error.
- How does the system handle a purchase order whose every line is fully received but which was never marked sent? Impossible by construction — receipt initiation requires a sent order.

## Requirements

### Functional Requirements

#### Governance and Setup

- **FR-001**: ADR 0006 MUST be approved, the constitution MUST be at 1.6.0, `Docs/PRD.md` §11 MUST list the exception, and `Docs/database/ERD.md` MUST carry the E-1 through E-6 extensions before any implementation task begins.
- **FR-002**: The system MUST register a `purchasing` navigation group whose Purchase Orders and Supplier Confirmations items resolve to real resources, replacing the placeholder entries currently declared in `AdminModuleRegistry`.
- **FR-003**: The system MUST expose a singleton purchasing settings record holding the approval threshold amount and its currency, editable only by System Admin.
- **FR-004**: All purchasing UI text MUST come from translation keys under the existing `admin.*` namespace, English-only for this phase.

#### Roles and Permissions

- **FR-005**: The system MUST define a `purchase.*` permission catalogue as the single source of truth consumed by both the permission seeder and the policy layer.
- **FR-006**: The system MUST add Purchasing Manager and Purchasing Officer to the canonical fixed dashboard role list, so that every other module's admin-bypass check narrows automatically.
- **FR-007**: The system MUST enforce every purchasing permission at both the page/action checkpoint and the service checkpoint, so a direct call that bypasses a hidden button is denied identically.
- **FR-008**: The system MUST deny purchasing roles access to every other module's surfaces, and MUST NOT require any Inventory permission for a purchasing user to initiate and complete a purchase-order receipt.
- **FR-009**: The system MUST prevent physical deletion of any purchasing record through the policy layer.

#### Purchase Orders

- **FR-010**: Users MUST be able to create a purchase order specifying supplier, destination warehouse, currency, order date, optional expected date, and notes.
- **FR-011**: The system MUST assign each purchase order a unique, human-readable number, unique across active, cancelled, closed, and soft-deleted records, and MUST issue distinct numbers under concurrent creation.
- **FR-012**: Users MUST be able to add lines specifying product variant, unit, quantity, and unit cost, with at least one line required before submission.
- **FR-013**: The system MUST default a line's unit cost, currency, and supplier item number from the active supplier product reference for that supplier and variant, and MUST record which reference supplied the price.
- **FR-014**: The system MUST reject a line with a non-positive quantity or a negative unit cost, and MUST reject a duplicate product-variant-and-unit line on the same order.
- **FR-015**: The system MUST store computed line totals and a document total rather than deriving them at read time, recomputing both whenever a quantity or cost changes.
- **FR-016**: The system MUST restrict supplier selection on new orders to active, non-archived suppliers.
- **FR-017**: The system MUST support searching purchase orders by number and supplier, and filtering by status, warehouse, currency, and date range, with pagination.
- **FR-018**: The system MUST archive rather than physically delete purchase orders, keeping the number reserved.

#### Lifecycle, Approval, and Transmission

- **FR-019**: The system MUST implement the purchase-order lifecycle `draft → pending approval → approved → sent → partially received → received`, with `rejected`, `closed`, and `cancelled` as additional states, and MUST reject every transition not explicitly permitted.
- **FR-020**: On submission, the system MUST auto-approve an order whose total is at or below the configured threshold and MUST route an order above it to pending approval, evaluating the threshold at submission time.
- **FR-021**: The system MUST record the approver and approval timestamp on every approval, including auto-approvals.
- **FR-022**: The system MUST prevent the submitting user from approving their own above-threshold order unless the actor is a System Admin.
- **FR-023**: The system MUST allow an above-threshold order to be rejected with a stored reason and returned to draft for correction.
- **FR-024**: The system MUST NOT retroactively approve an already-pending order when the threshold changes.
- **FR-025**: The system MUST record a transmission timestamp when an approved order is marked sent, and MUST make the order's supplier, warehouse, currency, lines, quantities, and costs immutable from that point, enforced at both the UI and service checkpoints.
- **FR-026**: The system MUST allow cancelling an order that has no completed receipt, with a stored reason, and MUST refuse cancellation once any receipt has completed.
- **FR-027**: The system MUST allow short-closing a partially received order with a stored reason, abandoning the outstanding quantity and blocking further receipts.

#### Supplier Confirmations

- **FR-028**: Users MUST be able to record a supplier confirmation against either a purchase order or a customer order, and the system MUST reject any other target type.
- **FR-029**: The system MUST record confirmation status (pending, confirmed, rejected), supplier, confirming user, confirmation timestamp, optional promised date, and notes.
- **FR-030**: The system MUST reject a promised date earlier than its target document's order date.
- **FR-031**: The system MUST treat a confirmed or rejected confirmation as immutable, requiring a new confirmation record for any correction.
- **FR-032**: The system MUST display a document's full confirmation history chronologically, surfacing the latest answer as current.
- **FR-033**: The system MUST allow a customer order to be marked pending supplier confirmation with a stored reason, and MUST move it to supplier-confirmed or supplier-rejected according to the recorded answer.
- **FR-034**: The system MUST flag a purchase order whose latest confirmation is a rejection, without changing its lifecycle status.
- **FR-035**: The system MUST support filtering confirmations by status, supplier, and target document type, with pagination.

#### Receiving

- **FR-036**: Users MUST be able to initiate a receipt from a sent or partially received purchase order, and the system MUST refuse initiation for every other status.
- **FR-037**: On initiation, the system MUST create a draft Inventory receipt operation carrying the purchase order as its source document, with the order's supplier and destination warehouse pre-filled and one line per open order line pre-filled with the remaining quantity.
- **FR-038**: The system MUST post all stock through the existing Inventory operation service and MUST NOT write stock, create inventory movements, or introduce any second receiving path of its own.
- **FR-039**: The system MUST increase each purchase-order line's received quantity when a linked receipt completes, and MUST advance the order to partially received or received accordingly.
- **FR-040**: The system MUST reject completion of a receipt that would push any line's cumulative received quantity above its ordered quantity, naming the offending line.
- **FR-041**: The system MUST keep received quantities correct under concurrent receipt completion against the same order, with no double counting.
- **FR-042**: The system MUST leave purchase-order received quantities unchanged when a linked receipt is cancelled, and MUST permit a new receipt afterwards.
- **FR-043**: The system MUST record both the ordered and the received unit cost per line and surface the variance.
- **FR-044**: The system MUST refuse receipt initiation when the destination warehouse is no longer active.
- **FR-045**: The system MUST leave lot, serial-number, and expiry capture entirely to the existing Inventory receipt behaviour.

#### Supplier Product References

- **FR-046**: Users MUST be able to manage supplier product references through a dedicated list surface with search by supplier, variant SKU, supplier item number, and manufacturer.
- **FR-047**: The system MUST enforce at most one active reference per supplier and product variant.
- **FR-048**: The system MUST update a reference's purchase cost and currency from a completed receipt line when they differ, retaining the previous value in the audit trail.
- **FR-049**: The system MUST create a new active reference from a completed receipt line when none exists for that supplier and variant.
- **FR-050**: The system MUST NOT default a cost from an inactive reference.

#### Reporting and Audit

- **FR-051**: The system MUST provide an open-commitments report showing outstanding ordered-minus-received value by supplier and warehouse, excluding cancelled, closed, and fully received orders.
- **FR-052**: The system MUST provide a receiving-performance report comparing promised and actual receipt dates by supplier.
- **FR-053**: The system MUST provide a cost-variance report comparing ordered and received unit costs by line.
- **FR-054**: The system MUST record an audit entry for every purchase-order status change, approval, rejection, transmission, confirmation, receipt linkage, short-close, and cancellation, capturing actor and timestamp.
- **FR-055**: The system MUST apply the same permission boundary to a report's export as to its on-screen view.

### Key Entities

- **Purchase Order**: A commitment to buy goods from one supplier, delivered to one warehouse. Carries a unique number, a status through the approval and receiving lifecycle, a currency, an order date and optional expected date, stored totals, and the approval, transmission, closure, and cancellation audit fields. Owns its lines; is referenced as the source document of zero or more Inventory receipt operations; is the target of zero or more supplier confirmations.
- **Purchase Order Line**: One product variant ordered in one unit at one cost. Carries ordered quantity, cumulative received quantity, ordered unit cost, last received unit cost, stored line total, a snapshot of the supplier item number, and the supplier product reference that supplied the price.
- **Supplier Confirmation**: An admin-recorded supplier answer against either a purchase order or a customer order. Carries the target document reference, the supplier, the confirmation status, the confirming user and timestamp, an optional promised date, and notes. Immutable once answered.
- **Purchase Setting**: A singleton holding the approval threshold amount and its currency.
- **Supplier** *(existing)*: Extended in use, not in shape — becomes the anchor of the purchasing module.
- **Supplier Product Reference** *(existing)*: Gains a first-class management surface and receives automatic cost writeback from completed receipts.
- **Inventory Operation** *(existing)*: Reused unchanged as the sole receiving mechanism; its existing nullable source-document reference points at the purchase order.
- **Order** *(existing customer order)*: Gains a nullable pending-reason field and participates as a supplier-confirmation target.

## Success Criteria

### Measurable Outcomes

- **SC-001**: A Purchasing Officer can draft, submit, and send a ten-line purchase order in under three minutes, with every line's cost defaulted rather than typed, given existing supplier references.
- **SC-002**: 100% of stock created by this module passes through the existing Inventory operation service — a static architecture test proves the purchasing namespace contains no direct stock or movement write.
- **SC-003**: Every one of the four fixed roles produces identical allow/deny outcomes at the page checkpoint and the service checkpoint across all purchasing actions, verified by automated tests covering both paths for every action.
- **SC-004**: Cumulative received quantity never exceeds ordered quantity on any line, including under concurrent receipt completion, verified by a concurrency test.
- **SC-005**: Every purchase-order state change is attributable to a user and a timestamp in the audit trail, with no unattributed transitions.
- **SC-006**: An approved purchase order's financial fields cannot be altered after transmission through any path, verified by tests exercising both the UI and a direct service call.
- **SC-007**: The open-commitments report reconciles exactly against the sum of ordered-minus-received line values for all non-terminal orders.
- **SC-008**: The feature ships with no new PHPStan baseline entries and no reduction in any existing quality gate.

## Assumptions

- The dashboard remains the only client; no mobile or API consumer reads or writes purchasing data in this phase.
- Suppliers are few enough and stable enough that manual selection is acceptable; no supplier onboarding workflow, approval, or scoring is needed.
- Supplier communication happens outside the system — by phone or email — and the dashboard records the outcome. Nothing is transmitted to a supplier by the application.
- One purchase order targets exactly one supplier and one destination warehouse. Multi-warehouse or multi-supplier orders are split into separate orders.
- The currency code is stored for record-keeping and display only. No exchange rate, conversion, or revaluation is performed anywhere in this feature.
- Prices exclude tax. Purchase tax is entirely a matter for the future accounting module, per Principle III's rule that tax is recognized only on payment.
- The existing `orders` table is a delivery/logistics order rather than the ERD's financial sales order. The back-order confirmation flow attaches to it as built; the ERD's missing financial columns stay the responsibility of the future sales feature.
- The existing Inventory receipt operation already handles lots, serial numbers, expiry, and unit conversion correctly, and needs no change to serve purchase-order receiving.
- Received cost is the line's unit cost only. Freight, duty, and other landed-cost components are out of scope, so cost writeback is a direct copy rather than an allocation.
- The audit trail uses the mechanism established by ADR 0005 (Spatie Activitylog), consistent with the other modules.

## Dependencies and Integration Points

| Module | Direction | Nature |
|--------|-----------|--------|
| Inventory — operations | Purchasing → Inventory | Purchase orders create receipt operations and read their completion. The **only** stock-writing path. Purchasing must not import inventory stock or movement writers directly. |
| Inventory — catalogue | Purchasing → Inventory | Product variants and units are read for line entry. |
| Inventory — warehouses | Purchasing → Inventory | Destination warehouse selection and active-state validation. |
| Inventory — supplier references | Bidirectional | Read for cost defaulting; written back on receipt completion. |
| Sales — customer orders | Purchasing → Sales | Back-order confirmations attach to customer orders and set their pending reason and supplier-confirmation status. |
| Identity — roles and permissions | Purchasing → Identity | Two new fixed dashboard roles registered in the canonical role list, narrowing every other module's admin bypass. |
| Reports | Purchasing → Reports | Three purchasing reports registered in the existing reports group. |
| Audit | Purchasing → Audit | State changes recorded through the established activity-log mechanism. |
| Accounting | **None** | Deliberately absent. No bill, payable, payment, journal entry, or tax recognition. The seam is documented for the future accounting feature, not built. |
