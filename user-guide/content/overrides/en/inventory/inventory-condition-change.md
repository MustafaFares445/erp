# Inventory Condition Changes

<IerpPageSummary purpose="Track quarantine release, damage, recovery and disposal as explicit inventory documents." useWhen="Preparing dependent condition-change form fields and reviewing posted evidence." owner="Inventory" />

## When to use this page

Preparing dependent condition-change form fields and reviewing posted evidence.

## Typical tasks

- Follow the documented “What this document changes” instructions below.
- Follow the documented “Process by type” instructions below.
- Follow the documented “Checks and exceptions” instructions below.

## What this document changes

Use condition changes to document stock moving between saleable, quarantined and damaged states or leaving warehouse custody permanently. The form offers four document types: **Quarantine Disposition**, **Damage**, **Damage Recovery**, and **Disposal**. Each has different eligibility and dependent fields; selecting a type must change the visible form before submission.

## Process by type

1. Open **Inventory → Operations → Condition Changes**, choose **New inventory condition change** and select the document type.
2. **Quarantine Disposition:** identify quarantined stock and its lot/serial where applicable. Select the inspection outcome: return to Saleable, mark Damaged, dispose, or return to supplier as offered. Record the quantity and reason.
3. **Damage:** select the affected variant, warehouse and tracking identity; move only eligible stock to Damaged with a reason.
4. **Damage Recovery:** choose the original damage document to reverse eligible damaged quantity. The recovery source determines dependent stock identity; do not invent a different product or warehouse.
5. **Disposal:** identify eligible stock for destruction/removal, enter quantities, reason and required authorization. Verify the irreversible stock effect before posting.
6. Save/inspect the Draft, then post or cancel only when authorized and permitted by status. Reconcile the resulting condition balances and movement provenance.

## Checks and exceptions

Check the required reasons, quantities, disposition, original damage reference and authorizer fields when exposed. Empty or unsupported combinations must be rejected. Posting changes stock condition and potentially available/on-hand quantities, not financial credit notes or refunds. A posted change is not silently editable.

Related: [Stock Levels](/screens/inventory/stock-level) and [Stock Movements](/screens/inventory/stock-movement).

## Reviewed dashboard screens

<IerpScreenshot id="inventory-inventory-condition-change-index" imageSrc="/images/en/inventory/inventory-inventory-condition-change-index.jpg" title="Stock condition change queue" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-inventory-condition-change-create" imageSrc="/images/en/inventory/inventory-inventory-condition-change-create.jpg" title="Condition-specific creation form" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-inventory-condition-change-view" imageSrc="/images/en/inventory/inventory-inventory-condition-change-view.jpg" title="Posted condition and movement evidence" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

## View: condition disposition and approval

The View panel reports lifecycle state and next step. Review change type (quarantine disposition, damage, recovery or disposal), SKU, warehouse, lot/serial, from/to stock condition, quantity, reason, inspector and posting metadata. A Draft does not alter the condition ledger; posting is authorized through its own workflow.

