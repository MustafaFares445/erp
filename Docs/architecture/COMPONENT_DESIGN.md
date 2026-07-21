# Component Design

## 1. Component Overview

IERP is composed of UI flow components, Laravel backend components, database components, integration components, and shared services.

## 2. Frontend Components

Frontend specs focus on flow and UI behavior, not a locked framework.

| Component | Responsibility |
|---|---|
| Dashboard Shell | Admin navigation, layout, auth state, notifications. |
| Customer App Shell | Customer navigation, profile, product browsing, invoices, tickets. |
| Employee App Shell | Employee navigation, monthly plan, tasks, visits, GPS, voice notes. |
| Product Screens | Product and variant listing/detail/search/filter. |
| Sales Screens | Quotations, delivery notes, invoices, payments. |
| Inventory Screens | Warehouses, stocks, movements, adjustments, transfers. |
| Accounting Screens | Chart of accounts, journals, fiscal periods, tax recognition. |
| Ticket Screens | Create, assign, view, pay, update, close tickets. |
| AI Screens | Voice upload status, transcription, detected drafts, admin review. |
| Report Screens | Operational reports and export actions. |

## 3. Backend Components

| Component | Responsibility |
|---|---|
| Controllers | Route requests to services. |
| Form Requests | Validate fields and business preconditions. |
| API Resources | Shape JSON responses. |
| Domain Services | Implement business workflows. |
| Models | Relationships, scopes, casts, and basic invariants. |
| Jobs | Process slow/external work. |
| Events | Announce domain changes. |
| Listeners | Send notifications, create side effects, enqueue work. |
| Policies/Gates | Authorize actor access. |
| Audit Logger | Record sensitive actions. |

## 4. Database Components

| Database Area | Responsibility |
|---|---|
| Identity | Users, user types, devices, tokens. |
| Customer/Employee/Supplier | Business profiles and relationships. |
| Product Catalog | Categories, products, variants, attributes, prices, files. |
| Inventory | Warehouses, stocks, movements, transfers, reservations. |
| Accounting | Chart of accounts, fiscal periods, journals, posting references. |
| Sales | Quotations, delivery notes, orders, supplier confirmations. |
| Payments | Payment methods, Stripe, manual payments, allocations. |
| Support | Tickets, messages, attachments, assignments, maintenance. |
| CRM | Leads, interactions, campaigns, recipients, responses. |
| AI | Voice notes, transcriptions, keyword rules, sales drafts. |
| System | Notifications, logs, exports, audits. |

## 5. Third-Party Integration Components

| Component | Responsibility |
|---|---|
| StripePaymentService | Create payment intents/links, handle webhooks, map events to payments. |
| MailService | Send invoice PDFs, reminders, and notifications. |
| AITranscriptionService | Submit voice notes and receive transcriptions. |
| FileStorageService | Store and authorize private files. |

## 6. Shared Services

- Money calculation service.
- Tax recognition service.
- Accounting posting service.
- Inventory movement service.
- Status transition service.
- Notification service.
- Export service.
- Audit log service.

## 7. Component Responsibilities

Business rules must live in services. Controllers must not calculate tax, stock, salary, or accounting entries directly.

## 8. Component Communication

```text
Controller -> Form Request -> Service -> Models/Database -> Events -> Jobs/Listeners -> Notifications/External Integrations
```

## 9. Future Spec Kit Extraction Map

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
