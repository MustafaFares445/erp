<!--
Sync Impact Report
==================
Current entry: version 1.9.0 to 1.10.0 (MINOR: Product Scope & Boundaries
materially expanded to approve Accounting payables administration through ADR
0011, including a narrow read-only reference from Accounting to Purchasing,
while preserving the Purchasing accounting boundary. The ADR names four new
posting callers: bill approval, expense approval, expense payment, and supplier
payment; records five payables tables; and adds the computed AP surface to the
ERD.

The prior 1.9.0 entry approved a read-only Accounting Financial Reports
Filament dashboard exception through ADR 0009).
`Docs/adr/0009-accounting-financial-reports.md` records project-owner approval,
scoped to **read-only** reporting over the posted general ledger: a Trial
Balance with opening balance, period debits and credits, and closing balance
per account; a General Ledger of posted lines with a running balance; a Profit
and Loss over a date range subtotalled by account type; a Balance Sheet as of a
date; a Posting Register of posted entries with their lines, fiscal period,
posting user, and source morph; date-range and as-of-date scoping with
fiscal-period selection as a convenience over open and closed periods alike;
displayed integrity proofs that MUST NOT be rounded, suppressed, adjusted, or
plugged when they fail; a computed accumulated-earnings equity line, labelled as
computed rather than posted, which is what allows the Balance Sheet to balance
while ADR 0007's exclusion of a year-end retained-earnings close stands; one new
`accounting.report.view` permission implied by no other permission; streamed CSV
exports gated on the same permission as the screen and enforced on the export
request itself; and the resolution of a latent duplicate navigation
registration.
This exception **adds no write path of any kind**. It authorises no posting
caller. Those already granted — ADR 0008's three commercial-document events
(invoice issuance, payment collection, credit-note confirmation) and the
accounting foundation's manual journal-entry path — remain the complete list,
and this feature adds none to it.
It approves no schema change — no table, column, index, or migration — which
makes it the first module-scale exception with no ERD footprint at all and the
cheapest to revert.
It does not approve any API surface; accounts-receivable or accounts-payable
subledgers or aged reports; supplier bills, expenses, refunds, or a
tax-definitions catalogue; a year-end retained-earnings close; a cash-flow
statement, budget-versus-actual, comparative or multi-period columns,
consolidation, or segment reporting; multi-currency or revaluation; cost
accounting, inventory valuation, or cost-of-goods-sold derivation; bank
reconciliation; PDF rendering; scheduled or emailed report delivery; saved
report definitions; or report-result caching. Those require their own
specification and either a separate ADR or an explicit amendment to ADR 0009.
**ADR 0007's no-automatic-posting rule and ADR 0006's Purchasing prohibition are
both untouched.** A ledger that can now be *reported on* is still not permission
to post to it, and reporting on it grants Purchasing nothing.
Modified sections: Product Scope & Boundaries (eighth narrow Filament dashboard
  exception added); Specification Governance (extraction-order mapping for 020
  added, recording it as an owner-prioritised addition with no corresponding
  entry, and restating the two prohibitions it leaves intact).
Added sections: none. Removed sections: none.
Templates requiring updates:
  - OK .specify/templates/plan-template.md (generic Constitution Check gate,
    no static references to update)
  - OK .specify/templates/spec-template.md (no constitution-specific
    references)
  - OK .specify/templates/tasks-template.md (no constitution-specific
    references)
Follow-up TODOs:
  - DONE - `Docs/adr/0009-accounting-financial-reports.md` was moved to
    **Accepted** by the project owner on 2026-08-23; this version references it
    as the record of project-owner approval.
  - NONE PENDING - no ERD update is required, because ADR 0009 authorises no
    schema change. This is the first exception for which
    `Docs/database/ERD.md` needs no amendment.
  - CARRIED - `Docs/PRD.md` §11 lists financial reports of every kind as out of
    scope under ADR 0007. That line must be qualified to record the ADR 0009
    exception, in the same way §11 already qualifies the ADR 0006 and ADR 0007
    entries.

Previous entry (1.8.0):
  Version change: 1.7.0 to 1.8.0 (MINOR: Product Scope & Boundaries
  materially expanded to approve a Sales, Payments, and Credit Notes Filament
  dashboard exception through ADR 0008, and the no-automatic-posting rule
  established at 1.7.0 narrowed rather than removed).
`Docs/adr/0008-filament-sales-payments-dashboard.md` records project-owner
approval, scoped to dashboard-only administration of the commercial documents
between a customer's quotation and the money collected against it: payment
terms with due-date and overdue derivation; a sales settings singleton carrying
the default tax rate and the four accounts the flow posts to; quotations with
tier-resolved priced lines, a price-floor guard, an admin-recorded accept or
reject, and no inventory effect in any state; the built `orders` table extended
in place with the accepted quotation's pricing; a Delivery Notes surface derived
from existing `InventoryOperation` deliveries rather than a new table, with all
stock movement still posted exclusively through the existing Inventory operation
services; invoices created from a completed delivery or entered directly, with
payment-term due dates, immutability once issued, no deletion of an issued
invoice by any path, queued PDF generation into Media Library, queued email, and
append-only receipt confirmation with signature; manual payments with proof,
multi-invoice allocation, an unallocated remainder posted to customer deposits,
and proportional tax recognition on collection with no rounding drift; credit
notes with reversal posting and no physical deletion; `sales.*` permissions and
three fixed dashboard roles; and audit logging through ADR 0005.

