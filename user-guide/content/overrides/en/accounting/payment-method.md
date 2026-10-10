# Payment methods and bank-account mapping

<IerpPageSummary purpose="Configure payment channels and their accounting collection accounts." useWhen="Preparing manual collection, supplier disbursement or bank reconciliation." owner="Shared Settings and Accounting" />

## When to use this page

Preparing manual collection, supplier disbursement or bank reconciliation.

## Typical tasks

- Follow the documented “Payment methods” instructions below.
- Follow the documented “New payment method” instructions below.
- Follow the documented “Payment method detail” instructions below.

<IerpScreenshot id="accounting-payment-method-index" title="Payment methods" description="Available manual/provider channels and active state." imageSrc="/images/en/accounting/accounting-payment-method-index.png" />
<IerpScreenshot id="accounting-payment-method-create" title="New payment method" description="Select the channel and accounting mapping." imageSrc="/images/en/accounting/accounting-payment-method-create.png" />
<IerpScreenshot id="accounting-payment-method-view" title="Payment method detail" description="Inspect the mapping and usage context." imageSrc="/images/en/accounting/accounting-payment-method-view.png" />
<IerpScreenshot id="accounting-payment-method-edit" title="Edit payment method" description="Change only permitted current configuration." imageSrc="/images/en/accounting/accounting-payment-method-edit.png" />

A manual payment requires an active method. Posting debits the method's designated collection account; bank statement import additionally requires the linked chart account to be active and postable. Method setup must precede transactions, not be retroactively altered to conceal historic posting. Confirm mapping and supported proof requirements before a payment is posted. Provider-dependent Stripe behavior is not equivalent to manually marking a bank receipt paid.
