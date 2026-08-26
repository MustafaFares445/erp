# ADR 0006: Adopt the Existing Filament Dashboard for the Purchasing Module

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Project Owner

**Related**: `specs/017-purchasing-orders-suppliers/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`, ADR 0001 (Inventory), ADR 0005 (Activitylog), and the IERP Constitution Product Scope & Boundaries section

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

**Authorised** (as `/admin` Filament dashboard surfaces only): purchase-order
creation, numbering, and priced lines; unit costs, currency, and stored line
and document totals defaulted from `supplier_product_references`; the
purchase-order lifecycle `draft → pending approval → approved → sent →
partially received → received`, with rejection, short-close, and cancellation;
a configurable value-threshold approval gate with separation of duties;
marking an approved order as transmitted to the supplier, after which its
supplier, warehouse, currency, lines, quantities, and costs are immutable;
supplier confirmations recorded manually by an admin against either a purchase
order or a customer order, carrying a promised date; receiving posted through
the existing Inventory operation services with the purchase order as the
operation's source document, supporting partial receipts and rejecting
over-receipt; writeback of received costs to supplier product references; a
first-class supplier product reference management surface; purchasing reports;
and dashboard roles and permissions for the module.

**Not authorised by this ADR**: any API surface, dashboard-facing or
customer-facing; a supplier-facing portal, which remains independently out of
scope under Product Scope & Boundaries and is **not** relaxed here; purchase
requisitions or requests for quotation; supplier bills, accounts payable,
payments to suppliers, journal entries, revenue or expense recognition, and
purchase-tax recognition; landed-cost allocation across freight, duty, or
insurance; supplier returns or debit notes; currency conversion, exchange-rate
management, or revaluation beyond storing a currency code; moving-average or
FIFO cost recalculation of on-hand stock; supplier performance scoring;
automatic reorder-point purchasing or demand forecasting; outbound email or EDI
transmission of a purchase order to a supplier; and blanket or scheduled
purchase agreements. Implementing any of these later requires its own
specification and either a separate ADR or an explicit amendment to this one.

Because supplier communication belongs outside the system, this feature treats
a purchase order as **transmitted by a human and recorded in the dashboard**.
Marking an order sent records a timestamp; it sends nothing. Supplier
confirmations are likewise the admin's record of an answer received by phone or
email, consistent with `Docs/PRD.md` §9.

Because the Accounting and Payments modules do not exist, **this module creates
no accounting artefact of any kind** — no supplier bill, no payable, no
payment, no journal entry, and no tax recognition. A purchase order is an
operational commitment document only. This keeps Principle III intact the same
way ADR 0004 did: no divergent accounting path is created here, because no
accounting path is created here at all.

That exclusion is also what makes the ordering legal. Purchasing's only hard
prerequisite is `005-products-variants-warehouses-inventory`, which is built.
It has no dependency on `006-chart-of-accounts-and-journals`,
`007-sales-flow-quotation-delivery-invoice`, or
`008-payments-stripe-manual-tax-recognition` **because** the payable side is
excluded. Adding any accounts-payable or general-ledger behaviour to this
module before those features exist would skip a prerequisite and violate the
constitution's Specification Governance section, however small the addition
appears.

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

Supplier confirmations are **append-only**. Once answered, a confirmation is
immutable; a correction is recorded as a new confirmation so the original
answer survives as evidence. The confirmation target is polymorphic, serving
both the ERD's customer back-order flow and purchase-order acknowledgement
through one entity.

All purchase-order, approval, confirmation, receiving, and cost-writeback
mutations are routed through domain services under `app/Services/Purchasing/`,
using Spatie Activitylog (per ADR 0005), Spatie Permission, and the existing
Inventory services — no parallel audit store, permission store, or stock
writer.

This feature adds two fixed dashboard roles, **Purchasing Manager** and
**Purchasing Officer**, to the existing `DashboardRole` catalogue, alongside
the existing System Admin and Reviewer roles which it reuses.

This approval is limited to English-only UI strings for this phase, following
the spec 013, 015, and 016 precedent.

The constitution's Specification Governance extraction order contains **no**
entry for purchasing. This work is therefore an owner-prioritised addition to
that order rather than a reordering of it, delivered as
`017-purchasing-orders-suppliers`.

### ERD extensions authorised by this decision

