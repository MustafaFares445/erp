# Phase 0 Canonical Cross-Module Ownership and Acceptance Flow

**Status:** Authoritative Phase 0 addendum

**Date:** 2026-09-10

**Decision record:** `Docs/adr/0012-origin-domain-owns-business-facts.md`

This document is the canonical Phase 0 correction for the Purchasing → Logistics /
Inventory → Accounting handoff. Where an older paragraph in
`Docs/ERP_DOMAIN_MODEL.md`, `Docs/CROSS_MODULE_BUSINESS_FLOWS.md`, or ADR 0011
conflicts with this addendum, this document and ADR 0012 take precedence for the
touched Phase 0 behavior.

## 1. Ownership matrix

| Business fact | Canonical owner | Other modules may do | Other modules must not do |
|---|---|---|---|
| Supplier on a purchase commitment | Purchasing / `PurchaseOrder.supplier_id` | Accounting derives it for a PO-linked Bill; Inventory references supplier on the physical receipt | Store another independently editable supplier for the same PO-linked payable |
| Accepted purchase price | Purchasing / `PurchaseOrderLine.unit_cost` | Supplier-cost service writes accepted cost to the purchasing supplier reference | Inventory/receipt code calculate or mutate procurement price |
| Destination warehouse for an accepted PO line | Logistics/Inventory / `PurchaseInboundAllocation` | Purchasing displays allocation state and receipt readiness | `purchase_orders` own a destination warehouse |
| Physical receipt, quantities and custody | Inventory / `InventoryOperation` and stock/movement services | Purchasing advances received quantities/status from completed receipt evidence | Acceptance create stock, movements, lots, or a completed receipt |
| Replenishment min/max | Replenishment policy / `WarehouseReplenishmentPolicy` | Inventory evaluates policy against stock | `InventoryStock` own `reorder_level` |
| Payable document | Accounting / `Bill` | PO acceptance requests one Draft Bill as an automatic handoff | Purchasing approve/post/pay the Bill or write ledger state |
| Supplier confirmation requirement | Purchasing / `Supplier.requires_confirmation` | Acceptance opens one Pending workflow when opted in | Inventory catalogue permission change this commercial workflow setting |

## 2. Accepted Purchase Order flow

```text
PurchaseOrder PendingApproval
        |
        | PurchaseOrderApprovalService::approve()
        v
PurchaseOrder Accepted
        |
        | same database transaction
        v
PurchaseOrderAcceptanceOrchestrator
        |
        +--> PurchaseInboundService::ensureForAccepted()
        |       -> one PurchaseInbound
        |       -> one PurchaseInboundLine per PO line
        |       -> no stock movement
        |
        +--> if Supplier.requires_confirmation
        |       SupplierConfirmationService::record()
        |       -> one Pending confirmation workflow
        |
        +--> SupplierCostWritebackService::apply()
        |       -> accepted commercial PO price is the signal
        |       -> no receipt-derived price
        |
        +--> PurchaseOrderDraftBillService::ensureForAccepted()
        |       -> one Draft Bill
        |       -> Bill.purchase_order_id = PO id
        |       -> Bill.supplier_id = null
        |       -> Bill.resolved_supplier_id = PO supplier
        |       -> Bill lines retain purchase_order_line_id provenance
        |
        `--> PurchaseOrderAccepted event
                -> dispatched after commit
                -> WarehouseManage recipients: allocation ready
                -> BillManage recipients: Draft Bill ready for review
```

The entire acceptance-side-effect set is inside the PO approval transaction. If
any synchronous provisioning step fails, the PO remains unaccepted and the
inbound, confirmation, supplier-cost writeback, and Draft Bill roll back with it.
The notification event is after-commit, so users are not told about work that did
not persist.

## 3. Receipt flow after acceptance

Acceptance is not receiving.

1. Inventory/Logistics allocates each `PurchaseInboundLine` to a warehouse.
2. `PurchaseOrderReceivingService::initiate()` resolves that allocation and opens
   a Draft Inventory receipt for outstanding quantities.
3. Inventory owns lot, serial, expiry, package, quantity and custody inputs.
4. `InventoryOperationService` completes the physical receipt and posts canonical
   stock/movement changes.
5. `InventoryOperationCompleted` synchronously advances the PO's received
   quantities and status.
6. Neither receipt initiation nor receipt completion writes
   `SupplierProductReference.purchase_cost`.

## 4. Bill flow after acceptance

The automatically provisioned Bill is only a Draft workflow artefact. It is not a
liability yet.

1. PO acceptance provisions the Draft and copies commercial line provenance.
2. Accounting reviews the supplier invoice/reference and match evidence.
3. Accounting permissions control Bill approval.
4. Only Bill approval invokes Accounting posting and recognises the payable.
5. Supplier payment remains an Accounting operation.

A Purchasing user therefore causes the system handoff but gains no generic Bill
CRUD/approval permission.

## 5. Shared Supplier UI boundary

The Supplier record remains legitimately shared: Inventory may maintain supplier
identity/contact/catalogue data and Purchasing may maintain the commercial
relationship. Shared record access does not imply shared commercial authority.

The following controls are Purchasing-only on the shared Filament resource:

- `requires_confirmation` — requires `purchase.supplier.manage`;
- product-reference repeater including `purchase_cost` — requires
  `purchase.product-reference.manage`.

Inventory catalogue permissions alone must not expose either commercial control.

## 6. Phase 0 invariants

The following are regression failures:

- `destination_warehouse_id` returns to `purchase_orders`;
- `reorder_level` returns to `inventory_stocks`;
- a PO-linked Bill can independently persist `supplier_id`;
- Inventory receipt forms regain `unit_cost`, `purchase_cost`, or
  `last_received_unit_cost` inputs;
- receiving services/listeners write `SupplierProductReference.purchase_cost`;
- Inventory permission names grant Purchasing management;
- the shared Supplier UI exposes commercial controls to an Inventory-only user;
- PO acceptance creates physical stock or movements;
- a retry creates a second inbound, confirmation workflow, or PO Draft Bill.

`tests/Feature/Architecture/Phase0OwnershipTest.php` and the Purchasing acceptance
feature tests are the executable guards for these boundaries.
