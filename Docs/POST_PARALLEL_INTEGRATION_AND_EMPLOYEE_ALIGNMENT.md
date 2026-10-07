# Post-Parallel Integration & Employee Alignment

## Scope

This document records the reconciliation of the completed Employee Module Dashboard & Backend Core improvement work with the completed Dental ERP Core Domain Remediation work.

The integration rule is:

- preserve both completed workstreams;
- reconcile shared contracts only where the parallel implementations introduced a real mismatch;
- keep Employee workflows simple;
- keep Product, Inventory, Purchasing, Sales, Accounting, Employees, CRM, and Support ownership separated.

## Integrated Baseline

The Dental ERP remediation was preserved as its own commit and merged into `dev` after the Employee implementation already present on `dev`.

The user-owned `design/employee-sales-app-v1.pen` working-tree change was intentionally left untouched by this integration.

## Conflict Audit Decisions

### Product / pricing / UoM

Employee quotation creation continues to delegate pricing and conversion rules to backend services.

- customer price lists are resolved by `PriceResolver`;
- quantity breaks remain backend-owned;
- sales UoM/packaging conversion remains backend-owned;
- price provenance is carried into quotation and downstream document lines;
- supplier costs, purchasing terms, replenishment configuration, and other procurement data are not added to Employee workflows.

### Quotation → Sales Order → Delivery

The finalized commercial lifecycle is:

```text
Quotation
→ Sales Order
→ Delivery Note / fulfillment
→ Invoice
→ Payment
```

The old quotation status name `converted_to_delivery` was inconsistent because conversion actually creates an `Order`. It is reconciled to `converted_to_order`, with a migration that updates existing records.

Employees do not bypass the Sales Order. Inventory reservation, availability, lot/serial enforcement, FEFO, picking, and posting remain backend/warehouse responsibilities.

### Employee visit customer context

Visit details may expose useful commercial context without exposing accounting or purchasing internals.

The integrated visit view includes customer commercial context and optional customer-owned serialized equipment.

A service visit may reference a serialized unit only when that unit is currently owned by the visit customer.

### Follow-up task provenance

Follow-up tasks remain Employee-domain tasks and are not converted into a generic workflow engine.

They may now carry nullable provenance links to:

- source visit;
- reviewed/selected sales opportunity;
- quotation;
- sales order;
- customer-owned serialized equipment.

### AI opportunities and performance

AI detections remain reviewable drafts.

```text
Pending AI Draft ≠ Confirmed Opportunity
```

Only opportunities in the accepted/reviewed state contribute to the opportunity performance factor. Draft and rejected detections do not increase performance or bonus calculations.

AI detection may remain product-level when transcript evidence is insufficient to identify a variant.

### Lot and serial responsibility

Default responsibility remains:

**Warehouse / Admin**
- lot selection;
- serial assignment;
- FEFO;
- picking;
- inventory posting.

**Employee**
- product;
- quantity;
- customer context;
- allowed commercial actions.

Customer-owned equipment is exposed only where it is operationally relevant to a visit.

### Navigation

Duplicate Purchasing navigation for supplier confirmations was removed from the main module registry where the workflow is already represented through the Purchase Order/supplier workflow.

## Migration Compatibility

The reconciliation migration is additive.

It:

- renames existing quotation status data from `converted_to_delivery` to `converted_to_order`;
- adds an optional customer-owned-equipment link to visits;
- adds optional opportunity / quotation / order / equipment provenance links to plan tasks.

No destructive reset is required and existing Employee records remain valid.

## Cross-Domain Verification

Dedicated integration regression coverage verifies:

1. only reviewed/accepted opportunities contribute to Employee performance;
2. visits cannot link equipment owned by another customer;
3. follow-up tasks preserve source visit, opportunity, quotation, order, and equipment provenance;
4. employee-authored quotations use customer price-list resolution and sales UoM conversion;
5. AI product detection can remain product-level and produces a reviewable draft.

The broader Employee, Sales, Inventory, Purchasing, and Support test suites are also part of the final integration gate.

## Final Domain Ownership

```text
Products
    product identity and configuration

Inventory
    stock, lot, serial, location and reservation enforcement

Purchasing
    supplier procurement

Sales
    quotations, sales orders, delivery/fulfillment and customer commercial documents

Accounting
    financial posting

Employees
    plans, tasks, visits and performance

CRM
    leads and opportunities

Support
    tickets, warranty and maintenance coordination
```

## Employee Change Gate

The integration deliberately does not add the following to normal Employee workflows:

- supplier cost;
- accounts payable balances;
- reorder configuration;
- supplier lead time;
- purchase-price history;
- journal-entry details;
- full lot/serial traceability screens.

The Employee experience remains centered on:

```text
Today
→ Visit
→ Customer
→ Product / Sales Action
→ Outcome
→ Follow-Up
→ Complete
```
