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

## Boundary

- Inventory owns parts stock mutation.
- Payments owns provider transaction/payment behavior.
- Sales owns quotations/invoices created for chargeable maintenance.
- Accounting owns resulting ledger truth.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
