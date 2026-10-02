# IERP Documentation

---
status: canonical
owner: project
last_verified: 2026-10-02
verified_against: current repository, code/tests, CI/deploy configuration and documentation convergence audit
---

## Start Here

This is the canonical documentation entry point for IERP.

New developer / new agent read order:

1. [Product Overview](product/PRODUCT_OVERVIEW.md)
2. [System Overview](architecture/SYSTEM_OVERVIEW.md)
3. [Domain Map](architecture/DOMAIN_MAP.md)
4. The relevant domain documentation below
5. [Canonical Business Flows](product/BUSINESS_FLOWS.md) for cross-domain work
6. Relevant [ADRs](adr/README.md)
7. Current code and tests
8. An active plan only when implementing unfinished work

For setup and contribution workflow:

- [Local Setup](onboarding/LOCAL_SETUP.md)
- [Development Workflow](onboarding/DEVELOPMENT_WORKFLOW.md)
- [Testing & Quality](onboarding/TESTING_AND_QUALITY.md)
- [Agent Workflow](onboarding/AGENT_WORKFLOW.md)

## Source of Truth

For implemented behavior:

1. executable current code and tests;
2. accepted, non-superseded ADRs;
3. canonical domain docs;
4. canonical product/architecture/reference/operations docs.

For unfinished behavior, an explicitly active plan may describe intended future state, but it must not be presented as implemented.

See the project constitution at `.specify/memory/constitution.md`.

## Product

- [Product Overview](product/PRODUCT_OVERVIEW.md)
- [Roles & Permissions](product/ROLES_AND_PERMISSIONS.md)
- [Canonical Business Flows](product/BUSINESS_FLOWS.md)
- [Glossary](product/GLOSSARY.md)

## Architecture

- [System Overview](architecture/SYSTEM_OVERVIEW.md)
- [Domain Map](architecture/DOMAIN_MAP.md)
- [Data Architecture](architecture/DATA_ARCHITECTURE.md)
- [Integrations](architecture/INTEGRATIONS.md)
- [Security](architecture/SECURITY.md)

## Domains

- [Identity & Authorization](domains/identity/README.md)
- [Settings & Shared Catalogs](domains/settings/README.md)
- [Catalog & Pricing](domains/catalog/README.md)
- [Inventory](domains/inventory/README.md)
- [Purchasing & Suppliers](domains/purchasing/README.md)
- [Sales & Orders](domains/sales/README.md)
- [Payments](domains/payments/README.md)
- [Accounting](domains/accounting/README.md)
- [Logistics & Shipments](domains/logistics/README.md)
- [CRM](domains/crm/README.md)
- [Employees](domains/employees/README.md)
- [Support & Maintenance](domains/support/README.md)
- [Notifications](domains/notifications/README.md)
- [Reporting & Audit](domains/reporting/README.md)

Each domain documents only pages that add value: overview, business rules, workflows, testing, and later domain-specific data/UI/API detail when needed.

## Mobile Applications

- [Customer App V1](apps/customer/README.md)
  - [Behavior](apps/customer/BEHAVIOR.md)
  - [Backend Dependencies](apps/customer/BACKEND_DEPENDENCIES.md)
  - [Design Handoff](apps/customer/DESIGN_HANDOFF.md)
- [Employee Sales App V1](apps/employee/README.md)
  - [Behavior](apps/employee/BEHAVIOR.md)
  - [Backend Dependencies](apps/employee/BACKEND_DEPENDENCIES.md)
  - [Design Handoff](apps/employee/DESIGN_HANDOFF.md)

Editable visual sources remain:
- `design/customer-app-v1.pen`
- `design/employee-sales-app-v1.pen`

A Pen screen is not proof that a backend endpoint is implemented.

## Reference

- [Configuration](reference/CONFIGURATION.md)
- [Commands & Scheduler](reference/COMMANDS.md)
- [Status & Transitions](reference/STATUS_AND_TRANSITIONS.md)
- [API Reference / Current Exposure](reference/API.md)
- [Data Model Reference](reference/DATA_MODEL.md)

Roles and permission namespaces are documented in [Roles & Permissions](product/ROLES_AND_PERMISSIONS.md); the code-level permission enums/policies remain the exact ability catalogue.

## Operations

- [Deployment](operations/DEPLOYMENT.md)
- [Infrastructure](operations/INFRASTRUCTURE.md)
- [Monitoring](operations/MONITORING.md)
- [Backup & Recovery](operations/BACKUP_AND_RECOVERY.md)

## ADRs

[Architecture Decision Records](adr/README.md) explain why important decisions were made.

ADRs are historical decision records. Current domain docs explain what is true now.

## Active Plans

`Docs/plans/` is for active future work only.

Current active plan:

- [Employee Visit / AI API Implementation Plan](plans/EMPLOYEE_VISIT_AI_API_IMPLEMENTATION_PLAN.md)

Once a plan is implemented and its durable rules are merged into canonical docs/ADRs, remove it from the working tree. Git history is the archive.

## Spec Kit Workspace

`specs/` is reserved for active Spec Kit feature packages only. There are currently no numbered active packages in the working tree.

Completed packages are removed after durable rules and live references are migrated; Git history is the archive.

See [`specs/README.md`](../specs/README.md).

## Current API Reality

As of 2026-10-02, the runtime route table contains no `api/*` routes.

Older endpoint descriptions and current mobile designs must not be reported as implemented HTTP contracts. See [API Reference](reference/API.md).

## Documentation Change Rule

Update canonical docs in the same change when implementation changes:

- a business invariant/calculation;
- status/lifecycle behavior;
- domain ownership or cross-domain side effect;
- permissions/ownership;
- public/mobile API;
- configuration/deployment/monitoring requirements;
- user workflow another developer/agent must understand.

Do not create duplicate “final”, “v2”, findings, or completed implementation-plan documents.

## Quality

The project CI enforces:
- formatting/refactor checks;
- static analysis;
- **100% type coverage**;
- **100% code coverage**;
- behavioral tests;
- MySQL warehouse/concurrency acceptance.

See [Testing & Quality](onboarding/TESTING_AND_QUALITY.md).
