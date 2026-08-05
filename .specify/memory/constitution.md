<!--
Sync Impact Report
==================
Current entry: version 1.3.0 to 1.4.0 (MINOR: Product Scope & Boundaries
materially expanded to approve an Employees Filament dashboard exception
through ADR 0003). `Docs/adr/0003-filament-employees-dashboard.md` records
project-owner approval, scoped to dashboard-only administration of employee
profiles, monthly plans, tasks, visits, voice-note review, AI transcription
review, performance calculations, salary calculations, bonus review, employee
reports, and dashboard roles/permissions. It explicitly does not authorise
`/api/employee` endpoints, the employee mobile application, employee-app
visit capture, employee-app attendance capture, mobile authentication flows,
or any other employee-facing API functionality — those remain out of scope
pending their own specification and either a separate ADR or an explicit
amendment to ADR 0003. Specification Governance is amended to note that this
work corresponds to the `011-employee-app-plans-visits-ai` extraction-order
entry, with only its dashboard portion authorised.
Modified sections: Product Scope & Boundaries (third narrow Filament
  dashboard exception added); Specification Governance (extraction-order
  divergence note added).
Added sections: none. Removed sections: none.
Templates requiring updates:
  - ✅ .specify/templates/plan-template.md (generic Constitution Check gate,
    no static references to update)
  - ✅ .specify/templates/spec-template.md (no constitution-specific
    references)
  - ✅ .specify/templates/tasks-template.md (no constitution-specific
    references)
  - ✅ .claude/skills/speckit-*/SKILL.md (no stale agent-specific naming found)
Follow-up TODOs: none

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

**Version**: 1.4.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-08-04
