# ADR 0006: Adopt the Existing Filament Dashboard for the Purchasing Module

**Status**: Accepted — amended by ADR 0011 and ADR 0012

**Date**: 2026-08-18

**Deciders**: Project Owner

**Related**: `specs/017-purchasing-orders-suppliers/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`, ADR 0001 (Inventory), ADR 0005 (Activitylog), ADR 0011 (Accounting payables), ADR 0012 (Origin Domain Owns the Business Fact), and the IERP Constitution Product Scope & Boundaries section

## Context

The constitution's Product Scope & Boundaries section permits a Filament
dashboard dependency only for the Inventory module (ADR 0001), the CRM module
(ADR 0002), the Employees module (ADR 0003), and the Support and Maintenance
module (ADR 0004). The Purchasing module — what we order from suppliers, what
they commit to, and what actually arrives — has no scaffolding built yet, but
the `/admin` panel already reserves the navigation group, two resource links,
and their English labels (`app/Filament/AdminModuleRegistry.php` group
`purchasing`, `lang/en/admin.php`). Opening the module today renders the shared
placeholder page.

The documented design treats purchasing thinly. `Docs/PRD.md` describes
Supplier Management and states in §9 that supplier confirmations are manually
updated by an admin; §11 places a supplier-facing portal out of scope. The ERD
carries `suppliers`, `supplier_product_references`, and a
`supplier_confirmations` table keyed to a customer order, plus the
`pending_supplier_confirmation` / `supplier_confirmed` / `supplier_rejected`
values in the orders status catalog. Read literally, the documented purchasing
flow is one thing only: a customer order cannot be filled from stock, an admin
asks a supplier, and the answer is recorded against that order.

**The ERD has no purchase-order entity at all.** There is no document
representing goods we order from a supplier, no ordered quantity, and no line
prices. This is the central tension this ADR must resolve, and there is
evidence on both sides.

Against inventing one: the ERD is canonical under Principle I, and the
documented flow works without it.

For inventing one: the built code already anticipates it. The
`InventoryOperation.source_document` morph — built in the Inventory operations
feature and live today — carries a docblock reading *"The originating
commercial document — a purchase order for a receipt, a sales delivery note for
a delivery"*. The outbound half of that morph is in production:
`OrderFulfillmentService` sets `source_document_type => Order::class` when a
delivery operation is raised. The inbound half has no writer anywhere in the
codebase, because the document it was built to reference does not exist. The
`inventory_operations` table also carries `supplier_id` and
`supplier_reference` columns whose only current writer is
`InventoryOperationBackfiller`, which copies them forward from legacy
`inventory_receipts` rows; no live user-facing flow populates them, because
only a purchasing flow would. Without a purchase order, the inbound morph has
nothing to point at, and
received stock has no ordered baseline to reconcile against — there is no way
to answer "did we get what we ordered?"

The risk profile is the sharpest of any module approved so far. Purchase orders
commit company money, and receiving writes real stock. Principle III
(NON-NEGOTIABLE) requires every stock-changing action to create an inventory
movement against `product_variant_id + warehouse_id`. A purchasing module that
posted its own stock would be a second, parallel receiving path — precisely the
divergent-code-path failure Principle III exists to prevent.

There is also an ordering problem. A purchasing module normally implies
accounts payable: you order goods, you owe money, you post a payable and
eventually a payment. That would depend on `006-chart-of-accounts-and-journals`
and `008-payments-stripe-manual-tax-recognition`, both unbuilt, and the
constitution forbids skipping a feature's prerequisites. Purchasing also has no
entry of its own anywhere in the documented extraction order.

## Decision

Use the existing `/admin` Filament dashboard for the Purchasing module, limited
to dashboard operations. The two resources already pinned in
`AdminModuleRegistry` — Purchase Orders and Supplier Confirmations — are backed
by real domain models and services in this feature.

A **Purchase Order** entity is authorised as a deliberate, registered extension
to the ERD, on the grounds that `InventoryOperation.source_document` was built
for it and cannot otherwise be used inbound. The extension is not treated as a
correction to the ERD but as an addition that must be written back into it
before implementation begins.

The original 017 implementation authorised purchase-order creation, numbering,
priced lines, approval, supplier communication, confirmations, receiving through
the existing Inventory operation services, supplier-product-reference cost
writeback, reports, and Purchasing roles/permissions. Its initial lifecycle and
ownership details are historical after the Phase 0 amendment below; ADR 0012 is
controlling where they conflict.

