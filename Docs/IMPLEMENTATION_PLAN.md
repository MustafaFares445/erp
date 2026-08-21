# Implementation Plan

## 1. Implementation Overview

This plan implements IERP in controlled phases for Claude Code/openCode. It starts with project foundation, database, and API contracts before business modules. Website and supplier portal are excluded.

## 2. Development Principles

- Read `/spec.constitution.md` before coding.
- Keep Laravel API monolith modular.
- Use database migrations before dependent services.
- Use transactions for financial and inventory workflows.
- Use tests before marking features complete.
- Do not add features outside approved scope.

## 3. Project Setup Phase

### Goal
Prepare Laravel API foundation.

### Tasks
- Configure Laravel project.
- Configure auth, API response helpers, exception handling.
- Create domain folder structure.
- Add base audit logging helper.

### Files Expected To Change
- `app/`
- `routes/api.php`
- `config/`
- `tests/`

### Dependencies
None.

### Acceptance Criteria
- Given the app is installed, when smoke tests run, then API health/auth endpoints respond correctly.

### Notes for Claude Code / openCode
Avoid feature implementation in setup beyond base infrastructure.

## 4. Database Phase

### Goal
Create the full normalized database foundation.

### Tasks
- Implement migrations in the order from ERD.
- Add foreign keys, indexes, constraints.
- Add seeders for default statuses, account types, payment methods, payment terms.

### Files Expected To Change
- `database/migrations/`
- `database/seeders/`
- `app/Models/`

### Dependencies
Project setup.

### Acceptance Criteria
- Given migrations run on a clean database, then all tables are created with constraints and seed data.

### Notes for Claude Code / openCode
Do not store product stock directly on products as source of truth.

## 5. Core Backend Foundation Phase

### Goal
Implement auth, users, customers, employees, suppliers, and shared CRUD patterns.

### Tasks
- Auth endpoints.
- User type middleware.
- Customer/employee/supplier APIs.
- Salary settings fields including `use_base_salary=false` default.

### Dependencies
Database phase.

### Acceptance Criteria
- Given valid user credentials, when user logs in, then correct token and profile are returned.

## 6. Accounting Phase

### Goal
Implement chart of accounts, fiscal periods, journals, and posting services.

### Tasks
- COA CRUD.
- Journal entry CRUD/confirmation.
- Balance validation.
- Posting service interface for invoices, payments, tax, credit notes.

### Dependencies
Core backend foundation.

### Acceptance Criteria
- Given a journal entry is confirmed, when debit does not equal credit, then confirmation fails.

## 7. Products and Inventory Phase

### Goal
Implement catalog, variants, warehouses, stock balances, movements, adjustments, transfers, reservations.

### Tasks
- Product/variant APIs.
- Warehouse APIs.
- Inventory service.
- Movement creation rules.
- Adjustment and transfer workflows.

### Dependencies
Database and core backend.

### Acceptance Criteria
- Given stock changes, when operation succeeds, then inventory_stocks and inventory_movements are both updated.

## 8. Sales Flow Phase

### Goal
Implement quotation -> delivery note -> invoice lifecycle.

### Tasks
- Quotation CRUD and customer accept/reject.
- Delivery note conversion and confirmation.
- Invoice creation from delivery note.
- Invoice PDF/email jobs.
- Invoice receipt confirmation with signature.

### Dependencies
Products, inventory, customers, employees, payment terms.

### Acceptance Criteria
- Given a delivery note is confirmed, then stock decreases and no tax is recognized.

## 9. Payments and Tax Phase

### Goal
Implement Stripe, manual payments, tax recognition, and payment accounting postings.

### Tasks
- Payment methods CRUD.
- Stripe payment creation.
- Stripe webhook handling.
- Manual payment recording with proof.
- Tax recognition service.
- Payment allocation.
- Journal posting.

### Dependencies
Accounting and invoices.

### Acceptance Criteria
- Given a partial payment, then tax is recognized proportionally.

## 10. Credit Notes Phase

### Goal
Support invoice correction/reversal.

### Tasks
- Credit note creation.
- Cancel-only draft flow.
- Cancel-and-new-invoice flow.
- Journal reversal postings.

### Dependencies
Invoices and accounting.

### Acceptance Criteria
- Given a credit note is confirmed, then the invoice is corrected without physical deletion.

## 11. Supplier Confirmation Phase

### Goal
Support manually updated supplier confirmation for pending orders.

### Tasks
- Order supplier link.
- Confirmation endpoint.
- Status update and filtering.

### Dependencies
Orders, suppliers.

### Acceptance Criteria
- Given admin confirms supplier availability, when confirmation is saved, then order status changes accordingly.

## 12. Employee Plans and AI Phase

### Goal
Implement monthly plans, tasks, visits, GPS, voice notes, performance, salary, AI sales drafts.

### Tasks
- Sales plan CRUD and copy monthly plan.
- Task assignment/status update.
- Visit check-in/out and GPS logs.
- Voice note upload.
- AI transcription job.
- Keyword detection and sales draft creation.
- Performance and salary calculation.

### Dependencies
Employees, customers, products, AI config.

### Acceptance Criteria
- Given AI transcription fails, then visit remains completed and the job failure is visible/retryable.

## 13. Tickets, Maintenance, CRM Phase

### Goal
Implement customer support, maintenance, leads, interactions, and campaigns.

### Tasks
- Ticket CRUD and assignments.
- Ticket payment link/status flow.
- Maintenance records/tasks.
- CRM leads/interactions.
- Campaigns, recipients, responses.

