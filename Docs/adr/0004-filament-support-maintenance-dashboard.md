# ADR 0004: Adopt the Existing Filament Dashboard for the Support and Maintenance Module

**Status**: Accepted

**Date**: 2026-08-10

**Deciders**: Project Owner

**Related**: `specs/016-support-maintenance-dashboard/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`, and the IERP Constitution Product Scope & Boundaries section

## Context

The constitution's Product Scope & Boundaries section permits a Filament
dashboard dependency only for the Inventory module (ADR 0001), the CRM module
(ADR 0002), and the Employees module (ADR 0003). The Support and Maintenance
module — support tickets, their conversation and assignment history,
chargeable tickets, maintenance requests for sold equipment, and the service
work performed against them — has no scaffolding built yet, but the `/admin`
panel already reserves the navigation group, the three resource links, and
their English labels (`app/Filament/AdminModuleRegistry.php` group `support`,
`lang/en/admin.php`). Opening the module today renders the shared placeholder
page.

The documented design in `Docs/api/API_CONTRACT.md`,
`Docs/database/DFD.md`, and `Docs/diagrams/SEQUENCE_DIAGRAMS.md` §15 describes
this module primarily as a customer-app flow: the customer submits a ticket
through `/api/customer/tickets`, a Stripe payment link is created for a
chargeable ticket, and the webhook moves the ticket to `live`. That design
depends on three things this repository does not have. There is no
`routes/api.php` and no API surface of any kind. There is no Invoice, Payment,
or Stripe integration — the Sales and Payments modules are unbuilt. And the
constitution's Product Scope & Boundaries section already excludes new
customer-facing API surfaces unless separately approved.

The two surfaces have very different risk profiles. The dashboard is an
internal administrative tool behind Spatie Permission. The customer API would
be a new public-facing surface, an authentication flow, and a payment
integration. Building the customer app was never part of this feature's
request.

One part of this module writes outside its own domain: recording the spare
parts a service visit consumed decrements stock. Constitution Principle III
(NON-NEGOTIABLE) requires every stock-changing action to create an inventory
movement against `product_variant_id + warehouse_id`. This feature must
therefore route parts consumption through the existing Inventory services
rather than writing stock rows of its own, and must not widen any support
role's Inventory access as a side effect.

## Decision

Use the existing `/admin` Filament dashboard for the Support and Maintenance
module, limited to dashboard operations. The three resources already pinned in
`AdminModuleRegistry` — Tickets, Maintenance Requests, and Service Records —
are backed by real domain models and services in this feature, mapped literally
onto the ERD's three Support entities: Tickets → `tickets`, Maintenance
Requests → `maintenance_records`, Service Records → `maintenance_tasks`.

**Authorised** (as `/admin` Filament dashboard surfaces only): ticket intake,
classification, and numbering; the documented ticket status lifecycle;
assignment history; the ticket conversation thread, internal notes, and
attachments; chargeable tickets held at `pending_payment` and released by an
admin-recorded settlement; ticket priority and SLA response/resolution
tracking; maintenance requests raised from a ticket or standalone, with an
equipment link to an existing serialized inventory unit and an explicit
warranty status; service records with technician, due date, and status;
spare-parts consumption posted through the existing Inventory services;
support reports; and dashboard roles and permissions for the module.

**Not authorised by this ADR**: `/api/customer/tickets`,
`/api/dashboard/tickets`, or any other API surface; the customer mobile
application; a technician mobile application; customer self-service ticket
creation; Stripe integration or provider-generated payment links; journal
entries, tax recognition, revenue recognition, or any other accounting posting
arising from a ticket payment; outbound customer email, SMS, or push
notification delivery; a knowledge base, canned responses, or chat/telephony
integration; and automatic ticket routing or AI triage. Implementing any of
these later requires its own specification and either a separate ADR or an
explicit amendment to this one.

Because ticket intake belongs to the out-of-scope customer app, this feature
treats tickets and maintenance requests as records **created and administered
from the dashboard on the customer's behalf**. It does not build customer
self-service.

Because the Payments module does not exist, a chargeable ticket's settlement is
recorded manually by an authorized dashboard user. That record is an
operational unblock only: it releases the ticket from `pending_payment` to
`live` and produces **no** journal entry, tax-recognition entry, or revenue
posting. The `ticket_payment_links` record reserves the external payment
reference and payment URL fields unused, so the future Payments module can
adopt the record without re-designing this module's schema. This keeps
Principle III intact — no divergent accounting path is created here, because no
accounting path is created here at all.

