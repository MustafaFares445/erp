# Supplier bills and accounts payable

<IerpPageSummary purpose="Review supplier invoices, approve liabilities and initiate controlled supplier settlement." useWhen="An accepted and sent purchase order has created a Draft Bill or an existing supplier invoice needs review." owner="Accounting" />

## When to use this page

An accepted and sent purchase order has created a Draft Bill or an existing supplier invoice needs review.

## Typical tasks

- Follow the documented “Purchase-to-pay responsibilities” instructions below.

<IerpScreenshot id="accounting-bill-index" title="Supplier bills" description="Identify draft, approved, partially paid and settled bills."  imageSrc="/images/en/accounting/accounting-bill-index.png" />
<IerpScreenshot id="accounting-bill-view" title="Bill amounts and status" description="Review lines, supplier references and authorized actions."  imageSrc="/images/en/accounting/accounting-bill-view.png" />
<IerpScreenshot id="accounting-bill-edit" title="Editable supplier bill" description="Changes are restricted by bill state and permission." imageSrc="/images/en/accounting/accounting-bill-edit.png" />

## Purchase-to-pay responsibilities

A Purchase Order's first **Send** may provision a Draft Bill. Purchasing owns commercial supplier commitment; Inventory owns receipt quantities. Neither approval nor receipt directly authorizes an unreviewed change to the ledger.

1. Open the Bill and compare supplier, supplier invoice reference, lines, tax, expense allocation and currency with its source order and receipt context.
2. **Approve** only after reviewing amount and permitted accounting fields. Accounting posts the approved payable through its central journal posting service.
3. Use **Record supplier payment** to initiate a Draft disbursement, then **Pay** it with valid allocations to settle the bill.
4. Check the bill's outstanding amount and AP report after partial and final payment. Paid or posted history must not be silently edited.

Related: [Accounts payable](/screens/accounting/accounts-payable), [Supplier payments](/screens/accounting/supplier-payment).