**It narrows, and does not repeal, the no-automatic-posting rule.** ADR 0007
approved a posting interface with no callers and said connecting any document to
it belongs to that document's own feature and ADR. ADR 0008 is that ADR for
exactly three events — invoice issuance, payment collection with its
proportional tax recognition, and credit-note confirmation with its reversal —
and for no others. Quotations, orders, delivery operations, purchase orders,
inventory movements, ticket payments, and spare-part consumption still post
nothing. **ADR 0006's prohibition on any accounts-payable or general-ledger
behaviour in the Purchasing module survives entirely intact**; a ledger with
three callers is not permission for a fourth. ADR 0004's exclusion of any
journal entry, tax-recognition entry, or revenue posting arising from a ticket
payment is likewise unrelaxed.

It explicitly does not authorise any API surface or unauthenticated route; the
customer application, a customer-facing accept/reject link, or any customer
self-service surface; Stripe, its client, its webhook, or any online payment
channel; accounts-receivable or accounts-payable subledger pages; supplier
bills, expenses, refunds, or a `tax_definitions` table; financial reports of any
kind, including aged receivables, sales, and tax reports; document templates or
the Settings page; cost-of-goods-sold posting or inventory valuation;
multi-currency or revaluation; recurring billing, subscriptions, or renewals;
dunning or reminder schedules beyond deriving an `overdue` status; customer
credit limits, which remain out of scope and are **not** relaxed; sales
commission calculation; goods-return movements from a credit note, or debit
notes; or wiring `TicketPaymentLink` to the Payments module.

