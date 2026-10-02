# IERP Domain Map

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: app/Services, app/Filament/Resources, tests/Feature, current repository structure
---

## Purpose

This page maps IERP business domains to their current implementation areas.

It is intentionally high-level. Detailed invariants, state transitions, data models, UI behavior, and tests belong in each future `Docs/domains/<domain>/` folder.

## Architecture Shape

IERP is a Laravel/Filament modular monolith.

Business logic is distributed across domain services and shared supporting components. Filament resources provide the current administration UI. Tests are grouped broadly by business area.

A technical folder name does not automatically define a separate business domain. Some related service folders are grouped into one canonical documentation owner.

## Domain Ownership Principles

- One business fact must have one canonical owner.
- Cross-module workflows call the owning domain rather than duplicating its rules.
- Inventory is the owner of stock truth and stock-changing operations.
- Accounting is the owner of ledger/accounting truth.
- Payments is the owner of payment/allocation/deposit/refund transaction behavior.
- Settings/shared catalogs own reusable system configuration rather than individual business modules.
- UI resources orchestrate user interaction; they do not become the canonical home for core business rules.
- Reporting reads domain facts and should not silently become a write path unless explicitly designed and approved.

## Canonical Domains

### Identity & Authorization

**Purpose:** authentication, users, roles, permissions, actor resolution, access boundaries.

**Primary code anchors:**
- `app/Services/Identity/`
- Authentication configuration/providers
- Policies/gates/permission checks
- Filament user/admin access surfaces
- `tests/Feature/Auth/`
- Architecture/permission-related tests

**Related UI/resources:**
- Dashboard users
- Audit/access-related administration

### Settings & Shared Catalogs

**Purpose:** cross-system settings and catalogs that other domains consume.

**Primary code anchors:**
- `app/Services/Settings/`
- Settings/config models
- Shared enums/catalogs

**Related Filament resources include:**
- Currencies
- Payment Terms
- Payment Methods
- Sales Settings
- Purchase Settings
- Inventory Settings
- Units/shared definitions where implemented
- Document Templates
- Notification preferences/templates where configuration-focused

### Catalog & Pricing

**Purpose:** products, variants, units/attributes, pricing tiers, price history, price-floor behavior.

**Primary code anchors:**
- Product and product-variant models/services
- Pricing resolution services
- Related inventory UOM helpers where shared with catalog definitions

**Related Filament resources include:**
- Products
- Product Variants
- Pricing Tiers
- Price Histories
- Price Floor Overrides

**Cross-domain dependencies:**
Sales, Purchasing, Inventory, CRM, and mobile apps consume catalog/pricing facts.

### Inventory

**Purpose:** stock truth, warehouse-level availability, movements, reservations, counts, adjustments, corrections, returns, lots, conditions, replenishment inventory facts.

**Primary code anchors:**
- `app/Services/Inventory/`
- Inventory models/migrations
- `tests/Feature/Inventory/`

**Related Filament resources include:**
- Warehouses
- Stock Levels
- Stock Movements
- Inventory Operations
- Inventory Reservations
- Inventory Counts
- Inventory Lots
- Serialized Inventory Units
- Adjustments
- Transfers/condition changes/corrections
- Returns
- Inventory Alerts
- Inventory Reports
- Warehouse Replenishment Policies

**Boundary:** other domains may request inventory effects, but stock mutation must flow through Inventory-owned behavior.

### Purchasing & Suppliers

**Purpose:** supplier master data, purchase orders, supplier confirmations, procurement demand, receiving coordination, purchasing reports.

**Primary code anchors:**
- `app/Services/Purchasing/`
- `app/Services/Suppliers/`
- `app/Services/Supply/`
- `tests/Feature/Purchasing/`

**Related Filament resources include:**
- Suppliers
- Supplier Product References
- Supplier Product Supports
- Purchase Orders
- Purchase Inbounds
- Supplier Confirmations
- Purchasing Reports

**Cross-domain dependencies:**
- Inventory owns received-stock effects.
- Accounting owns supplier bills/payables/posting.
- Payments owns supplier payment transaction behavior.

### Sales & Orders

**Purpose:** customer commercial lifecycle from quotation/order through delivery/invoice/credit correction.

**Primary code anchors:**
- `app/Services/Sales/`
- `app/Services/Orders/`
- `tests/Feature/Sales/`

**Related Filament resources include:**
- Quotations
- Customer Quotation Requests
- Sales Opportunities
- Orders
- Delivery Notes
- Invoices
- Credit Notes
- Sales Reports

**Cross-domain dependencies:**
- Catalog/Pricing resolves commercial product pricing.
- Inventory owns stock effects.
- Payments owns collections/allocations.
- Accounting owns postings/receivables/tax effects.
- Logistics owns outbound execution where the flow uses fulfillment/shipment.

### Accounting

**Purpose:** chart of accounts, fiscal periods, journals, receivables, payables, supplier bills, expenses, taxes, reconciliation, and financial reporting.

**Primary code anchors:**
- `app/Services/Accounting/`
- `app/Services/Reconciliation/`
- `tests/Feature/Accounting/`

**Related Filament resources include:**
- Chart Of Accounts
- Journal Entries
- Fiscal Periods
- Accounts Receivable
- Accounts Payable
- Bills
- Expenses
- Taxes
- Financial Reports
- Receivable Write Offs
- Supplier Payments where accounting-facing

**Boundary:** financial documents and operational domains may trigger approved posting services, but they do not own ledger truth.

