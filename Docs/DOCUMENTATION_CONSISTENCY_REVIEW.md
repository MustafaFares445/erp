# IERP Documentation Consistency Review

## 1. Overall Status

Pass, with the Phase 0 cross-module ownership addendum below taking precedence for
the Purchasing → Logistics / Inventory → Accounting flow changed on 2026-09-10.

The generated documentation set follows the confirmed IERP baseline: Laravel API backend, API-first dashboard/customer/employee clients, separated documentation files, full database design, API contract, implementation plan, testing strategy, and Spec Kit extraction mapping.

## 2. Missing Requirements

| Requirement | Missing From | Recommendation |
|---|---|---|
| Exact database engine | All technical docs | Keep as open question until MySQL/PostgreSQL is confirmed. |
| Exact AI provider | AI/config/infrastructure docs | Keep provider replaceable until confirmed. |
| Invoice PDF branding/layout | PRD/SDD/API | Define before invoice PDF implementation. |
| Currency and tax rate seed values | Database/config docs | Confirm with accounting owner before seeding production data. |

## 3. Conflicting Requirements

| Conflict | Files Affected | Recommendation |
|---|---|---|
| SRS mentions website inventory sync, but current decision says skip website | PRD, SDD, architecture, implementation plan | Current docs correctly mark website implementation as out of scope while keeping APIs future-ready. |
| SRS originally described salary by completion percentage, later decision added optional base salary | PRD, SDD, ERD, implementation plan | Current docs include optional base salary with default `use_base_salary=false`. |
| Older Purchasing/Accounting documentation forbids any Purchasing-triggered Bill creation, while Phase 0 now requires an accepted PO to provision an Accounting-owned Draft Bill | ADR 0011, ERP domain/cross-module flow documents | ADR 0012 and `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` supersede only that narrow statement: acceptance may request one Draft Bill, but Accounting exclusively owns approval, posting, liability, and payment. |
| Older flow descriptions may still show PO-owned destination warehouse, stock-owned reorder level, or receipt-derived commercial cost | ERP domain/cross-module flow documents | `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` is the authoritative Phase 0 correction for these touched flows. |

## 4. Over-Engineered or Extra Features

| Extra Feature | Files Affected | Recommendation |
|---|---|---|
| None confirmed | N/A | The docs avoid microservices, CQRS, event sourcing, Kubernetes-first deployment, supplier portal, customer credit limits, and active website implementation. |

## 5. Spec Kit Readiness

| Area | Status | Notes |
|---|---|---|
| Constitution | Ready | `spec.constitution.md` included. |
| PRD | Ready | Includes product scope, users, flows, functional and non-functional requirements. |
| SDD | Ready | Includes feature design, acceptance criteria, data/API impact, edge cases. |
| Database | Ready | Full table-by-table ERD with relationships, indexes, constraints, migration order. |
| API Contract | Ready | Includes dashboard, customer, employee, and Stripe webhook APIs. |
| Tasks/Plan | Ready | Implementation plan can be converted into Spec Kit tasks. |
| Testing | Ready | Critical accounting, inventory, payment, AI, and access tests included. |
| Phase 0 ownership | Ready | ADR 0012 + `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` define and guard the corrected ownership model. |

## 6. Final Documentation Summary

### Files Reviewed

- docs/PRD.md
- docs/SDD.md
- docs/IMPLEMENTATION_PLAN.md
- docs/TESTING_STRATEGY.md
- docs/CONFIGURATION.md
- docs/INFRASTRUCTURE.md
- docs/MONITORING.md
- docs/api/API_CONTRACT.md
- docs/architecture/SYSTEM_ARCHITECTURE.md
- docs/architecture/COMPONENT_DESIGN.md
- docs/database/ERD.md
- docs/database/DFD.md
- docs/diagrams/SEQUENCE_DIAGRAMS.md
- spec.constitution.md
- Docs/adr/0012-origin-domain-owns-business-facts.md
- Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md

### Main Features Covered

- Authentication and user access
- Customers, employees, and suppliers
- Products, product variants, and multi-warehouse inventory
- Full chart of accounts and journal entries
- Quotations, delivery notes, invoices, credit notes
- Stripe and manual payments
- Tax recognition on payment collection
- Employee plans, tasks, visits, GPS, salary calculation
- AI voice-note transcription and sales draft detection
- Tickets and maintenance
- CRM and marketing
- Reports, notifications, audit logs
- Phase 0 Purchasing → Logistics / Inventory → Accounting ownership and automatic acceptance handoff

### Important Assumptions

- Laravel API backend.
- Dashboard/customer/employee clients consume API.
- Website implementation is skipped now.
- Supplier portal is skipped now.
- Customer credit limits are not required.
- An accepted PO creates workflow artefacts (inbound allocation, optional confirmation, Draft Bill) but does not create physical stock or an Accounting liability.

### Open Questions

- Confirm database engine.
- Confirm AI transcription provider.
- Confirm invoice PDF branding.
- Confirm currencies and tax settings.
- Confirm manual payment approval behavior.

### Recommended Next Step

For Phase 0 cross-module behavior, use `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` and ADR 0012 together with `ERP_CROSS_MODULE_REMEDIATION_PLAN.md`. For untouched areas, continue using `docs/IMPLEMENTATION_PLAN.md` as the main implementation reference and convert each phase into Spec Kit-compatible feature specs and tasks.

## 7. Phase 0 Documentation Precedence

The following precedence is explicit so documentation cannot silently reintroduce
legacy ownership:

1. `Docs/adr/0012-origin-domain-owns-business-facts.md` — immutable ownership decision.
2. `Docs/PHASE0_CROSS_MODULE_OWNERSHIP.md` — canonical corrected flow and ownership matrix.
3. `ERP_CROSS_MODULE_REMEDIATION_PLAN.md` — implementation sequence and exit gates.
4. Existing `Docs/ERP_DOMAIN_MODEL.md`, `Docs/CROSS_MODULE_BUSINESS_FLOWS.md`, and ADR 0011 remain authoritative outside the Phase 0 statements explicitly superseded by items 1–2.

This precedence is narrow. It does not rewrite unrelated Sales, Accounting,
Inventory, tax, payment, or CRM behavior.