Specification Governance is amended to record that this work delivers three
extraction-order entries — `007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition` (manual channel only), and
`009-credit-notes` — as one owner-authorised slice, and to record that this
bundling **supersedes** ADR 0007's contrary reviewability judgement by explicit
project-owner decision rather than by oversight.
Modified sections: Product Scope & Boundaries (seventh narrow Filament
  dashboard exception added; the ADR 0007 no-automatic-posting paragraph
  qualified by ADR 0008's three authorised callers); Specification Governance
  (extraction-order mapping for 019 added, covering entries 007-009, with the
  Purchasing prohibition restated as unaffected).
Added sections: none. Removed sections: none.
Templates requiring updates:
  - OK .specify/templates/plan-template.md (generic Constitution Check gate,
    no static references to update)
  - OK .specify/templates/spec-template.md (no constitution-specific
    references)
  - OK .specify/templates/tasks-template.md (no constitution-specific
    references)
  - OK .claude/skills/speckit-*/SKILL.md (no stale agent-specific naming found)
Follow-up TODOs:
  - DONE - `Docs/adr/0008-filament-sales-payments-dashboard.md` was moved to
    **Accepted** by the project owner on 2026-08-23; implementation of
    `specs/019-sales-lifecycle-payments-credits` may proceed.
  - DONE - `Docs/database/ERD.md` now carries the eleven ERD deviations ADR
    0008 authorises (E-1 through E-11 in
    `specs/019-sales-lifecycle-payments-credits/spec.md` §ERD Divergence
    Register — one more than originally scoped: E-11 additionally drops
    `payments.invoice_id` in favour of `payment_allocations` as the sole
    link, found during data-model review). Every affected table's own
    `#### Notes` section states its divergence and cites this ADR; the Full
    Entity List, §5 Relationships, and §10 Status and Enum Catalog are
    updated to match.
  - DONE - `Docs/PRD.md` §11 lists the ADR 0008 exception alongside ADR 0006
    and ADR 0007.
  - RESOLVED - the three owner decisions carried forward at 1.7.0 (extend
    `orders`; no `delivery_notes` table; `barryvdh/laravel-dompdf` as the PDF
    dependency) are encoded by spec 019 as D2, D3, and D4. `laravel-dompdf` is
    installed by that feature, as 1.7.0 anticipated.

Previous entry (1.7.0):
  Version change: 1.6.0 to 1.7.0 (MINOR: Product Scope & Boundaries
  materially expanded to approve an Accounting Filament dashboard exception
  through ADR 0007). `Docs/adr/0007-filament-accounting-dashboard.md` records
  project-owner approval, scoped to dashboard-only administration of the general
  ledger's structural records and manual postings: seeded account types, the
  chart of accounts hierarchy with its postable/active flags, fiscal periods that
  can be closed, manual journal entries with a `draft` -> `posted` lifecycle,
  balance validation on posting, posted-entry immutability with a reversing entry
  as the only correction path, a `JournalPostingService` interface exposing
  `post()`/`reverse()` against the ERD's `source_type`/`source_id` morph,
  per-account balances and a single-account ledger read surface, dashboard
  roles/permissions, and audit logging through ADR 0005. It explicitly does not
  authorise any API surface, accounts-receivable or accounts-payable subledgers,
  supplier bills, expenses, refunds, tax definitions, financial reports of any
  kind (including a trial balance), **automatic posting from any commercial
  document**, multi-currency or revaluation, cost accounting or inventory
  valuation, budgets, bank reconciliation, a year-end retained-earnings close,
  opening-balance import, recurring entries, or approval workflow beyond
  `draft -> posted` — those remain out of scope pending their own specification
  and either a separate ADR or an explicit amendment to ADR 0007. Specification
  Governance is amended to record that this work corresponds to the
  `006-chart-of-accounts-and-journals` extraction-order entry, and that it is the
  first module delivered in documented order rather than as an owner-prioritised
  addition.
  Modified sections: Product Scope & Boundaries (sixth narrow Filament dashboard
    exception added); Specification Governance (extraction-order mapping for 018
    added, plus the sales-flow decisions carried forward).
  Added sections: none. Removed sections: none.
  Templates requiring updates:
    - OK .specify/templates/plan-template.md (generic Constitution Check gate,
      no static references to update)
    - OK .specify/templates/spec-template.md (no constitution-specific
      references)
    - OK .specify/templates/tasks-template.md (no constitution-specific
      references)
    - OK .claude/skills/speckit-*/SKILL.md (no stale agent-specific naming found)
  Follow-up TODOs:
    - DONE - `Docs/adr/0007-filament-accounting-dashboard.md` was moved to
      **Accepted** by the project owner on 2026-08-20; this version references it
      as the record of project-owner approval.
    - DONE - ADR 0006 (Purchasing) was moved to **Accepted** by the project owner
      on the same date, unblocking `specs/017-purchasing-orders-suppliers`, which
      is specified but still unimplemented. That work is unaffected by this
      amendment. 018 does not depend on it.
    - DONE - `Docs/PRD.md` §11 now lists the ADR 0007 exception.
    - DONE - `Docs/database/ERD.md` carries the two ERD deviations ADR 0007
      authorises: `fiscal_periods` drops the generic `status` column in favour of
      its purposeful `is_closed` flag, and `journal_entry_lines` gains an
      additive `sort_order`. No table was added or removed; all five accounting
      tables were already present in the Full Entity List.
    - CARRIED FORWARD - three owner decisions taken 2026-08-18 bind
      `007-sales-flow-quotation-delivery-invoice`, not 018, and are recorded in
      ADR 0007 §Decisions carried forward: the built `orders` table is extended
      rather than replaced by a `sales_orders` table; no `delivery_notes` table
      is created because `InventoryOperation` is already the single system of
      record for a delivery; and `barryvdh/laravel-dompdf` is the approved PDF
      dependency for invoice documents, to be installed by that feature and not
      by this one.

Previous entry (1.6.0):
  Version change: 1.5.0 -> 1.6.0 (MINOR: Product Scope & Boundaries materially
  expanded to approve a Purchasing Filament dashboard exception through ADR
  0006). `Docs/adr/0006-filament-purchasing-dashboard.md` records project-owner
  approval, scoped to dashboard-only administration of purchase orders and
  their priced lines, a value-threshold approval gate, transmission to the
  supplier, supplier confirmations recorded manually against either a purchase
  order or a customer order, receiving posted exclusively through the existing
  Inventory operation services, supplier product references and their cost
  writeback, purchasing reports, and dashboard roles/permissions. It does not
  authorise any API surface, a supplier-facing portal, purchase requisitions or
  RFQs, supplier bills, accounts payable, payments to suppliers, journal
  entries, purchase-tax recognition, landed-cost allocation, supplier returns
  or debit notes, currency conversion or revaluation, moving-average or FIFO
  cost recalculation, supplier performance scoring, automatic reorder-point
  purchasing, or outbound email/EDI transmission of a purchase order.
  Specification Governance was amended to record that this work has no
  corresponding entry in the documented extraction order and is an
  owner-prioritised addition, and to record why it does not skip a
  prerequisite. Its ERD extensions (`purchase_orders`,
  `purchase_order_lines`, the polymorphic `supplier_confirmations` target, and
  the `purchase_settings` singleton) are present in `Docs/database/ERD.md`.
  Follow-up: ADR 0006 was moved to **Accepted** on 2026-08-20.

Previous entry (1.5.0):
  Version change: 1.4.0 → 1.5.0 (MINOR: Product Scope & Boundaries materially
  expanded to approve a Support and Maintenance Filament dashboard exception
  through ADR 0004). `Docs/adr/0004-filament-support-maintenance-dashboard.md`
  records project-owner approval, scoped to dashboard-only administration of
  support tickets, their conversation and assignment history, chargeable
  tickets released by an admin-recorded settlement, ticket priority and SLA
  tracking, maintenance requests with equipment and warranty data, service
  records, spare-parts consumption posted through the existing Inventory
  services, support reports, and dashboard roles/permissions. It does not
  authorise `/api/customer/tickets`, `/api/dashboard/tickets`, any other API
  surface, the customer or technician mobile applications, customer
  self-service ticket creation, Stripe integration, any accounting or
  tax-recognition posting arising from a ticket payment, outbound notification
  delivery, a knowledge base, or automatic/AI ticket triage. Specification
  Governance was amended to note that this work corresponds to the
  `012-tickets-maintenance` extraction-order entry, with only its dashboard
  portion authorised. Follow-up TODO: `Docs/database/ERD.md` must be updated
  with the four ERD extensions ADR 0004 authorises before implementation of
  016 begins, per Principle I.

Previous entry (1.4.0):
  Version change: 1.3.0 → 1.4.0 (MINOR: Product Scope & Boundaries materially
  expanded to approve an Employees Filament dashboard exception through ADR
  0003). `Docs/adr/0003-filament-employees-dashboard.md` records project-owner
  approval for dashboard-only administration of employee profiles, monthly
  plans, tasks, visits, voice-note and AI review, performance and salary
  calculations, bonus review, employee reports, and dashboard
  roles/permissions. It does not authorise `/api/employee` endpoints, the
  employee mobile application, employee-app visit or attendance capture, or
  mobile authentication flows. Specification Governance was amended to note
  that this work corresponds to the `011-employee-app-plans-visits-ai`
  extraction-order entry, with only its dashboard portion authorised.

Previous entry (1.3.0):
  Version change: 1.2.0 → 1.3.0 (MINOR: Product Scope & Boundaries materially
  expanded to approve a CRM Filament dashboard exception through ADR 0002).
  `Docs/adr/0002-filament-crm-dashboard.md` records project-owner approval for
  dashboard-only CRM customer and pricing-tier administration. The 2026-08-02
  clarification keeps the version unchanged while consolidating
  product-scoped discounts into Pricing Tiers and excluding CRM payment terms.

Previous entry (1.2.0):
Version change: 1.1.0 → 1.2.0 (MINOR: Product Scope & Boundaries materially
  amended — Filament dashboard out-of-scope entry qualified with an
  Inventory-module exception)
Modified principles: none
Modified sections:
  - Product Scope & Boundaries: the "Filament dashboard dependency" out-of-scope
    entry now records an approved exception for the Inventory module only,
    referencing ADR 0001. All other modules' Filament dashboards remain out of
    scope pending a separate ADR.
Added sections: none
Removed sections: none
Rationale / approval: ADR 0001
  (Docs/adr/0001-filament-inventory-dashboard-for-inventory.md) records
  project-owner approval per the amendment procedure. Resolves
  FILAMENT_INVENTORY_DASHBOARD_PLAN.md §0 and Open Question #1, and the
  governance assumption in specs/001-inventory-dashboard-foundation/spec.md.
Templates requiring updates:
  - ✅ .specify/templates/plan-template.md (generic Constitution Check gate, no
    static references to update)
  - ✅ .specify/templates/spec-template.md (no constitution-specific references)
  - ✅ .specify/templates/tasks-template.md (no constitution-specific references)
  - ✅ .claude/skills/speckit-*/SKILL.md (no stale agent-specific naming found)
Follow-up TODOs: none

Previous entry (1.1.0):
  Version change: [TEMPLATE] → 1.1.0 (initial ratification of concrete constitution)
  Added Core Principles I–VI, Product Scope & Boundaries, and Specification
  Governance; replaced all template placeholders.
-->

# IERP Constitution

## Core Principles

### I. Specification-First Development

The specs, not the code, are the source of truth for implementation decisions.
Every implementation task MUST be derived from the approved documentation set
listed under Specification Governance. Coding agents (Claude Code, openCode,
or any other agent working in this repo) MUST read this constitution and the
related feature specification before writing any code. If code conflicts with
an approved spec, the spec wins unless the project owner explicitly approves
a spec update first. Database design MUST be finalized before implementation
begins for any feature that touches persisted data.

**Rationale**: IERP is a custom ERP built directly from company requirements,
not an off-the-shelf package. Without a single, authoritative source of truth,
parallel dashboard/customer-app/employee-app clients and multiple coding
agents would drift into inconsistent, conflicting behavior.

### II. Domain-Driven Modular Monolith

IERP is implemented as a single Laravel API backend ("clean monolith"),
organized into modular domain folders (e.g., accounting, inventory, sales,
CRM, tickets) rather than a microservices or event-sourced/CQRS architecture.
Controllers MUST stay thin; business rules MUST live in domain
services/actions. Form Requests MUST be used for input validation. API
Resources MUST be used to shape API output. Unrelated refactors are
prohibited when delivering a feature.

**Rationale**: A modular monolith keeps operational complexity low for a team
building a broad ERP surface (accounting, inventory, sales, CRM, tickets, AI)
while still enforcing separation of concerns through folder-level domain
boundaries. Microservices, event sourcing, and CQRS are explicitly out of
scope because they add operational overhead the project does not yet need.

### III. Financial & Inventory Integrity (NON-NEGOTIABLE)

Accounting correctness and inventory correctness are mandatory and MUST NOT be
compromised for delivery speed:

- Every stock-changing action MUST create a corresponding inventory movement.
- The stock source of truth is the pair `product_variant_id + warehouse_id`.
- The sales lifecycle MUST follow `Quotation → Delivery Note → Invoice →
  Payment`. Quotations MUST NOT affect inventory. Delivery notes affect
  inventory but MUST NOT recognize tax. Invoices represent the financial
  claim. Payments record actual collection.
- Tax MUST be recognized only when payment is collected; partial payments
  recognize tax proportionally.
- Manual payments and Stripe payments MUST share the same accounting and
  tax-recognition logic — no divergent code paths per payment channel.
- Confirmed financial documents (invoices, journal entries, etc.) MUST NOT be
  physically deleted; corrections MUST go through reversal or credit-note
  flows.
- Accounting, payment, and inventory-affecting operations MUST run inside
  database transactions.

**Rationale**: IERP centralizes the company's financial and operational
records. Silent stock drift or incorrect tax timing directly corrupts
financial reporting and regulatory compliance, and is far more costly to
unwind after the fact than to prevent at implementation time.

### IV. Unified Access, Media & Payment Standards

Authorization MUST use Spatie Laravel Permission: `users.user_type`
identifies the user channel/actor (System Admin, Customer, Employee), while
roles and permissions handle detailed authorization. Custom
authorization/role systems are prohibited. All uploaded files, generated
PDFs, payment proofs, ticket/visit attachments, product images, and
voice-note audio MUST use Spatie Laravel Media Library; custom per-feature
file tables MUST NOT be created unless a concrete future requirement proves
they are needed. Online payments go through Stripe; admin-defined manual
payment methods (cash, bank transfer, cheque, custom) MUST also be
supported, sharing tax logic with Stripe per Principle III. Long-running
operations (PDF generation, emails, payment webhooks, AI transcription,
notifications, exports) MUST run through queued jobs, not synchronously in
the request cycle.

**Rationale**: Standardizing on Spatie packages for access control and media,
and on queues for slow operations, avoids reinventing well-solved problems
and keeps behavior consistent across the dashboard, customer app, and
employee app.

### V. AI Isolation & Human Oversight (NON-NEGOTIABLE)

AI processing (voice-note transcription, keyword detection, sales
opportunity drafting) MUST be isolated from critical employee visit
workflows: an AI failure MUST NOT block visit completion or any other
core operational flow. AI-generated output (sales opportunity drafts, bonus
suggestions) MUST be reviewable by an admin before it has operational or
financial effect. Fully autonomous AI decisions without admin review are out
of scope.

**Rationale**: AI transcription and detection are convenience features layered
on top of the employee visit workflow. Coupling core operations to AI
availability, or letting AI act unsupervised on sales/financial data,
introduces reliability and correctness risk the business is not willing to
accept at this stage.

### VI. Engineering Discipline for Coding Agents

Every coding agent working in this repository MUST, for each implementation
task:

1. Read this constitution and the related feature specification before coding.
2. Keep controllers thin; put business rules in domain services/actions.
3. Use Form Requests for validation and API Resources for output shape.
4. Wrap accounting, payment, and inventory operations in transactions.
5. Use queues for long-running operations.
6. Add tests for every implemented business rule; sensitive actions MUST be
   covered by audit logging.
7. Avoid unrelated refactors.
8. Report changed files, database changes, API changes, and tests after each
   implementation task.

**Rationale**: This project is built by multiple AI coding agents against a
shared specification set. Explicit, checkable engineering rules keep output
consistent and reviewable regardless of which agent produced it.

## Product Scope & Boundaries

IERP's current feature areas are: Identity & Access; Dashboard Operations;
Customer App Operations; Employee App Operations; Product Catalog &
Inventory; Files & Attachments; Accounting & Finance; Sales Flow; Payments &
Tax Recognition; Suppliers; Employee Performance & Salary; AI Integration;
Tickets, Maintenance, CRM & Marketing; and Reporting, Notifications & Audit
Logs. Full detail for each area lives in `Docs/PRD.md` and `Docs/SDD.md`.

The following are explicitly **out of scope** unless the project owner
approves an exception in writing: website implementation and active website
inventory sync; a supplier-facing portal; customer credit limits; a Filament
dashboard dependency (**exception approved for the Inventory module only** —
see ADR 0001, `Docs/adr/0001-filament-inventory-dashboard-for-inventory.md`; a
Filament dashboard for any other module remains out of scope pending a
separate ADR); dependency on an open-source ERP package; microservices
architecture; event sourcing or CQRS; and AI decisions made without admin
review.

ADR 0002 adds a second narrow exception: the existing `/admin` Filament panel
is approved only for CRM customer and pricing-tier administration. Pricing
Tiers is the sole pricing management surface; the exception does not approve a
standalone product-subscription domain, CRM payment-term workflow, general CRM
customer app, public API, recurring billing, or Filament use by any other
module.

ADR 0003 adds a third narrow exception: the existing `/admin` Filament panel
is approved for the Employees module — dashboard-only administration of
employee profiles, monthly plans, tasks, visits, voice-note review, AI
transcription review, performance calculations, salary calculations, bonus
review, employee reports, and dashboard roles/permissions. It does not
approve `/api/employee` endpoints, the employee mobile application,
employee-app visit capture, employee-app attendance capture, mobile
authentication flows, or any other employee-facing API functionality; those
require their own specification and either a separate ADR or an explicit
amendment to ADR 0003.

ADR 0004 adds a fourth narrow exception: the existing `/admin` Filament panel
is approved for the Support and Maintenance module — dashboard-only
administration of support tickets and their conversation, attachment, and
assignment history; chargeable tickets held at `pending_payment` and released
by an admin-recorded settlement; ticket priority and SLA response/resolution
tracking; maintenance requests with equipment and warranty data; service
records; spare-parts consumption posted through the existing Inventory
services; support reports; and dashboard roles/permissions. It does not
approve `/api/customer/tickets`, `/api/dashboard/tickets`, any other API
surface, the customer mobile application, a technician mobile application,
customer self-service ticket creation, Stripe integration, any journal entry,
tax-recognition entry, or revenue posting arising from a ticket payment,
outbound customer notification delivery, a knowledge base, or automatic/AI
ticket triage; those require their own specification and either a separate ADR
or an explicit amendment to ADR 0004.

ADR 0006 adds a fifth narrow exception: the existing `/admin` Filament panel is
approved for the Purchasing module — dashboard-only administration of purchase
orders and their priced lines; a value-threshold approval gate with separation
of duties; marking an approved order as transmitted to the supplier, after
which it is immutable; supplier confirmations recorded manually by an admin
against either a purchase order or a customer order; receiving posted
**exclusively** through the existing Inventory operation services, with the
purchase order as the operation's source document; supplier product references
and the writeback of received costs to them; purchasing reports; and dashboard
roles/permissions. It does not approve any API surface, purchase requisitions
or RFQs, supplier bills, accounts payable, payments to suppliers, journal
entries, purchase-tax recognition, landed-cost allocation, supplier returns or
debit notes, currency conversion or revaluation, moving-average or FIFO cost
recalculation of on-hand stock, supplier performance scoring, automatic
reorder-point purchasing, or outbound email/EDI transmission of a purchase
order; those require their own specification and either a separate ADR or an
explicit amendment to ADR 0006. The supplier-facing portal listed above remains
out of scope and is **not** relaxed by this exception.

ADR 0007 adds a sixth narrow exception: the existing `/admin` Filament panel is
approved for the **Accounting foundation** — dashboard-only administration of
the general ledger's structural records and manual postings. That is: seeded
account types for the five accounting elements; a chart of accounts hierarchy
with unique codes, an owning account type, an optional parent, and
postable/active flags; fiscal periods that can be closed, after which they
refuse both new postings and changes to existing ones; manual journal entries
with a `draft` -> `posted` lifecycle; balance validation on posting, so that an
entry whose debits and credits differ cannot be posted; posted-entry
immutability, with a reversing entry as the only correction path; a
`JournalPostingService` exposing `post()` and `reverse()` against the ERD's
`source_type`/`source_id` morph, as the interface later commercial documents
will call; per-account balances and a single-account ledger read surface; and
dashboard roles/permissions.

It does not approve any API surface, accounts-receivable or accounts-payable
subledgers, supplier bills, expenses, refunds, tax definitions, financial
reports of any kind — including a trial balance, profit and loss, or balance
sheet — multi-currency or revaluation, cost accounting or inventory valuation,
cost-of-goods-sold posting, budgets, bank accounts or reconciliation, a year-end
close rolling income and expense into retained earnings, opening-balance import,
recurring or scheduled entries, or any approval workflow beyond
`draft -> posted`; those require their own specification and either a separate
ADR or an explicit amendment to ADR 0007.

**Most importantly, it approves no automatic posting.** No invoice, payment,
credit note, tax-recognition entry, purchase order, ticket payment, or inventory
movement posts to the ledger as a result of ADR 0007. The posting interface
exists; connecting any document to it belongs to that document's own feature and
its own ADR. Building the ledger does not implicitly authorise anything to write
to it. ADR 0008 is that ADR for three of those documents, and only three — see
the exceptions below, which narrow this paragraph without repealing it.

ADR 0011 adds a further narrow exception: the existing `/admin` Filament panel
is approved for **Accounting payables administration** — expenses, supplier
bills and bill lines, supplier payments and allocations, and a computed
Accounts Payable surface with reconciliation and aging. Accounting may read a
purchase order and its inventory receipts for an advisory three-way match, but
Purchasing remains unable to create or reference any accounting artefact. The
four named posting callers are bill approval, expense approval, expense
payment, and supplier payment. No other document, including a purchase order,
may post through this exception. The exception does not approve an API,
supplier-facing portal, inventory valuation, capitalisation, multi-currency,
statutory tax filing, or any other scope excluded by ADR 0011.

ADR 0008 adds a seventh narrow exception: the existing `/admin` Filament panel
is approved for the **Sales lifecycle, Payments, and Credit Notes** —
dashboard-only administration of the commercial documents between a customer's
quotation and the money collected against it. That is: payment terms, from which
invoice due dates and overdue status derive; a sales settings singleton holding
the default tax rate and the four accounts the flow posts to; quotations with
tier-resolved priced lines, a price-floor guard, an accept or reject **recorded
by an admin or employee** in the dashboard, and no inventory effect in any state;
the built `orders` table extended in place with the accepted quotation's pricing,
rather than replaced; a Delivery Notes surface derived from existing
`InventoryOperation` deliveries rather than from a new table, with every stock
change posted **exclusively** through the existing Inventory operation services;
invoices created from a completed delivery or entered directly, immutable once
issued, never deleted once issued, with queued PDF generation into Media
Library, queued email, and append-only receipt confirmation carrying a
signature; manual payments with proof, allocation across invoices, an
unallocated remainder posted to customer deposits, and **proportional tax
recognition on collection** with the settling allocation absorbing the rounding
residue; credit notes as the sole correction path for an issued invoice, with
reversal posting and no physical deletion; and dashboard roles/permissions.

ADR 0008 narrows the no-automatic-posting rule above to **exactly three
authorised posting events** — invoice issuance, payment collection with its
proportional tax recognition, and credit-note confirmation with its reversal —
and to no others. Quotations, orders, delivery operations, purchase orders,
inventory movements, ticket payments, and spare-parts consumption continue to
post nothing. **ADR 0006's prohibition on any accounts-payable or general-ledger
behaviour in the Purchasing module survives intact**, and ADR 0004's exclusion of
any journal entry, tax-recognition entry, or revenue posting arising from a
ticket payment is unrelaxed. A ledger with three authorised callers is not
permission for a fourth.

ADR 0008 does not approve any API surface or unauthenticated route of any kind;
the customer application, a customer-facing accept/reject link, or customer
self-service; Stripe, its client, its webhook, or any online payment channel —
the manual channel is the only channel, and Principle III's no-divergent-paths
requirement is met by one payment-posting service and one tax-recognition
service with no branch on channel in either; accounts-receivable or
accounts-payable subledger pages, which stay placeholders even though this work
creates the receivable balances they would report on; supplier bills, expenses,
refunds, or a tax-definitions catalogue; financial reports of any kind, including
aged receivables, sales, and tax reports; document templates or the Settings
page; cost-of-goods-sold posting or inventory valuation, so a delivery still
posts nothing; multi-currency or revaluation; recurring billing, subscriptions,
or renewals; dunning or reminder schedules beyond deriving an `overdue` status;
sales commission calculation; goods-return movements arising from a credit note,
or debit notes; or wiring ticket payment links to the Payments module. Customer
credit limits, listed as out of scope above, are **not** relaxed by this
exception. Any of these requires its own specification and either a separate ADR
or an explicit amendment to ADR 0008.

ADR 0009 adds an eighth narrow exception: the existing `/admin` Filament panel is
approved for **read-only reporting over the posted general ledger**. That is: a
Trial Balance carrying each account's opening balance, period debits, period
credits, and closing balance; a General Ledger of posted lines with a running
balance that reconciles to the trial balance's closing figure; a Profit and Loss
over a date range subtotalled by account type; a Balance Sheet as of a date; a
Posting Register listing posted entries with their lines, resolved fiscal period,
posting user, and source morph rendered generically so that documents which do
not yet post will appear without further change; date-range and as-of-date
scoping, with fiscal-period selection offered as a convenience over open and
closed periods alike, because closing a period stops postings and not reads; and
one new `accounting.report.view` permission, implied by no other permission —
in particular not by `accounting.ledger.view`, which grants one account at a
time rather than the whole book in aggregate.

Two properties of this exception are load-bearing and are stated as requirements
rather than as description.

**It authorises displayed integrity proofs, and forbids repairing them.** The
Trial Balance MUST display the equality of its debit and credit totals; the
Balance Sheet MUST display the accounting equation. Where a proof fails, the
surface MUST show the discrepancy prominently as an error and MUST NOT round,
suppress, adjust, or plug it. A failing proof is a real defect in posting or in
the report's own arithmetic, and a reporting layer that silently corrects what it
reports converts a detectable bug into an undetectable one — which is the precise
opposite of why this exception was granted. This is the first place in the
constitution where a surface is required to display its own failure.

**It adds no write path of any kind.** No create, update, or delete of any row in
any table; no schema change — no table, column, index, or migration; and **no
posting caller**. The computed accumulated-earnings equity line the Balance Sheet
presents is a presentation device, labelled as computed rather than posted, and it
is what allows that statement to balance while ADR 0007's exclusion of a year-end
retained-earnings close stands; nothing is posted to Retained Earnings or to any
other account to produce it, and no account is referenced by code anywhere in the
feature.

ADR 0009 does not approve any API surface; accounts-receivable or
accounts-payable subledgers or aged reports; supplier bills, expenses, refunds,
or a tax-definitions catalogue; a year-end retained-earnings close; a cash-flow
statement, budget-versus-actual comparison, comparative or multi-period columns,
consolidation, or segment and dimension reporting; multi-currency, conversion, or
revaluation; cost accounting, inventory valuation, or cost-of-goods-sold
derivation; bank accounts or reconciliation; PDF rendering; scheduled or emailed
report delivery; saved report definitions; or report-result caching. Any of these
requires its own specification and either a separate ADR or an explicit amendment
to ADR 0009.

**ADR 0007's no-automatic-posting rule and ADR 0006's Purchasing prohibition both
survive this exception untouched.** The authorised posting callers are exactly
those already granted; this exception adds none, and a ledger that can now be
*reported on* is still not permission to post to it. Reporting on the ledger
grants the Purchasing module nothing.

The dashboard UI framework is not locked (React is likely but not committed);
frontend specs MUST focus on screens, flows, states, forms, and API mapping
rather than a specific framework.

## Specification Governance

All implementation MUST be derived from this canonical documentation set:

- `.specify/memory/constitution.md` (this file)
- `Docs/PRD.md`
- `Docs/SDD.md`
- `Docs/database/ERD.md`
- `Docs/database/DFD.md`
- `Docs/api/API_CONTRACT.md`
- `Docs/architecture/SYSTEM_ARCHITECTURE.md`
- `Docs/architecture/COMPONENT_DESIGN.md`
- `Docs/diagrams/SEQUENCE_DIAGRAMS.md`
- `Docs/IMPLEMENTATION_PLAN.md`
- `Docs/TESTING_STRATEGY.md`
- `Docs/CONFIGURATION.md`
- `Docs/INFRASTRUCTURE.md`
- `Docs/MONITORING.md`

The documentation set is intended to be extractable into per-feature Spec Kit
specs, in this order: `001-project-foundation`, `002-database-foundation`,
`003-auth-users-spatie-access`, `004-media-library-files`,
`005-products-variants-warehouses-inventory`,
`006-chart-of-accounts-and-journals`,
`007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition`, `009-credit-notes`,
`010-customer-app-flows`, `011-employee-app-plans-visits-ai`,
`012-tickets-maintenance`, `013-crm-marketing`,
`014-reporting-notifications-audit`. Extraction order MAY be adjusted by the
project owner as priorities change, but MUST NOT skip a feature's
prerequisites (e.g., inventory specs before sales-flow specs).

The Employees dashboard work delivered as `015-employees-plans-visits-dashboard`
corresponds to the `011-employee-app-plans-visits-ai` entry above. ADR 0003
authorises only that entry's dashboard portion; the `-app-` (employee mobile
application) portion of the historical name remains out of scope pending its
own specification and ADR.

