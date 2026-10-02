# Architecture Decision Records

---
status: canonical
owner: architecture
last_verified: 2026-10-02
verified_against: Docs/adr statuses and current implementation/domain documentation
---

## Purpose

ADRs preserve decision history: **why** an architectural/business boundary was chosen.

They do not replace canonical domain documentation, which states **what the system does now**.

## Current ADR Index

| ADR | Status in record | Current relevance |
|---|---|---|
| 0001 Filament Inventory Dashboard | Accepted | Current; Inventory admin surface remains Filament. |
| 0002 Filament CRM Dashboard | Accepted | Current. |
| 0003 Filament Employees Dashboard | Accepted | Current for dashboard; Employee mobile/API is a separate later scope. |
| 0004 Filament Support & Maintenance Dashboard | Accepted | Current dashboard foundation; later domain implementation has expanded workflows. |
| 0005 Spatie Activitylog | Accepted | Current audit mechanism. |
| 0006 Filament Purchasing Dashboard | Accepted, amended by 0011/0012 | Current after amendments. |
| 0007 Filament Accounting Foundation | Accepted | Current foundation; later Sales/AR/AP ADRs add source-backed posting workflows. |
| 0008 Filament Sales/Payments | Accepted | Current. |
| 0009 Accounting Financial Reports | Accepted | Current. |
| 0010 Receivables/Tax/Refunds | Proposed | The corresponding behavior is implemented in current code, but the ADR's governance status remains Proposed until explicitly ratified; do not silently rewrite decision history. |
| 0011 Payables/Expenses/Bills | Accepted | Current; explicitly amends Purchasing boundary. |
| 0012 Origin Domain Owns Business Facts | Accepted | Current core cross-domain ownership rule. |
| 0013 Warehouse-Level Stock Identity and Putaway Gate | Accepted | Current; location/bin/putaway features remain gated until Inventory custody is redesigned below warehouse level. |

## Reading Rule

When an ADR and current code appear different:

1. Check whether a later ADR amended/superseded the older decision.
2. Read the canonical domain documentation.
3. Treat current tests/code as implemented truth.
4. Do not rewrite historical ADR rationale to make it look as though the original decision was different.

## Historical Inputs

Older PRD/SDD/global implementation plans have been replaced by canonical product/domain/architecture documentation and may be removed from the working tree.

Git history remains the historical archive.

## Canonical Context

- [Product Overview](../product/PRODUCT_OVERVIEW.md)
- [Canonical Business Flows](../product/BUSINESS_FLOWS.md)
- [System Overview](../architecture/SYSTEM_OVERVIEW.md)
- [Domain Map](../architecture/DOMAIN_MAP.md)
- [Data Architecture](../architecture/DATA_ARCHITECTURE.md)
