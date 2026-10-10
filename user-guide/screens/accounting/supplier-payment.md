---
title: "Supplier Payments"
module: accounting
pages: ["index", "edit"]
screenshots: ["accounting-supplier-payment-index", "accounting-supplier-payment-edit"]
lastVerified: 2026-10-07
---

# Supplier payments and payable settlement

<IerpPageSummary purpose="Record and allocate authorized outgoing supplier payments while protecting paid history." useWhen="Settling one or more approved supplier bills." owner="Accounting" />

## When to use this page

Settling one or more approved supplier bills.

## Typical tasks

- Follow the documented “Settlement” instructions below.

<IerpScreenshot id="accounting-supplier-payment-index" title="Supplier payment list" description="Supplier, method, amount and Draft/Paid status."  imageSrc="/images/en/accounting/accounting-supplier-payment-index.png" />
<IerpScreenshot id="accounting-supplier-payment-edit" title="Edit an eligible Draft payment" description="Paid payments are intentionally immutable." imageSrc="/images/en/accounting/accounting-supplier-payment-edit.png" />

## Settlement

1. Begin from a supplier Bill or the **Supplier Payments** list. Select supplier, active payment method, amount and date. Creation records a **Draft** payment.
2. Use **Pay** only on a Draft, selecting bill allocations for the **same supplier**. Only Approved or Partially Paid bills with an outstanding amount can receive an allocation.
3. The confirmed payment posts Accounting history and stores allocations that reduce eligible bill balances. Review the bill's status and payment reference afterward.
4. A Paid supplier payment cannot be edited, deleted or reset to Draft. Use an explicitly authorized corrective workflow instead of direct edits.

**Blocked screenshot:** Supplier Payment Edit requires a legitimate Draft record; seeded Paid payments must not be reopened just to capture an image.
