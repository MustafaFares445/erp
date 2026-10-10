# Sales financial posting settings

<IerpPageSummary purpose="Inspect protected tax defaults and financial account mappings used by Sales and Payments." useWhen="Validating invoice posting, deferred tax, AR, customer deposits or bad-debt configuration." owner="System Administration / Accounting" />

## When to use this page

Validating invoice posting, deferred tax, AR, customer deposits or bad-debt configuration.

## Typical tasks

- Follow the documented “Sales financial settings” instructions below.

<IerpScreenshot id="accounting-sales-setting-index" title="Sales financial settings" description="Default tax, quotation validity and posting account mappings." imageSrc="/images/en/accounting/accounting-sales-setting-index.png" />

These singleton settings affect invoice receivable/revenue/deferred-tax accounting, payable tax, customer deposits and write-off expense mapping. Access and editing are restricted to the authorized System Admin. Before changing a posting account, review chart-account activity, past source postings, payment-method mappings and open fiscal periods. Updating the settings changes future processing rules; it must not rewrite previously posted entries. Test financial effects using authorized invoice/payment actions, not a direct journal edit.
