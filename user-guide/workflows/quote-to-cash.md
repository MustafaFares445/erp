---
title: "Quote to Cash"
type: workflow
lastVerified: 2026-10-07
---

# Quote to Cash

Complete a customer sale from the first commercial offer through confirmed demand, warehouse execution, invoicing, collection and accounting.

<IerpWorkflow :steps='["Customer", "Quotation", "Sales Order", "Fulfillment", "Delivery / Shipment", "Invoice", "Payment", "Accounting"]' />

## Modules involved

CRM, Sales, Inventory, Accounting.

## When to use this workflow

Use this workflow when you need to complete **Quote to Cash** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Customer

Start from an approved/usable customer profile so pricing, payment terms and commercial ownership can resolve correctly.

**Work in:** [Customer](/screens/crm/customer)

Maintain the customer master profile and review approval, commercial defaults, addresses, interactions, sales, service and equipment context in one place.

### 2. Quotation

Build the offer, verify validity/pricing provenance and send it. No stock or ledger effect occurs here.

**Work in:** [Quotation](/screens/sales/quotation)

Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order.

### 3. Sales Order

Convert only an Accepted quotation. The conversion is one-time and creates confirmed customer demand.

**Work in:** [Sales Order](/screens/sales/order)

Manage confirmed customer demand, availability, procurement coverage and the release boundary into fulfillment.

### 4. Fulfillment

Release/plan fulfillment against available stock. Any uncovered demand can create procurement requirements instead of pretending stock exists.

**Work in:** [Fulfillment](/screens/inventory/outbound-fulfillment)

Plan and execute outbound customer fulfillment against released demand before delivery stock posting and shipment progress.

### 5. Delivery / Shipment

Complete the physical delivery through Inventory/Logistics and progress the shipment to arrival; physical custody changes here, not on the quotation.

**Work in:** [Delivery / Shipment](/screens/inventory/shipment)

Track planned shipments from warehouse dispatch through in-transit and arrival confirmation without becoming the stock ledger.

### 6. Invoice

Create/issue the invoice from eligible commercial/fulfillment context. Issuing creates the approved accounting effect while settlement remains separate.

**Work in:** [Invoice](/screens/sales/invoice)

Create, issue and review customer invoices while keeping document lifecycle, receivable balance and payment settlement as separate controlled facts.

### 7. Payment

Record and post the customer payment, allocating it to eligible invoices or leaving valid remainder as customer deposit.

**Work in:** [Payment](/screens/sales/payment)

Record customer money, allocate it to eligible invoices or deposits, and post/reverse the controlled collection effect.

### 8. Accounting

Review the resulting receivable, collection, tax and ledger effects in Accounting; corrections use explicit credit/reversal paths.

**Work in:** [Accounting](/screens/accounting/journal-entry)

Review and post balanced accounting entries, using explicit reversal/correction behavior after posting instead of destructive edits.

## Important business rules

- A quotation does not change stock.
- An accepted quotation converts to a Sales Order; it does not jump directly to delivery.
- Inventory owns reservation, lot/serial allocation and physical posting.
- Payment posting and accounting settlement are separate from the invoice document status.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [CRM](/screens/crm/)
- [Sales](/screens/sales/)
- [Inventory](/screens/inventory/)
- [Accounting](/screens/accounting/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
