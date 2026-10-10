# Reporting & Audit Domain

---
status: canonical
owner: reporting
last_verified: 2026-10-07
verified_against: app/Services/Reporting plus current domain report services/resources/tests
---

## Purpose

Reporting aggregates read models from business domains without taking ownership of the underlying facts.

Current reporting implementation is intentionally distributed:

- Accounting: financial statements, AR/AP, tax.
- Inventory: Stock Levels, movement provenance including lot/serial, Expiry Lots, Devices, reconciliation/condition/count reporting.
- Purchasing: Open Commitments, Receiving Performance, Cost Variance and supplier execution views.
- Sales: funnel, delivery/invoice/collection/tax/customer reporting, including Customer Revenue and Returns Without Credit exception visibility.
- CRM: funnel/pipeline/attribution.
- Employees: employee reports/exports.
- Support: workload/SLA/maintenance/margin/compliance.
- Reporting service namespace: supplier comparison.
- Audit Logs: cross-domain activity visibility.

## Boundary

A report may read many domains but must not become a hidden write/repair path.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
