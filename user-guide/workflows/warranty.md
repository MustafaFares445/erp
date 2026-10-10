---
title: "Warranty Service"
type: workflow
lastVerified: 2026-10-07
---

# Warranty Service

Evaluate equipment entitlement and execute covered service while preserving warranty evidence and authorized corrections.

<IerpWorkflow :steps='["Equipment", "Warranty Policy", "Entitlement", "Ticket / Request", "Coverage Decision", "Service", "History"]' />

## Modules involved

Support and Maintenance.

## When to use this workflow

Use this workflow when you need to complete **Warranty Service** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Equipment

Use the same serialized customer equipment identity to review custody, warranty and service history. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Equipment](/screens/support/support-equipment)

Use Equipment 360 to review customer-owned serialized equipment, custody, entitlement, warranty and service history from one support-facing view.

### 2. Warranty Policy

Review the configured warranty rules/activation basis used to evaluate equipment coverage. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Warranty Policy](/screens/support/warranty-policy)

Define warranty coverage/activation rules that later determine eligible equipment entitlement and service treatment.

### 3. Entitlement

Verify service/warranty entitlement before deciding whether the requested work is covered or chargeable. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Entitlement](/screens/support/support-entitlement)

Track whether a customer/equipment context is entitled to a configured support service and its current active/suspended/expired state.

### 4. Ticket / Request

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Ticket / Request](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 5. Coverage Decision

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Coverage Decision](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 6. Service

Record the performed service, evidence and result as the durable history of the service event. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Service](/screens/support/service-record)

Preserve completed service evidence, work performed and equipment context as the durable service-history record.

### 7. History

Use the same serialized customer equipment identity to review custody, warranty and service history. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [History](/screens/support/support-equipment)

Use Equipment 360 to review customer-owned serialized equipment, custody, entitlement, warranty and service history from one support-facing view.

## Important business rules

- Warranty activation can depend on delivery, installation or commissioning policy.
- Warranty-date correction requires dedicated permission and a reason.
- Serialized equipment warranty snapshot must remain synchronized.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Support and Maintenance](/screens/support/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