The Support and Maintenance dashboard work delivered as
`016-support-maintenance-dashboard` corresponds to the `012-tickets-maintenance`
entry above. ADR 0004 authorises only that entry's dashboard portion; the
customer-app ticket intake and Stripe ticket-payment portions of that entry
remain out of scope pending their own specification and ADR, and depend on the
unbuilt `010-customer-app-flows` and
`008-payments-stripe-manual-tax-recognition` entries.

The Purchasing dashboard work delivered as `017-purchasing-orders-suppliers`
has **no** corresponding entry in the extraction order above. Suppliers appear
as a feature area in Product Scope & Boundaries, but no purchasing spec was
enumerated. This work is therefore an owner-prioritised addition to the
extraction order rather than a reordering of it, authorised by ADR 0006.

It does not skip a prerequisite. Its only hard dependency is
`005-products-variants-warehouses-inventory`, which is built: purchase-order
lines reference existing product variants, units, and warehouses, and all
receiving is posted through the existing Inventory operation services rather
than through any new stock-writing path. It has no dependency on
`006-chart-of-accounts-and-journals`, `007-sales-flow-quotation-delivery-invoice`,
or `008-payments-stripe-manual-tax-recognition` **because** ADR 0006 excludes
supplier bills, accounts payable, payments to suppliers, journal entries, and
purchase-tax recognition from its scope.

