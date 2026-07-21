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
| Employee Management | System Admin | Manage employee records, salary options, plan assignment, visits, and app access. |
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
- Customer price resolves in order: active customer-specific tier, then the customer's general tier, then the product base price.
- Each product/variant has a minimum price (price floor). A tier discount must never drop the final price below it; the sale is blocked and can proceed only with explicit System Admin approval, which is logged.

## 10. Out of Scope

- Active website implementation.
- Supplier-facing portal.
- Filament dashboard implementation.
- Customer credit limits.
- Microservices, CQRS, event sourcing, or Kubernetes-first architecture.
- Unapproved payment gateways beyond Stripe.
- AI decisions without admin review.

## 11. Open Questions

- Which relational database engine is preferred: MySQL or PostgreSQL?
- Which AI provider will be used for transcription?
- What are the exact invoice PDF branding requirements?
- What currencies and tax rates should be seeded first?
- Should manual payments require approval before posting to accounting?
- What file size limits should apply to attachments and voice notes?

## 12. Future Spec Kit Extraction Map

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
