---
title: "Customer Equipment Lifecycle"
type: workflow
lastVerified: 2026-10-07
---

# Customer Equipment Lifecycle

Follow serialized equipment from Inventory delivery into customer custody, support, maintenance, loaner or supplier repair and later service history.

<IerpWorkflow :steps='["Serial Unit", "Delivery", "Customer Custody", "Equipment 360", "Ticket", "Maintenance / Loan / Supplier Repair", "Return / Service History"]' />

## Modules involved

Inventory, Sales, Support and Maintenance.

## When to use this workflow

Use this workflow when you need to complete **Customer Equipment Lifecycle** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Serial Unit

Trace the unique serial, condition and custody history for this physical unit. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Serial Unit](/screens/inventory/serialized-inventory-unit)

Track each serialized unit as a unique physical identity through warehouse custody, delivery, customer ownership and service history.

### 2. Delivery

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Delivery](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 3. Customer Custody

Trace the unique serial, condition and custody history for this physical unit. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Customer Custody](/screens/inventory/serialized-inventory-unit)

Track each serialized unit as a unique physical identity through warehouse custody, delivery, customer ownership and service history.

### 4. Equipment 360

Use the same serialized customer equipment identity to review custody, warranty and service history. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Equipment 360](/screens/support/support-equipment)

Use Equipment 360 to review customer-owned serialized equipment, custody, entitlement, warranty and service history from one support-facing view.

### 5. Ticket

Triage and progress the customer support case through assignment, work, resolution and closure. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Ticket](/screens/support/ticket)

Manage a customer support case from intake and triage through entitlement/SLA, assignment, work, resolution and closure.

### 6. Maintenance / Loan / Supplier Repair

Progress diagnosis, approval, repair and QA without losing equipment and warranty context. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Maintenance / Loan / Supplier Repair](/screens/support/maintenance-request)

Coordinate equipment diagnosis, customer/coverage approval, repair execution, QA and closure for maintenance work.

### 7. Return / Service History

Record the performed service, evidence and result as the durable history of the service event. Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.

**Work in:** [Return / Service History](/screens/support/service-record)

Preserve completed service evidence, work performed and equipment context as the durable service-history record.

## Important business rules

- Custody movement is an Inventory responsibility.
- Support references the same serialized identity rather than creating duplicate device records.
- Supplier repair and loaner work must be closed before the related service case can fully close when policy requires it.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)
- [Sales](/screens/sales/)
- [Support and Maintenance](/screens/support/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
