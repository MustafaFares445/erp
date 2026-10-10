---
title: "Support Case Lifecycle"
type: workflow
lastVerified: 2026-10-07
---

# Support Case Lifecycle

Handle a customer ticket from intake and triage through SLA, assignment, work, customer waiting, resolution and closure.

<IerpWorkflow :steps='["Ticket", "Triage", "SLA / Entitlement", "Assignment", "Work", "Resolution", "Closure"]' />

## Modules involved

Support and Maintenance.

## When to use this workflow

Use this workflow when you need to complete **Support Case Lifecycle** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Ticket

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Ticket](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 2. Triage

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Triage](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 3. SLA / Entitlement

Verify service/warranty entitlement before deciding whether the requested work is covered or chargeable. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [SLA / Entitlement](/screens/support/support-entitlement)

Track whether a customer/equipment context is entitled to a configured support service and its current active/suspended/expired state.

### 4. Assignment

Assign the support work to the appropriate team/queue according to routing and skill context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Assignment](/screens/support/support-team)

Maintain advanced support teams used by assignment, queue and routing behavior.

### 5. Work

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Work](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 6. Resolution

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Resolution](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 7. Closure

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Closure](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

## Important business rules

- Ticket closure can be blocked by unfinished child work.
- Warranty and chargeability decisions should remain traceable.
- Support does not directly rewrite stock custody or posted accounting.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Support and Maintenance](/screens/support/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
