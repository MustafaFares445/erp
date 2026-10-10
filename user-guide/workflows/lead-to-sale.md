---
title: "Lead to Sale"
type: workflow
lastVerified: 2026-10-07
---

# Lead to Sale

Move potential business from a lead through opportunity and customer context into the formal Sales lifecycle.

<IerpWorkflow :steps='["Lead", "Qualification", "Opportunity", "Customer", "Quotation", "Sales Order"]' />

## Modules involved

CRM, Sales.

## When to use this workflow

Use this workflow when you need to complete **Lead to Sale** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Lead

Capture and qualify the potential customer/business before promotion into a formal opportunity. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Lead](/screens/crm/lead)

Capture and qualify potential customers from New through Contacted/Qualified until conversion or disqualification.

### 2. Qualification

Capture and qualify the potential customer/business before promotion into a formal opportunity. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Qualification](/screens/crm/lead)

Capture and qualify potential customers from New through Contacted/Qualified until conversion or disqualification.

### 3. Opportunity

Review the commercial opportunity stage/value and progress it toward quotation or closure. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Opportunity](/screens/crm/sales-opportunity)

Track active revenue opportunities and their sales stage until won/lost or converted into the appropriate commercial follow-up.

### 4. Customer

Confirm the customer profile and commercial defaults are ready for the next business document. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Customer](/screens/crm/customer)

Maintain the customer master profile and review approval, commercial defaults, addresses, interactions, sales, service and equipment context in one place.

### 5. Quotation

Create or review the quotation, verify pricing/validity and use its lifecycle actions rather than editing status directly. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Quotation](/screens/sales/quotation)

Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order.

### 6. Sales Order

Confirm customer demand and use the order lifecycle to control release into fulfillment/procurement. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Sales Order](/screens/sales/order)

Manage confirmed customer demand, availability, procurement coverage and the release boundary into fulfillment.

## Important business rules

- CRM does not bypass quotation/order rules.
- Customer commercial defaults can guide currency, price list, payment terms and assigned salesperson.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [CRM](/screens/crm/)
- [Sales](/screens/sales/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