That exclusion is load-bearing, not cosmetic. Adding any accounts-payable or
general-ledger behaviour to the Purchasing module before
`006-chart-of-accounts-and-journals` exists would skip a prerequisite and
violate this section, regardless of how small the addition appears.

The Accounting foundation work delivered as `018-chart-of-accounts-journals`
corresponds to the `006-chart-of-accounts-and-journals` entry above. Unlike
015, 016, and 017, it is **not** an owner-prioritised addition or a
dashboard-only slice of a larger entry: it is that entry, delivered in
documented order, and its only prerequisites — `002-database-foundation` and
`003-auth-users-spatie-access` — are built. ADR 0007 authorises it.

Two consequences follow, and neither may be read loosely.

First, `007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition`, and `009-credit-notes` are
unblocked as to *this* prerequisite once 018 ships. They remain blocked on
their own specifications and ADRs.

Second, and against the more tempting reading: the completion of 018 does
**not** relax the Purchasing prohibition above. That prohibition survives
because ADR 0006 excludes supplier bills, accounts payable, payments to
suppliers, journal entries, and purchase-tax recognition from the Purchasing
module's scope — not merely because the ledger did not exist when ADR 0006 was
written. A ledger that exists is not permission to post to it. Adding any
accounts-payable or general-ledger behaviour to Purchasing still requires an
explicit amendment to ADR 0006, and ADR 0007 grants no automatic posting from
any document to anything.

