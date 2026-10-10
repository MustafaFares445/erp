---
title: "Field Service"
type: workflow
lastVerified: 2026-10-07
---

# Field Service

Schedule and execute a customer service appointment with the correct equipment, assignee and service outcome.

<IerpWorkflow :steps='["Service Need", "Appointment", "Assignment", "Visit", "Work", "Outcome", "Follow-up"]' />

## Modules involved

Support and Maintenance, Employees.

## When to use this workflow

Use this workflow when you need to complete **Field Service** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Service Need

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Service Need](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 2. Appointment

Schedule and execute field service with the correct customer, equipment and assignee. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Appointment](/screens/support/service-appointment)

Schedule and follow field-service appointments through dispatch, travel, on-site work, completion or cancellation.

### 3. Assignment

Schedule and execute field service with the correct customer, equipment and assignee. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Assignment](/screens/support/service-appointment)

Schedule and follow field-service appointments through dispatch, travel, on-site work, completion or cancellation.

### 4. Visit

Schedule and execute field service with the correct customer, equipment and assignee. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Visit](/screens/support/service-appointment)

Schedule and follow field-service appointments through dispatch, travel, on-site work, completion or cancellation.

### 5. Work

Record the performed service, evidence and result as the durable history of the service event. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Work](/screens/support/service-record)

Preserve completed service evidence, work performed and equipment context as the durable service-history record.

### 6. Outcome

Record the performed service, evidence and result as the durable history of the service event. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Outcome](/screens/support/service-record)

Preserve completed service evidence, work performed and equipment context as the durable service-history record.

### 7. Follow-up

Schedule and execute field service with the correct customer, equipment and assignee. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Follow-up](/screens/support/service-appointment)

Schedule and follow field-service appointments through dispatch, travel, on-site work, completion or cancellation.

## Important business rules

- Appointment status and ticket/maintenance status remain related but distinct.
- Evidence and equipment context should follow the service record.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Support and Maintenance](/screens/support/)
- [Employees](/screens/employees/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