The documented ERD carries no purchase-order document and models supplier
confirmation against a customer order only. Six extensions are authorised and
must be reflected back into `Docs/database/ERD.md` when this feature is
planned:

1. A new `purchase_orders` table: number, supplier, destination warehouse,
   status, currency, order and expected dates, stored total, and the
   submission, approval, transmission, closure, and cancellation audit fields.
2. A new `purchase_order_lines` table: product variant, unit, ordered and
   cumulative received quantity, ordered and last-received unit cost, stored
   line total, and the supplier product reference that supplied the price.
3. `supplier_confirmations.order_id` becomes a `confirmable_type` /
   `confirmable_id` morph, restricted to purchase orders and customer orders.
4. `supplier_confirmations` drops the ERD's generic `status` column, which is
   redundant against the `confirmation_status` column on the same table.
5. `supplier_confirmations` gains `promised_at`, so a supplier's committed date
   is filterable and reportable rather than buried in free-text notes.
6. A new `purchase_settings` singleton table holding the approval threshold
   amount and currency, following the existing `inventory_settings` precedent.

Separately, the `orders` table gains a single nullable `pending_reason` column
to support the customer back-order confirmation flow. The ERD's `supplier_id`,
`payment_status`, and `grand_total` columns on `orders` are **not** added by
this feature; they belong to the future sales and accounting work, and adding
unused financial columns now would let them drift before their semantics are
defined.

Every other structure follows the ERD as written.

### Amendment accepted by ADR 0011 (2026-08-26)

ADR 0006 is amended only to permit the Accounting module to hold a read-only
reference to a purchase order and purchase-order line when recording a
supplier bill. Accounting may derive ordered, received, and cumulatively billed
quantities from that reference for an advisory three-way match.

This does not add a payable, bill, supplier payment, journal entry, tax
recognition, or billed-amount surface to Purchasing. Purchase orders continue
to create no accounting artefact, and the dependency remains one-way:
Accounting may read Purchasing; Purchasing must not reference Accounting.

## Consequences

- The constitution's Product Scope & Boundaries section gains a fifth narrow
  Filament dashboard exception, alongside ADR 0001 (Inventory), ADR 0002 (CRM),
  ADR 0003 (Employees), and ADR 0004 (Support and Maintenance).
- The ERD gains its first document entity that was not present in the original
  design. This is a deliberate, registered divergence, not a drift: all six
  extensions are enumerated above and must be written back before
  implementation.
- The `InventoryOperation.source_document` morph is used inbound for the first
  time, completing a seam that has been scaffolded but unfilled since the
  Inventory operations feature shipped; its outbound half is already live via
  `OrderFulfillmentService`. The `inventory_operations.supplier_id` and
  `supplier_reference` columns gain their first writer outside the legacy
  backfill.
- Existing Spatie Activitylog, Spatie Permission, Inventory operation services,
  and `TracksBlameable` infrastructure remain canonical and are extended, not
  duplicated, for the Purchasing module.
- No API surface and no supplier-facing portal is introduced. The portal stays
  out of scope under Product Scope & Boundaries; this exception does not weaken
  that entry.
- No accounting behaviour is introduced. A received purchase order produces no
  supplier bill, no payable, no payment, no journal entry, and no tax
  recognition. When the Accounting and Payments modules land, they own the
  payable consequences from that point forward, at which time this ADR must be
  revisited.
- Stock correctness stays governed by Principle III: this module never writes
  stock directly, every receipt it records produces inventory movements through
  the Inventory services, and a static architecture test proves the absence of
  a second path.
- Two fixed dashboard roles are added to `DashboardRole`, which by design
  narrows every other module's `isAdmin()`-bypass check automatically. This is a
  behavioural change to four already-shipped modules: Inventory, CRM,
  Employees, and Support. Their existing authorization and cross-module
  boundary suites must be run as part of this feature, not assumed unaffected.
- Purchasing is scheduled ahead of the accounting and sales features it would
  normally follow. That is legal only because the payable side is excluded; the
  exclusion is load-bearing and must not be relaxed piecemeal.
- `Docs/database/ERD.md` must be updated with the six extensions listed above,
  and `Docs/PRD.md` §11 must list this exception, before implementation begins,
  per Constitution Principle I (database design finalized before
  implementation).
- Any future Filament dashboard exception for another module still requires its
  own ADR.
