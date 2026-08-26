# Product Requirements Document

## 1. Project Overview

IERP is a custom enterprise resource planning system for a company that needs one integrated platform for accounting, sales, inventory, employees, customer service, maintenance, CRM, and reporting. It is not an open-source ERP implementation. The system will be built as a Laravel API consumed by dashboard, customer app, and employee app interfaces.

The confirmed user types are System Admin, Customer, and Employee. The current implementation skips website implementation and supplier portal implementation.

## 2. Project Goals

- Centralize accounting, invoices, credit notes, payments, and full chart of accounts.
- Implement a controlled sales lifecycle: Quotation -> Delivery Note -> Invoice -> Payment.
- Track multi-warehouse inventory at product variant and warehouse level.
- Let customers browse products, request quotations/orders, pay invoices, and submit tickets.
- Let employees view monthly plans, execute tasks, record visits, send GPS logs, and create quotations/delivery notes.
- Support AI voice-note transcription and keyword detection as an isolated AI integration module.
- Provide traceability through audit logs and reports.

## 3. Target Users

| User Type | Description | Main Access Surface |
|---|---|---|
| System Admin | Manages dashboard, users, financials, inventory, sales, support, CRM, and reports. | Dashboard |
| Customer | Uses app to browse products, request quotations/orders, pay invoices, and create tickets. | Customer app |
| Employee | Uses app to complete tasks, visits, quotations, delivery notes, GPS, and voice notes. | Employee app |

## 4. Business Context

The company sells products/services on payment terms. Tax must be recognized only when money is collected. Some customer orders may stay pending after payment until the admin manually confirms availability with suppliers. Employee performance is tied to monthly plans, tasks, visits, schedule commitment, work-time commitment, and possible sales opportunity drafts.

## 5. Core Features

| Feature | Actors | Purpose |
|---|---|---|
| Authentication and User Access | System Admin, Customer, Employee | Authenticate users and separate API surfaces by user type. |
| Customer Management | System Admin | Create and manage customer profiles used by sales, invoices, tickets, and CRM. |
| CRM Customers and Pricing Tiers | System Admin, CRM Manager, Pricing Manager, Reviewer | Manage dashboard-only customers and general, customer-specific, or product-scoped pricing tiers, including eligibility, price previews, reporting, and audit review. |
| Employee Management | System Admin | Manage employee records, salary options, plan assignment, visits, and app access. |
| Employees, Monthly Plans, Visits, Performance & Salary Dashboard | System Admin, Employee Manager, Payroll Officer, Reviewer | Dashboard-only management of employee profiles, monthly plans and tasks, visit and voice-note review, AI transcription review, and performance/salary calculation. |
| Supplier Management | System Admin | Manage suppliers and manually update supplier confirmations for pending orders. |
| Products and Variants | System Admin, Customer, Employee | Manage products, variants, attributes, prices, and files. |
| Multi-Warehouse Inventory | System Admin, Employee | Track stock by product variant and warehouse, with movements, transfers, reservations, and adjustments. |
| Chart of Accounts | System Admin | Maintain account hierarchy, account types, and posting targets. |
| Journal Entries | System Admin | Record accounting postings produced manually or by invoices, payments, credit notes, and tax recognition. |
| Payment Terms | System Admin | Define due date rules, grace periods, and invoice defaults. |
| Quotation Flow | System Admin, Employee, Customer | Create quotations and allow customer accept/reject actions. |
| Delivery Note Flow | System Admin, Employee | Convert accepted quotations to delivery notes that affect inventory without recognizing tax. |
| Invoice Flow | System Admin, Customer, Employee | Create invoices, generate PDFs, send by email, and confirm invoice receipt with signature. |
| Credit Notes | System Admin | Reverse or correct invoices partially or fully without destructive deletion. |
| Stripe Payments | Customer, Stripe, System Admin | Collect online invoice and ticket payments through Stripe with idempotent webhooks. |
| Manual Payments | System Admin | Record cash, bank transfer, cheque, and custom payment methods from dashboard. |
| Tax Recognition | System | Recognize tax only when payment is collected, including proportional recognition for partial payment. |
| Employee Plans and Tasks | System Admin, Employee | Create monthly sales plans, assign tasks, copy plans, and track completion. |
| Visits and GPS Tracking | Employee, System Admin | Record visit check-in/out, GPS logs, visit duration, notes, and attachments. |
| Salary and Performance | System Admin, Employee | Calculate performance from configurable factors and optional base salary. |
| AI Voice Notes | Employee, System Admin, AI Provider | Transcribe employee voice notes, detect keywords, and create sales opportunity drafts. |
| Tickets | Customer, System Admin, Employee | Create, assign, pay for, and resolve support tickets. |
| Maintenance | Customer, System Admin, Employee | Track maintenance records and tasks for sold products/equipment. |
| CRM and Marketing | System Admin | Track leads, interactions, campaigns, recipients, and responses. |
| Reports and Notifications | System Admin, Customer, Employee | Provide operational reports and send reminders, invoice notices, and task notifications. |
| Audit Logs | System | Record sensitive business and financial changes. |

