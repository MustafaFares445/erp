# Support & Maintenance Domain

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: app/Services/Support, support enums/permissions, tests/Feature/Support
---

## Purpose

Support owns ticket intake/triage/lifecycle/SLA, maintenance requests and service records, maintenance costs/billing, preventive schedules, warranty entitlement/claims/recovery and ticket-payment coordination.

## Main UI Surfaces

Tickets, SLA Policies, Maintenance Requests, Maintenance Schedules, Service Records, Warranty Policies and Support Reports.

The Support dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards). It has assignee and priority filters, the ticket trend beside service economics (warranty and goodwill cost, customer-paid service, third-party recovery), the ticket and maintenance work queues side by side, and upcoming maintenance across the full width.

The Tickets and Maintenance Requests lists use the standard list-table experience ([ADR 0014](../../adr/0014-standard-list-table-experience.md)): a view tab bar, per-user favorites, Group by and slide-over rule filters. Ticket SLA-breach filters and Trashed stay as separate quick filters.

## Boundary

- Inventory owns parts stock mutation.
- Payments owns provider transaction/payment behavior.
- Sales owns quotations/invoices created for chargeable maintenance.
- Accounting owns resulting ledger truth.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