The Sales lifecycle, Payments, and Credit Notes work specified as
`019-sales-lifecycle-payments-credits` delivers **three** entries of the
extraction order above as a single feature:
`007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition` (its manual channel only), and
`009-credit-notes`. ADR 0008 authorises it. Their shared prerequisite,
`006-chart-of-accounts-and-journals`, is built as spec 018, so no prerequisite is
skipped; `005-products-variants-warehouses-inventory` is likewise built and
supplies the variants, warehouses, pricing, and the sole stock-writing path the
delivery step uses.

Bundling three entries **supersedes** ADR 0007's contrary judgement, which
rejected combining the financial entries on the grounds that changes must stay
small and reviewable. That reversal is a deliberate project-owner decision of
2026-08-23, recorded as such in ADR 0008 §Two reversals of ADR 0007 and in spec
019 §Owner Decisions D5. It is not a withdrawal of the reviewability rule in
`.ai/feature-development` §3, which continues to bind every other feature. The
agreed mitigation is that spec 019's nine user stories are ordered P1 → P3 with
each independently shippable, and that this ordering MUST appear in that
feature's `plan.md` and `tasks.md` as real phase boundaries rather than as labels
on one undifferentiated batch of work. A reviewer who finds it has degenerated
into the latter should treat that as a governance failure, not a formatting one.