## 6. User Flows

### 6.1 Admin Sales Flow

1. Admin or employee creates quotation.
2. Customer accepts or rejects quotation.
3. Accepted quotation is converted to delivery note.
4. Delivery note decreases stock by product variant and warehouse.
5. Delivery note is converted to invoice.
6. Invoice PDF is generated and can be emailed.
7. Customer pays online through Stripe or admin records manual payment.
8. Tax is recognized only for the collected amount.
9. Journal entries are posted.

### 6.2 Customer Flow

1. Customer logs in.
2. Customer browses products and variants.
3. Customer requests quotation or direct order.
4. Customer tracks quotation/order status.
5. Customer receives invoice and payment reminder.
6. Customer pays through Stripe.
7. Customer creates support or maintenance ticket when needed.

### 6.3 Employee Flow

1. Employee logs in.
2. Employee views monthly sales plan and tasks.
3. Employee starts visit and sends GPS.
4. Employee updates task/visit result.
5. Employee may create quotation or delivery note during visit.
6. Employee records voice note.
7. AI transcription job creates text and detects sales opportunities.
8. Admin reviews generated sales draft.

## 7. Functional Requirements

| ID | Requirement |
|---|---|
| FR-001 | The system must authenticate System Admin, Customer, and Employee users. |
| FR-002 | Admin must manage customers, employees, suppliers, products, variants, warehouses, and settings. |
| FR-003 | Admin must define payment terms and assign default terms to invoices. |
| FR-004 | The system must calculate invoice due dates from payment terms. |
| FR-005 | The system must generate invoice PDFs and support email delivery. |
| FR-006 | The system must support invoice export to CSV/Excel. |
| FR-007 | The system must support credit notes linked to invoices or standalone when required. |
| FR-008 | The system must implement Quotation -> Delivery Note -> Invoice -> Payment. |
| FR-009 | Delivery notes must affect stock but must not recognize tax. |
| FR-010 | Tax must be recognized only when payment is collected. |
| FR-011 | Partial payments must recognize tax proportionally. |
| FR-012 | Stripe must support online payments. |
| FR-013 | Manual dashboard payments must support admin-defined payment methods. |
| FR-014 | Products must support variants and variant attributes. |
| FR-015 | Inventory must support multiple warehouses and stock movement history. |
| FR-016 | Customers must be able to request quotations/orders and pay invoices. |
| FR-017 | Customers must be able to open tickets and maintenance requests. |
| FR-018 | Employees must view monthly plans, tasks, visits, and performance. |
| FR-019 | Employees must record GPS logs during visits when required. |
| FR-020 | Employees must upload voice notes for AI transcription and keyword detection. |
| FR-021 | The AI module must create reviewable sales opportunity drafts. |
| FR-022 | Admin must manage CRM leads, interactions, campaigns, and responses. |
| FR-023 | Reports must cover sales, invoices, payments, tax, inventory, employees, tickets, and CRM. |
| FR-024 | Sensitive actions must create audit logs. |

