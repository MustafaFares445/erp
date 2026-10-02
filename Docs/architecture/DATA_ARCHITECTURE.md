# Data Architecture

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: current Eloquent models/migrations and domain services
---

## Principle

The database schema is defined by Laravel migrations and current Eloquent models.

This document describes data architecture and ownership; it is not a hand-maintained exhaustive ERD.

## Why There Is No Giant Canonical ERD

The repository has a large, evolving relational model. A manually maintained all-table ERD becomes stale quickly.

Instead:

- migrations/models are schema truth;
- domain docs describe the important aggregates/relationships;
- workflow docs describe cross-domain provenance;
- generated/schema-inspection tooling should be used when an exhaustive diagram is required.

## Main Aggregate Families

### Identity
Users, employee/customer profiles, roles/permissions.

### CRM
Customer Profile, contacts/addresses/documents, Leads, Interactions, Campaigns, Customer Quotation Requests, Customer Return Requests.

### Catalog / Pricing
Products, Product Variants, UOM mappings, pricing tiers/history/floor overrides.

### Inventory
Warehouses/locations, Inventory Stocks, condition/lot balances, Serialized Units, Inventory Operations/Lines, Movements, Reservations, Counts, Returns, Corrections, condition changes, replenishment.

### Purchasing
Suppliers/product references, Purchase Orders/Lines, Purchase Inbounds/Lines/Allocations, Supplier Confirmations.

### Sales
Quotations/Lines, Orders/Lines, procurement requirements, Invoices/Lines, Invoice confirmations, Credit Notes/Lines.

### Logistics
Shipments, arrival confirmation/evidence, packages and fulfillment links to Inventory delivery operations.

### Payments
Payment Methods, Payments, Allocations, Payment Transactions, tax-recognition/deposit-application evidence.

### Accounting
Chart Accounts/types, Fiscal Periods, Journal Entries/Lines, Bills/Lines, Expenses, Supplier Payments/allocations, Refunds, Receivable Write-Offs.

### Employees
Employee Profiles, Sales Plans, Plan Tasks, Customer Visits, GPS Logs, Voice Notes/Transcriptions, performance/salary/bonus records.

### Support
Tickets/Assignments/Messages, SLA policies/state, Maintenance Records/Tasks/Parts/Costs, schedules/occurrences, Warranty Policies/Entitlements/Claims/Recovery, Ticket Payment Links.

### Notifications / Audit
Notification templates/preferences/deliveries and Spatie activity log.

## Provenance

IERP relies on explicit provenance rather than duplicated cross-domain state.

Examples:
- Inventory receipt line -> PO/inbound allocation.
- Delivery operation -> Sales Order/Order Line.
- Invoice -> delivery/order/maintenance context.
- Payment allocation -> Invoice.
- Journal Entry -> source document.
- Credit Note -> Invoice/Inventory Return where relevant.
- Customer Return Request -> resulting Inventory Return.
- Shipment -> Inventory delivery/order.
- Warranty entitlement -> shipped serialized equipment.

## Historical Snapshots

Commercial/physical documents retain important snapshots such as:
- UOM conversion factor;
- base quantity;
- price/tax values;
- approval/actor timestamps;
- configured auto-close/threshold facts where the workflow requires historical consistency.

## Mutation Strategy

Posted/committed accounting and inventory history is corrected through reversal/correction records, not destructive rewrites.

## Domain Data Pages

Detailed data-model pages may be added only where they materially help a domain. Do not duplicate every migration field in Markdown.
