# System Architecture

## 1. Architecture Overview

IERP uses a Laravel API modular monolith. The system exposes APIs to dashboard, customer app, and employee app clients. It uses a relational database for ERP data, private storage for documents and voice notes, queues for long-running work, and external integrations for Stripe, email, and AI transcription.

## 2. Recommended Architecture

```text
Clients -> Laravel API -> Domain Services -> Database / Queue / Storage / Integrations
```

The recommended approach is a simple clean monolith because the project has many business modules but does not require independent deployment per module.

## 3. Application Layers

| Layer | Responsibility |
|---|---|
| API Controllers | Receive requests and return resources. Keep thin. |
| Form Requests | Validate input and user intent. |
| API Resources | Format output for clients. |
| Domain Services | Execute business rules and transactions. |
| Models | Represent database entities and relationships. |
| Jobs | Process PDFs, emails, Stripe webhooks, reports, notifications, and AI. |
| Events/Listeners | Trigger side effects after important domain actions. |
| Database | Persist business, financial, inventory, and audit records. |
| Storage | Store invoice PDFs, attachments, payment proofs, and voice notes. |

## 4. Request Lifecycle

1. Client sends authenticated API request.
2. Middleware validates authentication and user type.
3. Form Request validates input.
4. Controller calls domain service.
5. Service starts transaction if needed.
6. Models persist data and enforce relationships.
7. Events/jobs are dispatched after commit.
8. API Resource returns consistent JSON.

## 5. Module Boundaries

Modules should be grouped under domains:

```text
Identity, Customers, Employees, Suppliers, Products, Inventory, Accounting, Sales,
Payments, Tickets, Maintenance, CRM, Notifications, Reports, AI, Audit
```

Each domain owns its services, actions, policies, and important model logic.

### CRM Dashboard Pricing Boundary

ADR 0002 permits the existing Filament `/admin` panel as a narrow CRM
exception. Customers and pricing tiers reuse the canonical models, policies,
audit log, reports, and price resolver. `/admin/pricing-tiers` is the only
pricing management surface and supports general, customer-specific, and
product-scoped tier types. Product and customer links are managed through the
pricing domain service; no standalone Product Subscription runtime module or
API is part of the architecture.

The CRM surface is English-only in this phase. Payment terms remain a separate
Sales and Accounting domain and are not CRM form input. Pricing decisions are
non-stacking and retain pricing-tier provenance when a price-floor approval is
required.

## 6. External Integrations

| Integration | Purpose | Required |
|---|---|---|
| Stripe | Online invoice and ticket payments | Yes |
| Mail provider | Invoice sending, reminders, notifications | Yes |
| AI transcription provider | Voice note transcription and keyword detection | Required module, provider TBD |
| File storage | PDFs, attachments, payment proofs, voice notes | Yes |

## 7. Storage Strategy

Use private storage for sensitive files. Public URLs should be signed/temporary when needed.

| File Type | Storage Visibility | Notes |
|---|---|---|
| Invoice PDFs | Private | Download through authorized API. |
| Payment proofs | Private | Linked to manual payments. |
| Ticket attachments | Private | Scoped to ticket access. |
| Visit attachments | Private | Scoped to employee/admin access. |
| Voice notes | Private | AI jobs read from secure storage. |

## 8. Queue and Background Jobs

Queue candidates:

- Invoice PDF generation
- Invoice email sending
- Stripe webhook processing
- Tax recognition posting
- Journal posting
- Export generation
- Notification sending
- AI transcription
- AI keyword detection
- Report generation

## 9. Cache Strategy

Cache stable lookup data only:

- Payment methods
- Payment terms
- Tax settings
- Product categories
- Variant attributes
- Notification templates

Do not cache financial balances or stock quantities unless invalidation is strictly controlled.

## 10. Deployment Overview

Recommended simple deployment:

- One Laravel API application server.
- One relational database.
- One queue worker process.
- Private file storage.
- Scheduled tasks via Laravel scheduler.
- HTTPS enforced.

## 11. Future Spec Kit Extraction Map

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