## 8. Non-Functional Requirements

| Area | Requirement |
|---|---|
| Usability | Interfaces must be clear, support Arabic content, and show understandable errors. |
| Performance | Common operations such as invoice creation and task update should respond in under 5 seconds under normal conditions. |
| Security | Protected APIs require authentication and user-type access control. Sensitive data must be protected in transit and storage where applicable. |
| Reliability | Financial and inventory records require transactions and non-destructive corrections. |
| Scalability | The modular monolith must handle growth in users, transactions, products, and warehouses. |
| Compatibility | Customer and employee flows must support modern mobile apps and web dashboard usage. |

## 9. Business Rules

- The project must not depend on an open-source ERP package.
- Website implementation is out of scope now.
- Supplier portal is out of scope now.
- Customer credit limits are not required.
- Inventory source of truth is `product_variant_id + warehouse_id`.
- Every stock-changing operation must create an inventory movement.
- Tax is not recognized at invoice creation.
- Tax is recognized only when payment is collected.
- Manual and Stripe payments must use the same tax recognition logic.
- Confirmed financial documents must not be physically deleted.
- AI failures must not block visit completion.
- Employee `use_base_salary` defaults to false.
- Supplier confirmations are manually updated by admin.
- Customer price resolves in order: active customer-specific tier, then the lowest eligible product-scoped tier result, then the customer's active general tier, then the product base price. Discounts never stack.
- Each product/variant has a minimum price (price floor). A tier discount must never drop the final price below it; the sale is blocked and can proceed only with explicit System Admin approval, which is logged.

## 10. CRM Customers and Pricing Tiers

The existing `/admin` dashboard is approved for CRM customer and pricing-tier
management by [ADR 0002](adr/0002-filament-crm-dashboard.md). The fixed
dashboard roles are System Admin, CRM Manager, Pricing Manager, and Reviewer.
`/admin/pricing-tiers` is the only pricing management surface and supports
general, customer-specific, and product-scoped tiers. Product-scoped tiers link
products and active customer profiles through existing pricing-tier
infrastructure; they do not introduce a subscription resource or recurring
plan.

Resolution uses customer-specific tier -> lowest eligible product-scoped tier
result -> active assigned general tier -> base price and never stacks
discounts. Equal product-scoped results use the lowest pricing-tier identifier
as the deterministic tie-breaker. Pricing-tier decisions can be reported,
audited, previewed, and restored through the dashboard.

This phase is English-only. Customer payment terms are not displayed, edited,
or validated by this CRM module; the separate Sales and Accounting Payment
Terms feature remains in scope elsewhere. The CRM pricing-tier scope excludes
customer apps, public APIs, recurring billing, renewals, invoices, payments,
and tax behavior.

## 11. Out of Scope