**Not authorised by this ADR**: any API surface, dashboard-facing or
customer-facing; a supplier-facing portal, which remains independently out of
scope under Product Scope & Boundaries and is **not** relaxed here; purchase
requisitions or requests for quotation; Purchasing-owned accounts payable,
payments to suppliers, journal entries, revenue or expense recognition, and
purchase-tax recognition; landed-cost allocation across freight, duty, or
insurance; supplier returns or debit notes; currency conversion, exchange-rate
management, or revaluation beyond storing a currency code; moving-average or
FIFO cost recalculation of on-hand stock; supplier performance scoring;
automatic reorder-point purchasing or demand forecasting; outbound email or EDI
transmission of a purchase order to a supplier; and blanket or scheduled
purchase agreements. Implementing any of these later requires its own
specification and either a separate ADR or an explicit amendment to this one.

Because supplier communication belongs outside the system, recording that an
order was sent is communication/audit metadata. Phase 0 makes `Accepted`, not
`sent_at`, the downstream activation and receiving prerequisite. Supplier
confirmations remain records of supplier answers, with automatic creation only
for suppliers explicitly configured to require that workflow under ADR 0012.

Receiving posts every stock change through the existing Inventory operation
services, inside the transaction that service already runs. This module never
writes `inventory_stocks` or `inventory_movements` and never introduces a
second receiving path. A static architecture test enforces this rather than
leaving it to review. Purchase-order received quantities advance in that same
transaction under a row lock, so cumulative received quantity can never exceed
the ordered quantity, including under concurrent receipt completion. No
purchasing role gains Inventory dashboard access; the module's own
`purchase.order.receive` permission authorises the action and the Inventory
service performs the write.

Supplier confirmations are **append-only** once answered. A correction is
recorded as a new confirmation so the original answer survives as evidence. The
confirmation target is polymorphic, serving both the customer back-order flow
and purchase-order acknowledgement through one entity.

All purchase-order, approval, confirmation, receiving, and commercial-cost
mutations are routed through domain services, using Spatie Activitylog, Spatie
Permission, and the existing Inventory services — no parallel audit store,
permission store, or stock writer. Cross-module acceptance side effects are
owned by `PurchaseOrderAcceptanceOrchestrator` as amended by ADR 0012.

This feature adds two fixed dashboard roles, **Purchasing Manager** and
**Purchasing Officer**, to the existing `DashboardRole` catalogue, alongside
the existing System Admin and Reviewer roles which it reuses.

This approval is limited to dashboard UI; no supplier-facing portal is implied.

The constitution's Specification Governance extraction order contains **no**
entry for purchasing. This work is therefore an owner-prioritised addition to
that order rather than a reordering of it, delivered as
`017-purchasing-orders-suppliers`.

### ERD extensions authorised by this decision

The documented ERD originally carried no purchase-order document and modelled
supplier confirmation against a customer order only. The original extension
created the purchase-order and confirmation structures. Phase 0 subsequently
corrected their ownership as follows:

1. `purchase_orders` stores the commercial commitment and lifecycle, but **no
   destination warehouse**. Warehouse destination is selected after acceptance
   through `PurchaseInbound` / `PurchaseInboundAllocation`.
2. `purchase_order_lines` stores product/unit, ordered and cumulative received
   quantity, frozen accepted `unit_cost`, line total, and supplier-reference
   provenance. Receipt-derived `last_received_unit_cost` is not an Inventory
   writeback source.
3. `supplier_confirmations` uses a `confirmable_type` / `confirmable_id` morph,
   serving purchase orders and customer orders.
4. `supplier_confirmations` carries one canonical `confirmation_status` plus
   promised/answer evidence rather than a duplicated generic status.
5. `purchase_settings` owns the approval threshold amount and currency.
6. Accepted PO warehouse routing is represented by the
   `purchase_inbounds` / `purchase_inbound_lines` /
   `purchase_inbound_allocations` aggregate rather than a warehouse foreign key
   on the PO.

Separately, the `orders` table may carry its customer-back-order metadata. The
Purchasing feature does not acquire unrelated Sales or Accounting columns by
convenience.

Every other structure follows the accepted specifications and later ADRs.