Two consequences, and neither may be read loosely.

First, `010-customer-app-flows` remains blocked and untouched. ADR 0008's
decision that a customer's quotation accept or reject is **recorded by an admin
or employee** exists precisely so that this feature creates no customer-facing
surface. The Stripe half of `008` is likewise undelivered: only its manual
payment channel and its tax-recognition logic ship here, and the remaining Stripe
work requires its own specification and either a separate ADR or an amendment to
ADR 0008.

Second, and against the same tempting reading that 018 invited: ADR 0008 wiring
three documents to the ledger does **not** relax the Purchasing prohibition
either. ADR 0006's exclusion of supplier bills, accounts payable, payments to
suppliers, journal entries, and purchase-tax recognition is a statement about the
Purchasing module's scope, not about the ledger's availability. The ledger now has
three authorised callers; a fourth still requires an explicit amendment to ADR
0006. The same holds for ADR 0004 and ticket payments.

The Accounting Financial Reports work delivered as
`020-accounting-financial-reports` has **no** corresponding entry in the
extraction order above. Ledger reporting appears in no enumerated entry: the
closest, `014-reporting-notifications-audit`, bundles reporting with
notifications and audit visibility, and shares neither data nor permission with
the general ledger. This work is therefore an owner-prioritised addition to the
extraction order rather than a reordering of it, as
`017-purchasing-orders-suppliers` was, authorised by ADR 0009.