- Active website implementation.
- Supplier-facing portal.
- Filament dashboard implementation, except the Inventory dashboard approved by [ADR 0001](adr/0001-filament-inventory-dashboard-for-inventory.md), the narrow CRM customer and pricing-tier dashboard approved by [ADR 0002](adr/0002-filament-crm-dashboard.md), the Employees, Monthly Plans, Visits, Performance & Salary dashboard approved by [ADR 0003](adr/0003-filament-employees-dashboard.md), the Support and Maintenance dashboard approved by [ADR 0004](adr/0004-filament-support-maintenance-dashboard.md), the Purchasing dashboard approved by [ADR 0006](adr/0006-filament-purchasing-dashboard.md), the Accounting foundation dashboard approved by [ADR 0007](adr/0007-filament-accounting-dashboard.md), the Sales lifecycle, Payments, and Credit Notes dashboard approved by [ADR 0008](adr/0008-filament-sales-payments-dashboard.md), and the Accounting payables dashboard approved by [ADR 0011](adr/0011-accounting-payables-expenses-bills.md). Any other module remains out of scope pending a separate ADR.
- Customer credit limits.
- Microservices, CQRS, event sourcing, or Kubernetes-first architecture.
- Unapproved payment gateways beyond Stripe.
- AI decisions without admin review.
- `/api/employee` endpoints and any other employee-facing API functionality, the employee mobile application, employee-app visit capture, employee-app attendance capture, and mobile authentication flows. These stay out of scope per [ADR 0003](adr/0003-filament-employees-dashboard.md); building any of them later requires its own specification and either a separate ADR or an explicit amendment to ADR 0003.
- Purchase requisitions and RFQs; supplier bills, accounts payable, payments to suppliers, journal entries, and purchase-tax recognition inside Purchasing; landed-cost allocation; supplier returns and debit notes; currency conversion or revaluation; moving-average or FIFO cost recalculation; supplier performance scoring; automatic reorder-point purchasing; and outbound email or EDI transmission of a purchase order. These stay out of scope per [ADR 0006](adr/0006-filament-purchasing-dashboard.md), which authorises purchasing as a dashboard-only module that creates no accounting artefact and posts all received stock through the existing Inventory services. [ADR 0011](adr/0011-accounting-payables-expenses-bills.md) is the narrow Accounting-side exception: it may record bills, payables, supplier payments, and purchase-tax recognition while reading purchase-order references; it does not add any of those behaviours to Purchasing. The supplier-facing portal listed above is not relaxed by either approval.
- Accounts-receivable and accounts-payable subledgers; supplier bills, expenses, refunds, and tax definitions; financial reports of any kind, including a trial balance, profit and loss, and balance sheet (**exception approved for read-only ledger reporting** — see [ADR 0009](adr/0009-accounting-financial-reports.md), and payables administration — see [ADR 0011](adr/0011-accounting-payables-expenses-bills.md), which authorises expenses, supplier bills, supplier payments, the computed Accounts Payable surface, advisory purchase-order matching, and exactly four new named posting callers); multi-currency, currency conversion, and revaluation; cost accounting, inventory valuation, and cost-of-goods-sold posting; budgets and forecasting; bank accounts and bank reconciliation; a year-end close rolling income and expense into retained earnings; opening-balance import from an external accounting system; recurring or scheduled journal entries; and journal-entry approval workflow beyond `draft -> posted`. These stay out of scope per [ADR 0007](adr/0007-filament-accounting-dashboard.md), except for the narrow Accounting-side exceptions explicitly approved by ADR 0009 and ADR 0011. The posting interface exists; connecting any document to it requires that document's own specification and ADR.
- Any API surface for the sales lifecycle; the customer application or a customer-facing quotation accept/reject link — a customer's answer is recorded by an admin or employee in the dashboard instead; Stripe, its webhook, or any online payment channel — the manual channel is the only one built; accounts-receivable and accounts-payable subledger pages, which stay placeholders even though this module creates the receivable balances they would report on; a `tax_definitions` rate catalogue, replaced by a single configurable default rate; financial reports of any kind; cost-of-goods-sold posting or inventory valuation, so a delivery still posts nothing to the ledger; multi-currency; recurring billing; dunning beyond a derived overdue status; customer credit limits; sales commission calculation; goods-return inventory movements from a credit note; and wiring ticket payments to the Payments module. These stay out of scope per [ADR 0008](adr/0008-filament-sales-payments-dashboard.md), which authorises exactly three ledger-posting events — invoice issuance, payment collection with proportional tax recognition, and credit-note confirmation — as a narrow, named amendment to ADR 0007's no-automatic-posting rule. No other document gains a posting path, and ADR 0006's prohibition on any accounts-payable or general-ledger behaviour in Purchasing is unaffected.