### Dependencies
Customers, employees, payments.

### Acceptance Criteria
- Given a paid ticket is unpaid, then it remains pending_payment with pending reason until payment succeeds.

## 14. Reporting and Notifications Phase

### Goal
Implement reports, reminders, notifications, and exports.

### Tasks
- Sales, invoice, payment, tax, inventory, employee, ticket, CRM reports.
- Notification templates.
- Email/push/database notification logs.
- Export logs.

### Dependencies
All business modules.

### Acceptance Criteria
- Given an overdue invoice, when reminders run, then customer receives a payment reminder with Stripe link.

## 15. Frontend Flow Specification Phase

### Goal
Prepare framework-neutral UI implementation guidance.

### Tasks
- Dashboard screen inventory.
- Customer app flows.
- Employee app flows.
- API mapping.
- Loading/empty/error states.

### Dependencies
API contract.

### Acceptance Criteria
- Given frontend team reads docs, then every screen has user action and API mapping.

## 16. Testing Phase

### Goal
Complete test coverage for critical workflows.

### Tasks
- Unit tests.
- Feature tests.
- Integration tests.
- Critical accounting/inventory/payment tests.

### Dependencies
Implemented modules.

### Acceptance Criteria
- All critical tests pass.

## 17. Deployment Phase

### Goal
Prepare production environment.

### Tasks
- Configure env.
- Migrate database.
- Seed defaults.
- Configure queues, storage, mail, Stripe webhook.
- Smoke test.

### Dependencies
Testing phase.

### Acceptance Criteria
- Production smoke tests pass.

## 18. Final QA Phase

### Goal
Validate business workflows with stakeholders.

### Tasks
- Run full sales lifecycle.
- Validate tax recognition.
- Validate inventory movements.
- Validate employee plan/visit/AI flow.
- Validate tickets and maintenance.

### Acceptance Criteria
- Stakeholder approves workflows.

## 19. Implementation Checklist

- [ ] Constitution reviewed.
- [ ] Database implemented.
- [ ] API contracts implemented.
- [ ] Tests added.
- [ ] Critical workflows verified.
- [ ] Deployment prepared.

## CRM Customers and Pricing Tiers Workstream

The approved dashboard-only CRM workstream extends the existing Customer,
pricing, audit, reporting, and Spatie authorization surfaces. Implementation is
governed by `specs/013-crm-customers-subscriptions/` (the directory name is a
historical Spec-Kit identifier) and its dependency-ordered `tasks.md`.

The work removes the unfinished Product Subscriptions runtime surface and uses
`/admin/pricing-tiers` as the only pricing-tier management page. It extends the
existing tier model with general, customer-specific, and product-scoped types;
adds product links; reuses existing customer-tier assignments; and routes tier
lifecycle, link, and assignment changes through transactional pricing services
with audit writes. The approved implementation baseline is a fresh database,
so obsolete subscription creation/provenance migrations are removed and no
legacy cleanup or conversion path is included.

Pricing integration preserves one resolver and implements the non-stacking
order customer-specific -> lowest eligible product-scoped result -> assigned
general -> base. Equal product-scoped results use the lowest tier identifier.
Price-floor approvals retain the winning tier as provenance.

The final work includes role-bound Filament actions, product and customer
assignment management, read-only preview, reports, audit review, English-only
feature text, migration/rollback tests, and focused Pest regression coverage.
Customer payment terms are removed from this CRM surface but remain a separate
Sales and Accounting concern. The work excludes a customer API, recurring
billing, renewal, invoice, payment, tax, and duplicate storage work.

## Employees, Monthly Plans, Visits, Performance & Salary Dashboard Workstream

The approved dashboard-only Employees workstream is governed by
`specs/015-employees-plans-visits-dashboard/` (`plan.md`, `spec.md`, and its
dependency-ordered `tasks.md`) and [ADR 0003](adr/0003-filament-employees-dashboard.md).
It is complete: employee profiles, monthly plans with weighted evaluation
factors, plan tasks with an audited status history, visit and GPS-trail
review, voice-note and AI-transcription review, performance scoring, salary
and bonus calculation, and the search/report/audit surfaces over all of it.

Implementation added a new `app/Services/Employees/` domain-service folder
(mirroring `Crm`, `Inventory`, `Orders`, `Identity`), 14 new tables, the
`EmployeePermission` catalogue, and two new fixed dashboard roles (`Employee
Manager`, `Payroll Officer`) alongside the existing System Admin and Reviewer
roles. Adding those two roles required a small fix to the existing CRM and
Inventory permission-check traits so a user holding only one of the new roles
is never silently treated as an unrestricted admin in those other modules.

AI transcription runs through the `VoiceNoteTranscriber` interface —
`OpenAiWhisperTranscriber` in production, `FakeVoiceNoteTranscriber` in every
test — so no test reaches the network and no class outside the driver
references the OpenAI client. Transcription confidence is stored only as
provider-reported, derived-from-log-probability, or unavailable, each labeled
accordingly; a failed transcription never blocks visit completion and never
affects a performance or salary calculation.

Per ADR 0003 (decision D10), the workstream is a Filament `/admin` dashboard
extension only: it adds no `/api/employee` endpoint, no employee mobile
application, and no employee-app visit or attendance capture. `composer test`
passed with the project's 100% type-coverage and 100% code-coverage
thresholds held and no new PHPStan baseline entries.

## 20. Future Spec Kit Extraction Map

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