It skips no prerequisite. Its only hard dependency,
`006-chart-of-accounts-and-journals`, is built as spec 018. It has **no**
dependency on `007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition`, or `009-credit-notes` — delivered
together as spec 019 — **because** it reads the ledger rather than the documents.
Every entry spec 019 posts appears in these reports automatically, with no change
to either specification, so the two may land in either order and neither blocks
the other.

Three consequences, and none may be read loosely.

First, this is the first exception granted with **no ERD footprint**. ADR 0009
authorises no table, column, index, or migration. Principle I's requirement that
database design be finalised before implementation begins is satisfied trivially
rather than waived, and `Docs/database/ERD.md` needs no amendment — the first time
that has been true of a module-scale feature.

Second, and against the most tempting reading available to a reporting feature:
**being able to read the ledger in aggregate is not permission to write to it.**
ADR 0009 adds no posting caller. The callers authorised by ADR 0008 and by the
accounting foundation's manual path remain the complete list. A reporting surface
is the most natural place for a posting path to be added quietly — a "post the
year-end close from the Balance Sheet" convenience is one line of plausible code
and would be a governance breach — and the specification's success criteria
include a test asserting that producing and exporting every report writes no row
to any table.

Third, the reserved `accounting` navigation slots for `accounts_payable`,
`bills`, and `expenses` are now approved by ADR 0011 and may be implemented in
the Accounting module. `accounts_receivable`, `refunds`, and `taxes` remain
placeholders under `021-accounting-receivables-tax-refunds`. The payables
exception is explicitly limited to its accepted ADR and does not relax the
Purchasing boundary.

## Governance

This constitution supersedes all other engineering practices and prior
informal conventions in this repository. Any conflict between this document
and code, comments, or other guidance files MUST be resolved in favor of this
constitution unless the project owner approves an amendment.

**Amendment procedure**: Amendments are proposed as an update to this file,
must state the rationale for the change, and require project-owner approval
before merge. On approval, the version is bumped per the policy below, the
Sync Impact Report at the top of this file is regenerated, and any
dependent templates or agent guidance files are updated in the same change.

**Versioning policy**: This constitution uses semantic versioning
(MAJOR.MINOR.PATCH):
- MAJOR: backward-incompatible governance or principle removals/redefinitions.
- MINOR: a new principle or section is added, or existing guidance is
  materially expanded.
- PATCH: clarifications, wording, or typo fixes with no semantic change.

**Compliance review**: Every implementation task MUST be checked against the
Core Principles above before it is considered done. Reviewers (human or
agent) MUST reject work that violates Principle III (Financial & Inventory
Integrity) or Principle V (AI Isolation & Human Oversight) outright, as these
are non-negotiable. Use this constitution, together with the documents
listed under Specification Governance, as the baseline for all runtime
development guidance.

**Version**: 1.10.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-08-26