## 12. Open Questions

- Which relational database engine is preferred: MySQL or PostgreSQL?
- Which AI provider will be used for transcription?
- What are the exact invoice PDF branding requirements?
- What currencies and tax rates should be seeded first?
- Should manual payments require approval before posting to accounting?
- What file size limits should apply to attachments and voice notes?

## Employees, Monthly Plans, Visits, Performance & Salary Dashboard

The existing `/admin` dashboard is approved for the Employees module by [ADR 0003](adr/0003-filament-employees-dashboard.md). The fixed dashboard roles are System Admin, Employee Manager (new), Payroll Officer (new), and Reviewer, each checked at both page-open and action-execution time, everywhere an action can be triggered.

The module delivers eight user stories at a product level:

1. **Dashboard roles and permissions** — every employee, plan, task, visit, AI-review, performance, salary, and report action is governed by one of the four fixed roles.
2. **Employee profiles** — an Employee Manager creates and maintains employee profiles (job title, contact data, salary option, app-access state) with a guaranteed-unique employee code and a searchable archive when an employee leaves.
3. **Monthly plans** — an Employee Manager builds a plan per employee per month with four weighted evaluation factors (task, visit, schedule, and work-time) that must sum to exactly 100, and can copy a plan to another employee or month as an independent record with no execution history carried over.
4. **Tasks and completion tracking** — tasks live inside a plan with mandatory start/end dates, an optional customer link, and an append-only, audited status history.
5. **Visits and location review** — an authorized reviewer sees planned and executed visits with their GPS trail and attachments, and can add a review note without altering a field-recorded visit's data.
6. **Voice notes and AI review** — a reviewer sees each visit's voice notes, transcription text, a labeled confidence indicator, and any AI-drafted sales opportunity; no AI output takes effect without an explicit human decision.
7. **Performance and salary calculation** — a Payroll Officer previews each plan's weighted performance score and the resulting salary, including any admin-approved bonus, before confirming it.
8. **Search, reports, and audit** — any authorized user searches and filters employees, plans, tasks, and visits, and reviews plan-completion, overdue-task, unexecuted-visit, and performance/salary summaries by employee or month.

This is a dashboard-only extension, not a new API surface. Per ADR 0003 (decision D10), it does not add `/api/employee` endpoints, an employee mobile application, employee-app visit or attendance capture, or mobile authentication flows. It also adds no attendance/shift/working-hours module — schedule and work-time performance factors are derived only from task due dates and visit check-in/check-out timestamps this feature already owns — and no payroll disbursement, accounting postings, or quotation/delivery creation during a visit. Any of those requires its own specification and either a separate ADR or an explicit amendment to ADR 0003.

## 13. Future Spec Kit Extraction Map

| Future Spec | Scope |
|---|---|
| 001-project-foundation | Project rules, actors, glossary, non-functional requirements |
| 002-database-foundation | Base schema, migrations, seed data, data integrity |
| 003-auth-users-access | Authentication and user type access |
| 004-products-variants-warehouses-inventory | Catalog, variants, warehouses, stock movements |
| 005-chart-of-accounts-and-journals | COA, fiscal periods, journals, posting rules |
| 006-sales-flow-quotation-delivery-invoice | Quotation, delivery note, invoice lifecycle |
| 007-payments-stripe-manual-tax-recognition | Stripe, manual payments, tax recognition |
| 008-credit-notes | Credit note workflows and accounting reversal |
| 009-customer-app-flows | Product browsing, quotations, orders, invoices, tickets |
| 010-employee-app-plans-visits-ai | Plans, visits, GPS, voice notes, AI sales drafts |
| 011-tickets-maintenance | Support tickets and maintenance records |
| 012-crm-marketing | Leads, interactions, campaigns, responses |
| 013-reporting-notifications | Reports, alerts, reminders, audit visibility |
