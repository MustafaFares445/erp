---
title: "Fiscal Periods"
module: accounting
pages: ["index", "create", "view", "edit"]
screenshots: ["accounting-fiscal-period-index", "accounting-fiscal-period-create", "accounting-fiscal-period-view", "accounting-fiscal-period-edit"]
lastVerified: 2026-10-07
---

# Fiscal periods and controlled close

<IerpPageSummary purpose="Control which accounting dates accept postings and execute auditable period-close checks." useWhen="Opening a fiscal period or completing a month-end close." owner="Accounting" />

## When to use this page

Opening a fiscal period or completing a month-end close.

## Typical tasks

- Follow the documented “Before closing” instructions below.
- Follow the documented “Operational close sequence” instructions below.

## Before closing

A fiscal period must not overlap another. Ledger posting requires an open period at the entry date.

<IerpScreenshot id="accounting-fiscal-period-index" title="Fiscal period list" description="Open and closed periods, checklist and close actions." imageSrc="/images/en/accounting/accounting-fiscal-period-index.png" />
<IerpScreenshot id="accounting-fiscal-period-create" title="New fiscal period" description="Define non-overlapping dates." imageSrc="/images/en/accounting/accounting-fiscal-period-create.png" />
<IerpScreenshot id="accounting-fiscal-period-view" title="Period close and checks" description="Read the persisted checklist result before acting." imageSrc="/images/en/accounting/accounting-fiscal-period-view.png" />
<IerpScreenshot id="accounting-fiscal-period-edit" title="Open period edit" description="Only editable facts can be changed." imageSrc="/images/en/accounting/accounting-fiscal-period-edit.png" />

## Operational close sequence

1. Complete outstanding bills, invoices, refunds and posting reconciliation.
2. Select **Run checklist**; this explicitly saves fresh check results. Opening the dashboard does not re-run them.
3. Review mandatory failures. Resolve them; an override requires separate permission, an explanation and an audit trail.
4. Select **Close** and inspect the confirmation. The service reruns checks at final submission; stale displayed results cannot bypass it.
5. **Reopen** requires a separate permission and is audited. Neither a closed period nor previously posted history should be silently edited.

Avoid creating artificial journals to force a pass. Trace discrepancies to their source documents.
