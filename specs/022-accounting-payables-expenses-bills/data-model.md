# Data Model: Accounting Payables — Expenses, Supplier Bills, and Accounts Payable

## Relationship overview

```text
Supplier ──< Bill ──< BillLine >── optional PurchaseOrderLine
                    │                 │
                    │                 └── PurchaseOrder ──< InventoryOperation(receipts)
                    │
Supplier ──< SupplierPayment ──< SupplierPaymentAllocation >── Bill

Expense ── optional Supplier
Bill / Expense / SupplierPayment ── source morph ── JournalEntry
```

The arrows crossing into Purchasing are read-only. No Purchasing model gets a relationship back to Accounting.

## Persisted entities

### `expenses`

One payable cost without a supplier invoice. Required business fields are a unique expense number, expense date, account, payment method, amount/tax, description, status, optional supplier/requesting employee, approval metadata, and blameable timestamps. Drafts may be edited/deleted and may carry a Spatie Media Library receipt. Approved and paid rows are immutable and undeletable.

Lifecycle: `draft → approved → paid`; `draft → cancelled`.

Posting source: approval recognises the payable; payment clears it.

### `bills`

One supplier invoice. Fields include unique bill number, supplier reference unique per supplier among non-cancelled bills, supplier, optional PO reference for display/navigation, optional payment term, bill/due dates, totals, paid amount, lifecycle status, approval metadata, blameable timestamps, and draft-only soft deletion.

Lifecycle: `draft → approved → partially_paid → paid`; cancellation is allowed only before approval according to the service contract.

The implementation must align the final column names with the approved 022 ERD register. The current dirty `Bill` model's aggregate naming is not accepted as the contract by itself.

### `bill_lines`

One net charge in a bill. Fields: bill, optional purchase-order line, optional product variant, selected postable active chart account, description, quantity, unit price, tax amount, line total, and sort order.

The optional PO-line reference powers the advisory match:

- ordered quantity: `purchase_order_lines.quantity_ordered`;
- cumulative received quantity: completed receipt-operation lines for the referenced PO line;
- cumulative billed quantity: bill lines referencing the same PO line, excluding cancelled bills as defined by the service;
- quantity variance and unit-price variance: computed for display, never a blocking validation.

### `supplier_payments`

Outbound money to one supplier. Fields: unique payment number, supplier, payment method, amount, payment date, reference, status, optional source journal entry, blameable timestamps.

Lifecycle: `draft → paid`; `draft → cancelled`. A paid payment is immutable and undeletable.

### `supplier_payment_allocations`

Append-only allocation evidence linking one supplier payment to one bill and an amount. Allocation writes lock the payment, target bills, and relevant rows, then validate payment total, bill remaining balance, supplier identity, bill status, and non-zero allocation before committing.

## Read-only/computed surface

### Accounts Payable

No `accounts_payable` table and no stored supplier balance. The resource/service computes:

- approved bill totals minus supplier-payment allocations;
- approved unpaid expenses attributable to a supplier;
- aging against due date as of a report date using current, 1–30, 31–60, 61–90, and over-90 buckets;
- supplier detail whose open-document remainders sum to the supplier balance;
- payable control-account balance from posted journal lines;
- explicit tie-out difference, without adjustment or rounding plug.

Soft-deleted suppliers remain represented and are marked as deleted where their documents appear.

## Existing entities reused without modification

- `PurchaseOrder` and `PurchaseOrderLine` provide order context only.
- `InventoryOperation` and its lines provide received quantities only for completed PO receipts.
- `Supplier`, `PaymentTerm`, `PaymentMethod`, `ChartAccount`, `FiscalPeriod`, `JournalEntry`, and `JournalEntryLine` remain existing domain objects.
- `JournalEntry.source_type/source_id` links postings to the originating bill, expense, or supplier payment.

## Integrity rules

1. All financial and allocation mutations are transactional.
2. All approval/payment transitions lock the status row before rechecking state.
3. Monetary calculations use integer minor units at service/aggregation boundaries; decimal strings are presentation values.
4. Approved documents cannot be changed through service, Filament, or direct model writes.
5. No bill line posts to an inventory account; goods bills debit the selected expense account.
6. The customer payment tables are never used for supplier payments.
7. The existing PO/receipt schema remains unchanged.
