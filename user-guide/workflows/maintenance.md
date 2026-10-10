---
title: "Maintenance and Repair"
type: workflow
lastVerified: 2026-10-07
---

# Maintenance and Repair

Coordinate equipment diagnosis, approval, repair, quality checks and service evidence.

<IerpWorkflow :steps='["Request", "Equipment Context", "Diagnosis", "Approval", "Repair", "QA", "Service Record"]' />

## Modules involved

Support and Maintenance, Inventory.

## When to use this workflow

Use this workflow when you need to complete **Maintenance and Repair** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Request

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Request](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 2. Equipment Context

Use the same serialized customer equipment identity to review custody, warranty and service history. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Equipment Context](/screens/support/support-equipment)

Use Equipment 360 to review customer-owned serialized equipment, custody, entitlement, warranty and service history from one support-facing view.

### 3. Diagnosis

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Diagnosis](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 4. Approval

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Approval](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 5. Repair

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Repair](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 6. QA

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [QA](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 7. Service Record

Record the performed service, evidence and result as the durable history of the service event. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Service Record](/screens/support/service-record)

Preserve completed service evidence, work performed and equipment context as the durable service-history record.

## Important business rules

- Known serialized customer equipment should be reused when available.
- Parts consumption and custody changes are posted through Inventory.
- Chargeable work can create Sales-owned commercial documents.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Support and Maintenance](/screens/support/)
- [Inventory](/screens/inventory/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
