---
title: "Customer Pricing"
type: workflow
lastVerified: 2026-10-07
---

# Customer Pricing

Understand how customer profile, currency, price lists, tiers, quantity breaks and floor controls influence selling price.

<IerpWorkflow :steps='["Customer Profile", "Currency", "Price List / Tier", "Product / Variant", "Quantity Break", "Resolved Price", "Quotation"]' />

## Modules involved

CRM, Sales.

## When to use this workflow

Use this workflow when you need to complete **Customer Pricing** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Customer Profile

Confirm the customer profile and commercial defaults are ready for the next business document. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Customer Profile](/screens/crm/customer)

Maintain the customer master profile and review approval, commercial defaults, addresses, interactions, sales, service and equipment context in one place.

### 2. Currency

Use the active shared currency catalog for new work while preserving historical stored currencies. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Currency](/screens/accounting/currency)

Maintain the shared currency catalog, including active/default currency choices used by new transactions while preserving historical codes.

### 3. Price List / Tier

Review the applicable customer/product pricing tier and quantity-break context. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Price List / Tier](/screens/crm/pricing-tier)

Define reusable customer pricing tiers/discount behavior that can participate in deterministic price resolution.

### 4. Product / Variant

Verify catalog/variant/UOM/tracking configuration before relying on the item in downstream operations. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Product / Variant](/screens/inventory/product)

Maintain catalog product identity, classification and shared attributes while keeping variant, supplier and physical-stock facts separate.

### 5. Quantity Break

Review the applicable customer/product pricing tier and quantity-break context. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Quantity Break](/screens/crm/pricing-tier)

Define reusable customer pricing tiers/discount behavior that can participate in deterministic price resolution.

### 6. Resolved Price

Create or review the quotation, verify pricing/validity and use its lifecycle actions rather than editing status directly. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Resolved Price](/screens/sales/quotation)

Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order.

### 7. Quotation

Create or review the quotation, verify pricing/validity and use its lifecycle actions rather than editing status directly. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Quotation](/screens/sales/quotation)

Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order.

## Important business rules

- Pricing resolution should remain deterministic and preserve provenance.
- Inactive catalog values remain valid for historical records but are not offered for new transactions.

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
