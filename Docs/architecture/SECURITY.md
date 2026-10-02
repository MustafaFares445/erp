# Security Architecture

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: policies, permission enums, service authorization patterns and mobile app requirements
---

## Authorization

IERP uses domain-scoped permissions with Laravel Gate/Policies and Spatie Permission.

Filament visibility is usability; policy/service authorization is security.

## Least Privilege / Domain Isolation

A permission in one module does not imply permission in another.

Cross-module permission-leak tests protect this boundary.

## Maker / Checker

High-risk financial/procurement workflows use separation of duties where defined, including PO approval, refunds and write-offs.

Any System Admin exemption is explicit to that workflow.

## Customer / Employee Ownership

Future mobile APIs must derive ownership from authentication.

Never trust client-provided `customer_id` or `employee_id` to authorize access.

## Financial Integrity

- Ledger writes go through Accounting posting services.
- Posted entries are immutable and corrected by reversal.
- Closed fiscal periods block posting unless reopened through authorized workflow.
- Payment/provider settlement uses idempotency/locking where required.

## Inventory Integrity

- Stock changes go through Inventory-owned services.
- Physical operations/reservations use row locks/transactions.
- Posted physical history is corrected explicitly.
- serial/lot/expiry/UOM rules are server-side.

## Media

Private business documents/evidence must use authenticated/authorized access paths rather than public predictable URLs.

## Audit

Spatie activity log records high-impact changes with actor/source context.

Audit logs are evidence and do not replace domain state.

## External Providers

- never store Stripe card credentials in IERP;
- verify provider result server-side;
- use idempotency for provider retries;
- AI output must not automatically become an irreversible business decision;
- secrets belong in environment/configuration, not source control.

## Mobile Security Requirements

Customer/Employee APIs must include authenticated ownership, lifecycle validation, private-media guards and server-derived prices/payment amounts.

Employee V1 additionally requires server-authoritative device binding when implemented.
