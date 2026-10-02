# Purchasing Workflows

---
status: canonical
owner: purchasing
last_verified: 2026-10-02
verified_against: PurchaseOrderApprovalService, PurchaseOrderAcceptanceOrchestrator, PurchaseInbound services
---

## Purchase Order

1. Create/edit Draft and lines.
2. Submit.
3. Below matching-currency threshold: auto Accepted; otherwise Pending Approval.
4. Explicit approval moves to Accepted.
5. First Send activates downstream execution.
6. Allocate accepted inbound quantities to warehouses.
7. Open Inventory receipt operations.
8. Inventory completes physical receipts.
9. Purchasing synchronizes received quantities/status.
10. PO reaches Partially Received / Received, or is short-closed/cancelled under guards.

## First-Send Activation

`Purchasing -> PurchaseInbound + optional SupplierConfirmation + supplier cost writeback + Accounting Draft Bill + replenishment coverage`

This occurs at first Send, not approval.

## Receipt

`PO / Inbound Allocation -> Draft Inventory Receipt -> Inventory Ready -> Inventory Done -> Purchasing received progress`

Purchasing never increments warehouse stock directly.

## Sales Shortage Procurement

`Released Sales Order -> Sales procurement requirement -> eligible supplier -> Purchasing draft PO -> normal PO lifecycle -> completed receipts -> requirement fulfilled`

If a PO fails/short-closes, old demand history is preserved and only the outstanding quantity is requeued.

## Cancellation

Cancellation is allowed only before physical receipt and without open receipt/active bill conditions that would make the state inconsistent. Eligible draft Accounting bills are cancelled as part of the PO cancellation path.
