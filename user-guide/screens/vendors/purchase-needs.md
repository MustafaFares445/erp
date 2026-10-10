---
title: "Purchase Needs"
module: vendors
pages: ["overview"]
screenshots: ["vendors-purchase-needs-overview"]
lastVerified: 2026-10-07
---

# Purchase Needs

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Operator steps and checks” instructions below.
- Follow the documented “Screen references” instructions below.

> Ownership: Purchasing records commercial supplier commitments; Inventory posts physical quantities; Accounting and Payments manage AP and settlement.

## Operator steps and checks

Purchase Needs combines **Sales procurement requirements** and **Inventory replenishment requirements** as read-only projections of their owning modules. Search by order reference, SKU, product, warehouse, status or linked PO. Inspect the origin, outstanding base quantity and eligible-supplier count before sourcing.

For a Sales shortage, choose **Create from Sales demand**, then the Sales Order, currency and an eligible supplier. The supplier list is dependent on the selected order and active matching supplier-product references in that currency. The supported path creates one draft PO per open Sales requirement. For replenishment, choose **Create from Replenishment**, select eligible recommendations, and review the resulting grouped draft POs; internal transfer coverage is removed from external buying need. **Create Purchase Order** opens independent draft creation.

No Purchase Need action posts physical stock. If no supplier is eligible, maintain its active supplier-product reference first rather than bypassing commercial validation.

## Screen references

### Overview

<IerpScreenshot id="vendors-purchase-needs-overview" title="Purchase Needs — Overview" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Needs" />

See also: [Procure to Pay](/workflows/procure-to-pay).
