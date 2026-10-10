# Receipt Corrections

<IerpPageSummary purpose="Compensate an already posted receipt without rewriting original movement history." useWhen="Reviewing a completed source and its correction document." owner="Inventory" />

## When to use this page

Reviewing a completed source and its correction document.

## Typical tasks

- Follow the documented “Purpose” instructions below.
- Follow the documented “Operator procedure” instructions below.
- Follow the documented “Safety rules” instructions below.

## Purpose

Use a correction to address a mistake discovered **after** an original receipt, delivery or transfer movement has been posted. Completed source operations and original movements are historical evidence; they are not edited or deleted to force a new stock total. The visible Receipt Corrections queue focuses on receipt-origin corrections.

## Operator procedure

1. Open **Inventory → Operations → Receipt Corrections** and inspect the original completed receipt.
2. Choose **New Receipt Correction** if authorized. Select a valid posted source and enter the required correction reason.
3. Review the proposed correction lines, original quantities and movement provenance; a correction cannot substitute an unrelated or unposted source.
4. Check the Draft and post through the controlled action. Confirm that compensating movement records are linked to the original receipt and the correction.
5. Reconcile the quantities in **Stock Movements** and retain the original receipt and audit trail. Cancel only when the correction lifecycle and permissions allow it.

## Safety rules

Posting/cancellation is restricted to valid document states. Posted and cancelled corrections must not be posted again or silently rewritten. Creating a correction alone does not alter stock. Receipt correction addresses physical custody, not supplier invoice adjustment or automatic financial settlement.

Related: [Inventory Operations](/screens/inventory/inventory-operation) and [Stock Movements](/screens/inventory/stock-movement).

## Reviewed dashboard screens

<IerpScreenshot id="inventory-inventory-correction-index" imageSrc="/images/en/inventory/inventory-inventory-correction-index.jpg" title="Receipt corrections queue" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-inventory-correction-view" imageSrc="/images/en/inventory/inventory-inventory-correction-view.jpg" title="Correction source and compensating movement" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

## View: receipt correction provenance

The View panel precedes the correction details. Review original completed Receipt, reason and correction lines. A Draft is not posted; Posted records preserve a traceable correction to the original receipt. Do not edit completed historical inventory movements directly.

