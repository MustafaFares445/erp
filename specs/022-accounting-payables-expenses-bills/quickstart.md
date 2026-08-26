# Quickstart Validation: Accounting Payables

This guide is executable only after the 022 governance gate is cleared. Until then it is a validation target, not authorization to implement or run migrations against a shared database.

## Prerequisites

1. ADR 0011 is `Accepted`.
2. ADR 0006 is amended exactly for the Accounting-side PO/PO-line read reference.
3. The constitution amendment and ERD divergence register are merged.
4. The existing dirty worktree has been reviewed so unrelated changes are preserved.
5. MySQL is available and the application test database is configured.

## Focused automated validation

Run the feature tests after implementation:

```powershell
php artisan test --compact tests/Feature/Accounting/PayablesPermissionTest.php tests/Feature/Accounting/ExpenseLifecycleTest.php tests/Feature/Accounting/BillPurchaseOrderMatchTest.php tests/Feature/Accounting/SupplierPaymentTest.php tests/Feature/Accounting/AccountsPayableTest.php
```

Then verify the architectural and prerequisite regressions:

```powershell
php artisan test --compact tests/Unit/ArchTest.php tests/Feature/Purchasing/PurchaseOrderReceivingTest.php tests/Feature/Purchasing/PurchaseOrderOverReceiptTest.php
```

Finally run formatting and static checks appropriate to the final diff:

```powershell
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test
```

## End-to-end scenarios

1. Create and transmit a PO for two lines. Confirm the PO has no bill, payable, payment, or journal entry.
2. Use the PO Receive action. Confirm a draft `InventoryOperation` points to the PO through `source_document`, is prefilled with outstanding quantities, and does not change stock until completed.
3. Complete a partial receipt. Confirm PO `quantity_received`, stock, and the receipt operation commit together.
4. Record a bill with lines matched to PO lines. Confirm ordered/received/billed quantities and quantity/price variances are visible and advisory.
5. Approve the bill as a different user. Confirm one balanced source-linked journal entry: expense debits + input-tax debit = payable credit. Confirm closed fiscal periods reject approval atomically.
6. Record and approve an expense, then pay it. Confirm the second source-linked entry clears the payable control account and credits the payment method account.
7. Record one supplier payment allocated across multiple approved bills. Confirm allocation and bill status updates are atomic, cannot overpay, and produce one balanced source-linked payment entry.
8. Open Accounts Payable. Confirm supplier aging, detail totals, approved unpaid expenses, soft-deleted supplier labels, and the payable-control-account tie-out.
9. Deliberately create a tie-out mismatch in a test fixture. Confirm the exact difference is displayed as an error and no balance is adjusted.
10. Verify reviewer/read-only and recorder/approver separation, duplicate supplier invoice validation, immutable posted documents, empty-state rendering, and CSV permission gates.

## Expected boundary proof

The final diff must show no modified Purchasing class/table/column/surface for payables. The only PO-side behavior retained is the already-built receipt relation and receiving flow; the new accounting reference originates at `bill_lines` and is consumed by Accounting services/resources.