Spare-parts consumption posts every stock change as an inventory movement
through the existing Inventory services, inside the same transaction as the
consumption record. No support role gains Inventory dashboard access; the
module's own `support.*` permission authorises the action and the Inventory
service performs the write.

All ticket, payment, maintenance, service-record, and consumption mutations are
routed through domain services under `app/Services/Support/`, using the
existing `AuditLogger`, Spatie Permission, and Spatie Media Library
infrastructure — no parallel audit store, permission store, or media store.
Ticket attachments use Media Library collections, not the custom
`ticket_attachments` file table implied by a literal reading of the ERD; the
ERD entity survives as the attachment metadata association, consistent with how
ADR 0003 handled visit attachments and voice-note audio.

This feature adds two fixed dashboard roles, **Support Manager** and **Support
Agent**, to the existing `DashboardRole` catalogue, alongside the existing
System Admin and Reviewer roles which it reuses.

This approval is limited to English-only UI strings for this phase, following
the spec 013 and spec 015 precedent.

The constitution's Specification Governance extraction order lists this work as
`012-tickets-maintenance`; this ADR authorises only that entry's dashboard
portion. The actual feature directory is `016-support-maintenance-dashboard`,
reflecting that scope narrowing.

### ERD extensions authorised by this decision

The documented ERD does not carry ticket priority, SLA tracking, warranty data,
or parts consumption. Four extensions are authorised and must be reflected back
into `Docs/database/ERD.md` when this feature is planned:

1. `tickets` gains priority, SLA target snapshot, response/resolution due
   timestamps, first-response and resolution timestamps, accumulated
   customer-wait duration, breach flags, and a self-reference to a continued
   ticket.
2. A new SLA policy table holds the first-response and resolution targets per
   priority.
3. `maintenance_records` gains a serialized-inventory-unit reference, warranty
   status, and warranty expiry date.
4. A new service-record parts table records each consumed variant, warehouse,
   quantity, and the inventory movement it produced.

Every other structure follows the ERD as written.

## Consequences

- The constitution's Product Scope & Boundaries section gains a fourth narrow
  Filament dashboard exception, alongside ADR 0001 (Inventory), ADR 0002 (CRM),
  and ADR 0003 (Employees).
- Existing `AuditLogger`, Spatie Permission, Spatie Media Library, Inventory
  movement services, and `TracksBlameable` infrastructure remain canonical and
  are extended, not duplicated, for the Support and Maintenance module.
- No customer-facing or technician-facing API or mobile surface is introduced.
  `/api/customer/tickets`, `/api/dashboard/tickets`, the customer app, and
  customer self-service ticket creation all stay out of scope pending their own
  specification and ADR.
- No accounting behavior is introduced. A recorded ticket settlement carries no
  journal entry, no tax recognition, and no revenue recognition; when the
  Payments module lands, it adopts the reserved payment-link fields and owns the
  accounting consequences from that point forward, at which time this ADR must
  be revisited.
- No notification delivery is introduced. SLA breaches, assignments, and
  closures are visible in the dashboard only until the Reporting and
  Notifications feature is built.
- Stock correctness stays governed by Principle III: this module never writes
  stock directly, and every consumption it records produces an inventory
  movement through the Inventory services.
- Two fixed dashboard roles are added to `DashboardRole`, which by design
  narrows every other module's `isAdmin()`-bypass check automatically; each
  module's existing cross-module boundary tests must confirm the new roles grant
  no access outside Support.
- `Docs/database/ERD.md` must be updated with the four extensions listed above
  before implementation begins, per Constitution Principle I (database design
  finalized before implementation).
- Any future Filament dashboard exception for another module still requires its
  own ADR.

> **Update (ADR 0005):** the "existing `AuditLogger`... audit store" references
> above describe the audit trail as it stood when this ADR was written. As of
> ADR 0005, that trail is backed by `spatie/laravel-activitylog` instead of the
> bespoke `AuditLogger` service/`audit_logs` table — the "reuse the existing
> audit infrastructure, don't duplicate it" decision itself is unchanged, only
> what that infrastructure is built on.
