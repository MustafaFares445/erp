---
title: "Purchase Orders"
module: vendors
pages: ["index", "create", "view", "edit"]
screenshots: ["vendors-purchase-order-index", "vendors-purchase-order-create", "vendors-purchase-order-view", "vendors-purchase-order-edit"]
lastVerified: 2026-10-07
---

# Purchase Orders

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Operator steps and checks” instructions below.
- Follow the documented “Screen references” instructions below.
- Follow the documented “List” instructions below.

> Ownership: Purchasing records commercial supplier commitments; Inventory posts physical quantities; Accounting and Payments manage AP and settlement.

## Operator steps and checks

**Purchase Orders are commercial commitments, not stock receipts.** Use the list's status views, favorites, Group by, search and filters (ready to send, awaiting supplier, overdue, accounting issues) to locate action-ready POs.

**Create Draft:** choose the supplier before entering lines. Verify the currency and active supplier-product references, purchase UOM, conversion factor, MOQ and costs. Add product variants, positive ordered quantities, dates, terms and notes. Supplier or currency cannot silently change after commercial lines exist. A Draft can be edited; after submission, follow the workflow action rather than editing locked commercial data.

**Submit → Approve → Send:** submit validates complete terms and evaluates the matching-currency threshold. Qualifying positive thresholds may auto-accept; otherwise a separately authorized approver accepts, with maker/checker enforced except the documented System Admin exemption. **Return for revision** records a reason and returns the PO to Draft. Acceptance alone generates **no inbound, supplier confirmation, AP bill or physical receipt**. The **first Send** records transmission and provisions Purchase Inbound, optional supplier confirmation, supplier-cost writeback and an Accounting **Draft Bill**. Resending must not duplicate these.

**Receive:** open the inbound execution, allocate exact base quantity to the correct warehouse, then open the purchase-linked Inventory Receipt. **Draft/Ready** do not change on-hand. Only Inventory **Done** posts received quantities and updates PO/inbound progress. The PO must not exceed ordered or allocated quantities. **Supplier confirmation** tracks committed/backordered quantities and dates; a rejection or partial commitment remains visible follow-up work.

**Financial follow-up:** open the related Bill in Accounting for review, posting and AP; supplier payment/allocation is performed by the financial owner, not by this PO action.

**Short close / Cancel:** inspect open receipts, completed receipts, active bills and unfulfilled Sales demand. Short-close requires no open receipt; cancellation is blocked by completed receipts, open receipts or non-draft active bills. Eligible draft bills are cancelled with an eligible PO cancellation; unfulfilled linked demand is requeued without deleting history.

**Check before any final action:** current status, role permission, affected quantity or amount, modal explanation and the next owning module.

## Screen references

### List

<IerpScreenshot id="vendors-purchase-order-index" title="Purchase Orders — List" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Orders"  imageSrc="/images/en/vendors/vendors-purchase-order-index.png" />

### Create

<IerpScreenshot id="vendors-purchase-order-create" title="Purchase Orders — Create" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Orders"  imageSrc="/images/en/vendors/vendors-purchase-order-create.png" />

### Record details

<IerpScreenshot id="vendors-purchase-order-view" title="Purchase Orders — Record details" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Orders"  imageSrc="/images/en/vendors/vendors-purchase-order-view.png" />

### Edit

<IerpScreenshot id="vendors-purchase-order-edit" title="Purchase Orders — Edit" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Orders" />

See also: [Procure to Pay](/workflows/procure-to-pay).
