# Purchasing & Suppliers Domain

---
status: canonical
owner: purchasing
last_verified: 2026-10-02
verified_against: app/Services/Purchasing, Suppliers/Supply services, Purchase enums/policies, tests/Feature/Purchasing
---

## Purpose

Purchasing owns supplier-facing commercial commitments: suppliers, supplier product references, purchase orders, approval/transmission, supplier confirmation, inbound allocation/provenance and purchasing reports.

Purchasing does not directly own physical stock or the general ledger.

## Main Code Anchors

- `PurchaseOrderService.php`
- `PurchaseOrderApprovalService.php`
- `PurchaseOrderAcceptanceOrchestrator.php`
- `PurchaseInboundService.php`
- `PurchaseInboundStatusService.php`
- `PurchaseOrderReceivingService.php`
- `SupplierConfirmationService.php`
- `SalesDemandProcurementService.php`
- `SupplierCostWritebackService.php`

## Main UI Surfaces

Suppliers, Supplier Product References/Supports, Purchase Orders, Purchase Inbounds, Supplier Confirmations, Purchase Settings and Purchasing Reports.

## Boundary

- Inventory owns stock posting for receipts.
- Accounting owns bills/AP/journal effects.
- Payments owns supplier payment transaction behavior.
- Sales may create procurement requirements; Purchasing sources them without owning the sales order.

## Related Decisions

- [ADR 0006](../../adr/0006-filament-purchasing-dashboard.md)
- [ADR 0011](../../adr/0011-accounting-payables-expenses-bills.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
