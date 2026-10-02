# Support & Maintenance Workflows

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: Support services/tests
---

## Ticket

`Intake -> triage -> Pending Payment or Live -> Assigned -> In Progress -> Waiting Customer/Resume -> Resolved -> Closed`

Exact allowed transitions remain enforced by `TicketLifecycleService`.

## Chargeable Ticket

`Ticket -> TicketPaymentLink -> provider PaymentTransaction -> settlement -> ticket/maintenance billing continuation`

Payments executes provider money movement; Support owns the support-state consequence.

## Maintenance

`Ticket/Standalone -> Maintenance Open -> Diagnose -> coverage/billing decision -> approval/repair -> QA -> Closed`

Chargeable work can generate Sales quotation/invoice through `MaintenanceBillingService`.

## Service Parts

`Service Record -> consume part -> Inventory-owned stock effect`

Reversal uses the dedicated reverse path.

## Warranty

`Shipment arrival -> warranty activation -> diagnosis -> coverage decision -> repair/replacement/recovery`

Posted customer-return/replacement behavior updates entitlement through explicit warranty services.

## Preventive Maintenance

`Schedule -> generate occurrences -> due/missed/skip -> linked maintenance execution -> completion`
