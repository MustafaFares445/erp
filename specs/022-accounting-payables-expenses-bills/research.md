# Research: Accounting Payables — Expenses, Supplier Bills, and Accounts Payable

## Decision 1: Keep the integration one-way: Accounting reads Purchasing

**Decision**: `bill_lines` references `purchase_order_lines`; Purchasing does not reference bills, payables, payments, or journal entries.

**Rationale**: The existing PO → receipt integration is complete and already uses the intended boundary: `PurchaseOrder::receipts()` exposes receipt operations, `PurchaseOrderReceivingService` initiates an `InventoryOperation`, and `AdvancePurchaseOrderOnOperationCompleted` updates received quantities after Inventory completes the operation. Adding a bill or accounting relation to PO creation would violate ADR 0006 and make a purchasing action create an accounting artefact.

**Alternative rejected**: Add `bill_id`, `payable_id`, or accounting callbacks to `purchase_orders`. This would create reverse coupling and expose payable state on Purchasing surfaces.

## Decision 2: Create the accounting link when recording a bill, not when creating a PO

**Decision**: A bill may optionally reference a PO line. The bill form may prefill lines from the PO, but PO creation creates no accounting model.

**Rationale**: A purchase order is a commitment, not a liability. The liability is recognised only when a bill or expense is approved and posted. The match is advisory and can compare an early bill, partial receipt, over-delivery, or price variance.

**Alternative rejected**: Automatically create a draft bill during PO creation. That would create duplicate or orphan accounting documents for orders that are rejected, cancelled, never billed, or billed in multiple invoices.

## Decision 3: Reuse the existing Inventory Operation receipt path

**Decision**: Received quantity is aggregated from completed receipt operations whose `source_document` is the PO, using the existing operation lines. No new receipt table or stock writer is introduced.

**Rationale**: The repository already has the polymorphic source-document schema, receipt relation, receiving service, completion event, transaction boundary, and regression tests. Reusing it preserves stock correctness and avoids a second source of received truth.

## Decision 4: Use five payables entities and no Accounts Payable table

**Decision**: Add `expenses`, `bills`, `bill_lines`, `supplier_payments`, and `supplier_payment_allocations`. Compute the Accounts Payable surface from these records and posted ledger lines.

**Rationale**: The specification requires multi-bill allocation, immutable accounting evidence, aging, and an explicit ledger tie-out. A stored AP balance would duplicate derived facts and could drift.

## Decision 5: Keep supplier payments separate from customer payments

**Decision**: Supplier payments get their own table and allocation table.

**Rationale**: The existing customer `payments` model requires `customer_id` and participates in customer tax recognition. Reusing it would corrupt customer aggregates and blur inbound/outbound accounting semantics.

## Decision 6: Post only the four named events through `JournalPostingService`

**Decision**: The only new posting callers are bill approval, expense approval, expense payment, and supplier payment.

**Rationale**: Each lifecycle transition has a distinct source document and transaction. Bill/expense approval debits expense and recoverable input tax and credits `2100 Accounts Payable`; later payment debits `2100` and credits the selected payment method account. The source morph on `journal_entries` preserves auditability and reversal compatibility.

## Decision 7: Treat matching as advisory and tax as an isolated assumption

**Decision**: PO quantity/price variances never block bill approval. Input tax is posted to seeded `1450 Recoverable Input Tax` at payable approval.

**Rationale**: The owner decisions in `spec.md` explicitly prefer an approver-visible exception over an unrecordable over-delivery/partial bill. Isolating input tax to one account and one posting line makes a future jurisdictional change localized.

## Decision 8: Reconcile, do not overwrite, the existing dirty implementation

**Finding**: The current working tree contains partial `Bill`, `Expense`, resources, policies, migrations, seeders, and `AccountingDocumentService` work. It does not yet implement the specified `BillLine`/PO-line matching, supplier-payment allocation, full payment lifecycle, or computed aging surface. The current `Bill` shape also differs from the 022 ERD register in places such as `expense_account_id` and aggregate totals.

**Decision**: After governance approval, compare every partial file to the approved 022 contract and make narrow corrective changes. Do not assume the existing uncommitted files are complete, and do not discard them or unrelated changes.