### Amendment accepted by ADR 0011 (2026-08-26)

ADR 0006 was amended to permit the Accounting module to hold a reference to a
purchase order and purchase-order line when recording a supplier bill.
Accounting may derive ordered, received, and cumulatively billed quantities from
that reference for an advisory three-way match.

ADR 0012 further amends the old one-way wording below: Accounting still owns the
payable and all financial approval/posting, but an accepted PO may now request
one Accounting-owned Draft Bill as an automatic workflow handoff.

### Phase 0 ownership amendment accepted by ADR 0012 (2026-09-10)

The original implementation mixed commercial, physical, routing, and accounting
facts across modules. Phase 0 replaces those details with the immutable rule
**origin domain owns the business fact**.

For Purchasing specifically:

- lifecycle activation is `Accepted`; `sent_at` is communication metadata and is
  never required for receiving;
- a PO owns no warehouse. Acceptance creates/activates one `PurchaseInbound`,
  and Logistics/Inventory owns line-to-warehouse allocation;
- `InventoryOperationLine` carries no procurement monetary value. Receipt
  completion updates physical/received quantity only and cannot write
  `SupplierProductReference.purchase_cost`;
- supplier commercial cost writeback uses the PO line's frozen accepted price
  and occurs at acceptance through `SupplierCostWritebackService`;
- an accepted PO may provision **one Accounting-owned Draft Bill** through
  `PurchaseOrderDraftBillService`. That Bill derives supplier from the PO and
  retains `purchase_order_line_id` provenance. This does not grant the
  Purchasing actor Bill-management permission and does not recognise a
  liability; only Accounting approval/posting does that;
- automatic supplier-confirmation workflow is explicit: it is opened only when
  `Supplier.requires_confirmation` is true, and the default is false;
- `PurchaseOrderAcceptanceOrchestrator` is the single acceptance-time call site
  for inbound activation, optional confirmation, commercial-cost writeback, and
  Draft-Bill provisioning. Its `PurchaseOrderAccepted` event is dispatched after
  commit for workflow notifications;
- acceptance itself creates no inventory operation, stock balance, inventory
  movement, lot, or serial record.

This amendment supersedes every earlier sentence in ADR 0006 that assigns a
destination warehouse to the PO, treats `Sent` as a lifecycle gate, derives
commercial cost from receiving, or absolutely prohibits a Purchasing-triggered
Draft-Bill handoff. It does **not** permit Purchasing to approve/post/pay Bills,
write journal entries, recognise tax, or bypass Accounting authorization.

See `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` for the executable flow and ADR 0012
for the controlling ownership rule.

## Consequences

- The constitution's Product Scope & Boundaries section gains a fifth narrow
  Filament dashboard exception, alongside ADR 0001 (Inventory), ADR 0002 (CRM),
  ADR 0003 (Employees), and ADR 0004 (Support and Maintenance).
- The purchase-order document remains the commercial commitment, while warehouse
  routing is a post-acceptance Logistics/Inventory concern rather than PO-owned
  data.
- The `InventoryOperation.source_document` morph is used inbound for physical
  receipts; physical receipt and stock correctness stay governed exclusively by
  the Inventory operation services.
- Existing Spatie Activitylog, Spatie Permission, Inventory operation services,
  and `TracksBlameable` infrastructure remain canonical and are extended, not
  duplicated.
- No API surface and no supplier-facing portal is introduced.
- Accounting owns every financial consequence. Acceptance can provision one
  Draft Bill, but only Accounting can approve/post it and recognise the payable;
  Purchasing cannot create arbitrary Bills or post ledger/tax/payment state.
- Stock correctness stays governed by Principle III: Purchasing never writes
  stock directly, every receipt produces inventory movements through the
  Inventory services, and architecture tests prove the absence of a second
  path.
- Supplier commercial price is no longer coupled to physical receipt. The
  accepted PO price is the commercial signal; Inventory remains quantity/custody
  only.
- Shared Supplier UI access does not imply shared commercial authority: supplier
  reference prices and confirmation policy require Purchasing permissions even
  when an Inventory catalogue user can maintain shared supplier identity data.
- Two fixed dashboard roles remain in `DashboardRole`, with fine-grained module
  permissions governing the actual actions.
- Any future change that moves ownership of these facts must explicitly amend
  ADR 0012 rather than adding a duplicated field or hidden writeback.
