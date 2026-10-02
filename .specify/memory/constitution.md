# IERP Constitution

<!--
Sync Impact Report
==================
Version: 2.0.0
Date: 2026-10-02

This major documentation-governance revision removes the accumulated feature-by-feature
changelog from the constitution and aligns governance with the current implemented
Laravel/Filament system. Historical decisions remain in Git and Docs/adr/.

Material governance changes:
- current code/tests are explicitly the highest authority for implemented behavior;
- canonical domain/product/architecture docs replace removed monolithic PRD/SDD/ERD/API books;
- completed specs/plans are historical inputs rather than permanent current authority;
- the implemented Filament modular monolith is the current administration architecture;
- customer/employee mobile behavior is documented separately from API exposure;
- domain ownership, financial/inventory integrity, AI oversight and quality gates remain non-negotiable.
-->

## Core Principles

### I. Current Truth Is Verifiable

For already implemented behavior, authority is:

1. executable current code and tests;
2. accepted, non-superseded ADRs for intentional decisions;
3. canonical domain documentation under `Docs/domains/`;
4. canonical product/architecture/reference/operations documentation;
5. historical specs/plans only as background.

For unfinished behavior, an explicitly active plan/spec may define the intended future state, but it MUST NOT be represented as already implemented.

A behavior-changing change MUST update canonical documentation when it changes a business invariant, lifecycle, permission, domain boundary, public/mobile contract, configuration requirement or operational procedure.

### II. Domain-Driven Modular Monolith

IERP is a Laravel/Filament modular monolith.

Each business fact has one owning domain. Other domains MUST call the owner through its service/event boundary rather than create a parallel rule or shadow state.

Current ownership includes:

- Identity: authentication/roles/permissions foundation.
- Settings: shared configuration/currency/constraints.
- Catalog/Pricing: products, variants, UOM and price rules.
- Inventory: physical stock/custody/reservations/movements.
- Purchasing: supplier commercial commitments/inbounds.
- Sales: quotation/order/invoice/credit commercial lifecycle.
- Logistics: outbound fulfillment/shipment execution.
- Payments: collections, allocations, deposits and provider transactions.
- Accounting: chart/periods/journal/AR/AP/refunds/reports.
- CRM: customers/leads/campaigns/customer requests.
- Employees: plans/tasks/visits/AI evidence/performance/salary.
- Support: tickets/maintenance/warranty/service.
- Notifications: delivery/template behavior.
- Reporting: read/aggregation surfaces, not hidden writes.

Cross-domain ownership is detailed in `Docs/architecture/DOMAIN_MAP.md` and `Docs/product/BUSINESS_FLOWS.md`.

### III. Financial & Inventory Integrity (NON-NEGOTIABLE)

Inventory stock changes MUST use Inventory-owned posting/operation services and preserve movement/provenance history.

Accounting entries MUST use Accounting-owned journal posting and preserve double-entry balance, fiscal-period rules, immutability and reversal history.

Posted/committed facts MUST NOT be silently edited to make a report or balance appear correct. Corrections use explicit correction/reversal workflows.

Payments, credits, refunds and tax recognition MUST remain traceable to their source documents and MUST be idempotent/transactional where retries or concurrent actions can occur.

Reports MUST expose inconsistencies; they MUST NOT repair source data silently.

### IV. Access, Ownership, Media & Provider Safety

Authorization MUST be enforced in policies/services; hidden UI controls are not sufficient.

Domain permissions MUST remain isolated. A role in one module MUST NOT gain another module's business ability by accident.

Customer/employee APIs MUST derive ownership from authenticated identity. Client-supplied owner IDs, prices, tax values or payable totals MUST NOT be trusted as authority.

Sensitive media MUST use authorized access.

Secrets MUST remain in environment/secret stores.

Provider results such as Stripe payment/refund success MUST be verified server-side and made idempotent. IERP MUST NOT store card credentials.

### V. AI Isolation & Human Oversight (NON-NEGOTIABLE)

AI/transcription is an assistive subsystem, not an authoritative business actor.

- AI failure MUST NOT corrupt or irreversibly block the core visit/business workflow where a documented manual fallback exists.
- AI-generated outcomes/products/opportunities remain draft/reviewable until explicitly confirmed by the employee or owning domain rule.
- Provider confidence/metadata MUST be represented truthfully.
- Network/provider integration MUST be isolated behind replaceable contracts and deterministic test doubles.

### VI. Engineering Discipline

Agents and developers MUST:

- discover current implementation/tests before changing;
- use version-specific framework/package documentation;
- reuse existing services/components;
- keep changes reviewable;
- use explicit types and fail early;
- add regression tests for behavior changes;
- preserve/increase static-analysis quality;
- never weaken architecture/type/coverage gates to make CI pass;
- avoid touching unrelated working-tree changes.

The shared standard is `.ai/guidelines/project/feature-development.md`.

The current quality gate is documented in `Docs/onboarding/TESTING_AND_QUALITY.md`.

### VII. Decision and Documentation Governance

Use an ADR when a durable architectural/business ownership decision needs rationale/history.

Do not rewrite an old ADR to pretend the original decision was different. Amend/supersede it explicitly.

Use `Docs/plans/` for active future implementation plans only. Once implementation is complete and durable behavior is merged into canonical docs/ADRs, remove the plan from the working tree; Git is the archive.

Spec Kit packages are temporary implementation artifacts. Completed packages MUST be removed after durable rules and live references are migrated; Git history is the archive. Active packages MUST NOT outrank current code/tests/canonical docs for already implemented behavior.

## Product Scope & Boundaries

The current implemented administration surface is Filament 5 across the ERP domains documented under `Docs/domains/`.

Current mobile product definitions:
- Customer App V1: `Docs/apps/customer/`
- Employee Sales App V1: `Docs/apps/employee/`

As of 2026-10-02 the runtime route table has no `api/*` routes. Domain services or designs that anticipate Customer/Employee APIs do not constitute implemented API exposure.

The active Employee Visit/AI API plan remains future-state work until routes/controllers/resources/tests exist.

Scope must be derived from current canonical docs and accepted ADRs, not from removed historical PRD/SDD text.

## Specification Governance

Canonical documentation entry point:

`Docs/README.md`

Required implementation read order:

1. canonical documentation index;
2. owning domain;
3. relevant business rules/workflows;
4. accepted ADRs;
5. current tests/code;
6. active plan/spec only for unfinished work.

Spec Kit work MUST identify its owning domain and MUST reconcile durable rules back into canonical documentation after implementation.

A stale task checkbox does not override implementation evidence.

## Governance

This constitution governs engineering/documentation process and non-negotiable integrity boundaries. It does not replace executable domain rules.

Amendments:
- require project-owner approval;
- state the rationale;
- update affected canonical docs/agent guidance in the same change;
- use semantic versioning.

Versioning:
- MAJOR: incompatible governance/principle/source-of-truth change;
- MINOR: new principle or material expansion;
- PATCH: clarification without semantic change.

Reviewers MUST reject work that violates Principle III or Principle V.

**Version**: 2.0.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-10-02
