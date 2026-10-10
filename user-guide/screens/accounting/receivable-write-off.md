---
title: "Receivable Write Off"
module: accounting
pages: ["index", "create", "view"]
screenshots: ["accounting-receivable-write-off-index", "accounting-receivable-write-off-create", "accounting-receivable-write-off-view"]
lastVerified: 2026-10-07
---

# Receivable write-offs and approval

<IerpPageSummary purpose="Record and approve controlled invoice write-offs without deleting customer debt history." useWhen="A collectible invoice balance is formally determined to be uncollectible." owner="Accounting" />

## When to use this page

A collectible invoice balance is formally determined to be uncollectible.

## Typical tasks

- Follow the documented “Process” instructions below.

<IerpScreenshot id="accounting-receivable-write-off-index" title="Write-off queue" description="Review recorded, approved and related invoice amounts." />
<IerpScreenshot id="accounting-receivable-write-off-create" title="Record a proposed write-off" description="Select an issued invoice, customer, positive amount and reason." imageSrc="/images/en/accounting/accounting-receivable-write-off-create.png" />
<IerpScreenshot id="accounting-receivable-write-off-view" title="Write-off approval context" description="Review source and independent authorization." imageSrc="/images/en/accounting/accounting-receivable-write-off-view.png" />

## Process

1. Select the correct issued invoice and matching customer. The proposed amount must be positive, at most the outstanding balance, and accompanied by a reason.
2. Save the request; it does not immediately erase receivables.
3. Have a different authorized approver review and approve it (maker–checker separation).
4. Approval posts the write-off and applicable tax effects and sets the invoice financial treatment to Written Off. Review the journal reference and financial reporting afterward.

Do not create a credit note or payment solely to simulate a write-off; these are different business effects. Rejection/permission blocks must be resolved through the authorized workflow.
