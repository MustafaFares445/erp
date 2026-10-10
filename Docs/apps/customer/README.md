# Customer App V1

---
status: canonical
owner: customer-app
last_verified: 2026-10-07
verified_against: adopted customer V1 decisions, current backend services, current route table, design/customer-app-v1.pen
---

## Purpose

The Customer App is the customer-facing channel for account onboarding, product discovery, quotation-led ordering, shipment confirmation, invoices/payments, returns, support, warranty/equipment and notifications.

It does not own Inventory, Accounting, Purchasing or Support internals.

## Product Decisions

The adopted V1 decisions are consolidated in [Behavior](BEHAVIOR.md).

## Current Design Source

Editable visual source:

`design/customer-app-v1.pen`

Use [Design Handoff](DESIGN_HANDOFF.md) for the contract between visual design and business behavior.

## Backend Reality

The backend already contains many required domain capabilities: customer onboarding/approval/change requests, quote requests, return requests, pricing, Sales documents, shipment arrival confirmation, Stripe/payment transaction services, deposits/refunds, Support/warranty/maintenance and Notifications.

The runtime now exposes Sanctum customer login/logout plus a rollout-gated Support/Maintenance slice under `/api/customer`, including tickets, messages, customer-visible attachments, support payment session/status, equipment, maintenance, knowledge and satisfaction. The rest of the Customer App API — catalog, quotation/order, shipment, invoice/payment, returns, notifications and account workflows — is still incomplete and must not be inferred from the Pen design or backend services.

See [Backend Dependencies](BACKEND_DEPENDENCIES.md).

## Primary Navigation

The adopted product behavior expects access to:

- Home
- Products
- Orders / commercial records
- Support
- Notifications
- Profile / account

Exact placement is a UX/design concern; feature availability and ownership rules are defined by the behavior docs/backend.

## Cross-Domain References

- [Canonical Business Flows](../../product/BUSINESS_FLOWS.md)
- [CRM](../../domains/crm/README.md)
- [Sales](../../domains/sales/README.md)
- [Payments](../../domains/payments/README.md)
- [Logistics](../../domains/logistics/README.md)
- [Support](../../domains/support/README.md)