### Payments

**Purpose:** payment methods, transactions, allocations, customer deposits, refunds, supplier/provider settlements, and payment-side state transitions.

**Primary code anchors:**
- `app/Services/Payments/`
- Payment transaction/allocation models
- `tests/Feature/Payments/`

**Related Filament resources include:**
- Payments
- Payment Transactions
- Payment Methods
- Refunds
- Supplier Payments where payment-facing

**Cross-domain dependencies:**
Sales/Support/Purchasing/Accounting may initiate or consume payment outcomes, while Payments owns the transaction behavior.

### Logistics & Shipments

**Purpose:** outbound fulfillment, dispatch, shipments, packages, and delivery execution beyond commercial document state.

**Primary code anchors:**
- `app/Services/Logistics/`
- `app/Services/Shipments/`
- `tests/Feature/Shipments/`

**Related Filament resources include:**
- Outbound Fulfillments
- Shipments
- Shipment Attachments
- Packages
- Package Types

**Cross-domain dependencies:**
Sales/Orders provide fulfillment demand; Inventory provides/changes stock through its own services.

### CRM

**Purpose:** customers, leads, opportunities, interactions, campaigns, customer lifecycle context, and CRM-originated return requests.

**Primary code anchors:**
- `app/Services/Crm/`
- `tests/Feature/Crm/`

**Related Filament resources include:**
- Customers
- Leads
- Sales Opportunities where CRM-facing
- Interactions
- Campaigns
- CRM Reports
- Customer Return Requests

**Cross-domain dependencies:**
Catalog/Pricing, Sales, Notifications, and Support consume customer/CRM context.

### Employees

**Purpose:** employee profiles, monthly plans, tasks, visits, performance, salary calculations, GPS/visit execution, and employee-facing operational behavior.

**Primary code anchors:**
- `app/Services/Employees/`
- `tests/Feature/Employees/`
- `tests/Feature/Performance/`

**Related Filament resources include:**
- Employees
- Monthly Plans
- Tasks
- Visits
- Performance
- Salary Calculations
- Employee Reports

**Future/mobile extension:**
The active employee visit/AI API plan describes future API/mobile work. Planned API behavior is not current runtime behavior until routes/services/tests are implemented.

### Support & Maintenance

**Purpose:** customer support tickets, SLA, warranty, maintenance requests/schedules, service records, support billing/costs, and related settlement behavior.

**Primary code anchors:**
- `app/Services/Support/`
- `tests/Feature/Support/`

**Related Filament resources include:**
- Tickets
- SLA Policies
- Warranty Policies
- Maintenance Requests
- Maintenance Schedules
- Service Records
- Support Reports

**Cross-domain dependencies:**
Payments and Accounting own their respective financial truths; Inventory owns consumed/replacement stock effects when applicable.

### Notifications

**Purpose:** notification preferences, templates, delivery orchestration, and communication history.

**Primary code anchors:**
- `app/Services/Notifications/`
- `tests/Feature/Notifications/`

**Related Filament resources include:**
- Notification Templates
- Notification Preferences
- Notification Deliveries

### Reporting & Audit

**Purpose:** cross-domain read/report surfaces, exports, dashboard metrics, and audit visibility without changing the owning domain's facts.

**Primary code anchors:**
- `app/Services/Reporting/`
- Domain-specific report services
- Audit/activity-log integration
- Reporting tests across business areas

**Related Filament resources include:**
- Financial Reports
- Inventory Reports
- Purchasing Reports
- Sales Reports
- CRM Reports
- Employee Reports
- Support Reports
- Audit Logs

**Boundary:** a report may aggregate facts from several domains but does not become the owner of those facts.

## Mobile Application Documentation Owners

### Customer App

Canonical docs target: `Docs/apps/customer/`

Visual source:
- `design/customer-app-v1.pen`

The app consumes/coordinates behavior from customer identity, catalog/pricing, sales/orders, payments, support/maintenance, and notifications.

### Employee App

Canonical docs target: `Docs/apps/employee/`

Visual source:
- `design/employee-sales-app-v1.pen`

The app consumes/coordinates behavior from employee identity, plans/tasks/visits, customers/catalog, sales, GPS, notifications, and planned AI voice-note processing.

## Cross-Domain Flows That Require Dedicated Documentation

The product-level business-flow documentation must explicitly cover at least:

1. Quotation -> order/delivery -> invoice -> payment -> accounting.
2. Purchase order -> receipt -> inventory -> supplier bill/payable -> supplier payment/accounting.
3. Inventory adjustment/transfer/count/correction lifecycle.
4. Customer return -> inventory condition -> refund/credit/accounting.
5. Support ticket -> triage/SLA -> warranty/maintenance -> billing/payment/settlement.
6. Outbound fulfillment -> shipment/package -> delivery completion.
7. Employee monthly plan -> task/visit -> GPS/result -> performance/salary.
8. Employee visit -> voice note -> AI transcription/opportunity when that active plan is implemented.
9. CRM lead/opportunity/campaign -> sales handoff.
10. Notification generation/delivery around critical business events.

Each flow must link back to the domain that owns each state-changing side effect.

## Documentation Migration Rule

When a canonical domain folder is created:

- Extract current rules from code/tests first.
- Reconcile relevant ADRs.
- Use old specs/plans only to recover intent not obvious from code.
- Link important code/test entry points.
- Mark unfinished behavior clearly.
- Only then remove the superseded source documents listed in the documentation inventory.
