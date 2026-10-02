# Purchasing Testing

---
status: canonical
owner: purchasing
last_verified: 2026-10-02
verified_against: tests/Feature/Purchasing
---

## Test Location

`tests/Feature/Purchasing/`

## Critical Coverage

The suite covers:

- PO draft creation/immutability/numbering;
- approval threshold, maker-checker and send activation;
- supplier confirmation;
- inbound allocation/status and multi-warehouse receipt;
- over-receipt guards;
- Inventory receiving integration;
- supplier cost writeback;
- Sales shortage follow-up;
- permissions/navigation/resources/forms;
- reports and document/media behavior;
- cancellation/close/remediation guards.

Representative tests: `PurchaseOrderApprovalTest`, `PurchaseOrderAcceptanceTest`, `PurchaseOrderReceivingTest`, `PurchaseInboundReceivingTest`, `PurchaseMultiWarehouseInboundEndToEndTest`, `PurchaseOrderOverReceiptTest`, `SupplierBackorderFollowUpTest`, and `PurchasingWorkflowRemediationTest`.
