# Reporting & Audit Domain

---
status: canonical
owner: reporting
last_verified: 2026-10-02
verified_against: app/Services/Reporting plus domain report services/resources/tests
---

## Purpose

Reporting aggregates read models from business domains without taking ownership of the underlying facts.

Current reporting implementation is intentionally distributed:

- Accounting: financial statements, AR/AP, tax.
- Inventory: inventory/reconciliation/condition/count reports.
- Purchasing: commitments/receiving/cost variance.
- Sales: funnel, delivery/invoice/collection/tax/customer reports.
- CRM: funnel/pipeline/attribution.
- Employees: employee reports/exports.
- Support: workload/SLA/maintenance/margin/compliance.
- Reporting service namespace: supplier comparison.
- Audit Logs: cross-domain activity visibility.

## Boundary

A report may read many domains but must not become a hidden write/repair path.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
