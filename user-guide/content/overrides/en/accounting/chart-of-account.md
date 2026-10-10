# Chart of accounts and ledger lookup

<IerpPageSummary purpose="Maintain controlled ledger-account hierarchy and inspect posted account activity." useWhen="Creating an eligible posting account or investigating which journal entries affect an account." owner="Accounting" />

## When to use this page

Creating an eligible posting account or investigating which journal entries affect an account.

## Typical tasks

- Follow the documented “Safe use” instructions below.

<IerpScreenshot id="accounting-chart-of-account-index" title="Account list and hierarchy" description="Find account codes, types and active/postable state." imageSrc="/images/en/accounting/accounting-chart-of-account-index.png" />
<IerpScreenshot id="accounting-chart-of-account-create" title="Create account" description="Choose a unique code, classification and permitted parent." imageSrc="/images/en/accounting/accounting-chart-of-account-create.png" />
<IerpScreenshot id="accounting-chart-of-account-view" title="Account and posted ledger" description="Review account details and its related journal entries." imageSrc="/images/en/accounting/accounting-chart-of-account-view.png" />
<IerpScreenshot id="accounting-chart-of-account-edit" title="Edit account configuration" description="Respect active/postable rules and existing usage." imageSrc="/images/en/accounting/accounting-chart-of-account-edit.png" />

## Safe use

Use searchable account codes to find the correct asset, liability, equity, income or expense classification. Only active postable accounts are eligible for new journal postings. Parent/group accounts are for hierarchy and totals, not direct transaction lines.

Before changing a live account, inspect its postings and dependent payment methods/settings. Deactivating an account affects future selection but must never rewrite earlier journal history. The View page's ledger relation is read-only; corrections happen through authorized source/reversal workflows.
