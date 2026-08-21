# Testing Strategy

## 1. Testing Overview

Testing must protect financial correctness, inventory correctness, authorization, status transitions, and AI failure isolation. Laravel tests should cover services, APIs, integrations, jobs, and edge cases.

## 2. Unit Testing

Unit test domain services for calculations and rules: tax recognition, inventory movement, accounting posting, salary scoring, and status transitions.

## 3. Feature Testing

Feature test every API endpoint with valid input, validation errors, unauthorized access, and status conflicts.

## 4. Integration Testing

Integration tests cover Stripe webhook processing, file storage, email jobs, queue jobs, and AI transcription mocks.

## 5. End-to-End Testing

E2E scenarios should simulate the full quotation -> delivery note -> invoice -> payment lifecycle and employee visit -> AI draft lifecycle.

## 6. API Testing

All API responses must follow the standard envelope and pagination format.

## 7. Accounting Testing

- Journal entries balance.
- Invoice creation does not recognize tax.
- Payments create tax recognition entries.
- Credit notes reverse/correct without deleting invoices.

## 8. Inventory Testing

- Delivery notes decrease stock by product variant and warehouse.
- Quotations do not affect stock.
- Adjustments and transfers create movements.
- Insufficient stock blocks confirmation.

## 9. Payment Testing

- Stripe webhook is idempotent.
- Manual payment methods are admin-defined.
- Manual and Stripe payments use same tax logic.
- Partial payments recognize proportional tax.

## 10. AI Integration Testing

- Voice note upload saves file.
- Transcription job stores text.
- Keyword match creates sales draft.
- AI failure does not block visit completion.

## 11. Security Testing

- Admin cannot be replaced by customer or employee token.
- Customer cannot access another customer's invoices/tickets.
- Employee cannot access another employee's plans unless allowed.
- Stripe webhook rejects invalid signatures.

## 12. QA Checklist

- [ ] User type access works.
- [ ] All important lists are paginated.
- [ ] Search placeholders describe searchable fields.
- [ ] Arabic content displays correctly.
- [ ] File downloads require authorization.
- [ ] Audit logs are created for sensitive actions.

## 13. Test Data Requirements

- Admin, customer, and employee users.
- Products with variants.
- Two warehouses.
- Stock balances.
- Payment terms.
- Chart of accounts.
- Quotation, delivery note, invoice, payment, and credit note samples.
- Employee monthly plan with tasks and visits.
- Ticket and maintenance samples.

## 14. Acceptance Testing

## Feature: Authentication and User Access

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Customer Management

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Employee Management

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Supplier Management

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Products and Variants

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Multi-Warehouse Inventory

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Chart of Accounts

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Journal Entries

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Payment Terms

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Quotation Flow

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Delivery Note Flow

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Invoice Flow

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Credit Notes

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Stripe Payments

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Manual Payments

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Tax Recognition

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Employee Plans and Tasks

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Visits and GPS Tracking

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Salary and Performance

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: AI Voice Notes

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Tickets

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Maintenance

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: CRM and Marketing

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Reports and Notifications

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.

## Feature: Audit Logs

### Test Cases
- [ ] Test valid case.
- [ ] Test validation errors.
- [ ] Test unauthorized access.
- [ ] Test status transitions where applicable.
- [ ] Test audit logging where applicable.
- [ ] Test edge cases.


## 15. Critical Test Rules

- [ ] Tax is not recognized at invoice creation.
- [ ] Tax is recognized after payment.
- [ ] Partial payment tax recognition is proportional.
- [ ] Stripe and manual payments use the same tax recognition service.
- [ ] Delivery note decreases stock.
- [ ] Quotation does not decrease stock.
- [ ] Stock is tracked by product variant and warehouse.
- [ ] Manual payment methods are admin-defined.
- [ ] AI failure does not block visit completion.
- [ ] Credit note does not physically delete invoice.
- [ ] Confirmed financial documents are auditable.
- [ ] Pricing uses customer-specific tier, the lowest eligible product-scoped tier result, assigned general tier, then base price without stacking.
- [ ] Equal product-scoped results use the lowest pricing-tier identifier as the deterministic tie-breaker.
- [ ] Pricing-tier product links and customer assignments are unique, transactional, and audit logged.
- [ ] Fixed CRM roles enforce the same boundary for pages, record actions, relationship actions, and bulk actions.
- [ ] A below-floor tier candidate requires a System Admin approval with reason and pricing-tier provenance.
- [ ] `/admin/pricing-tiers` is the only pricing management route and no Product Subscriptions route or navigation item is registered.
- [ ] CRM customer forms do not display, accept, or validate payment terms.
- [ ] Feature-specific dashboard text is English-only in this phase.
- [ ] Fresh migrations create no legacy subscription tables, provenance columns, routes, or runtime symbols.
- [ ] AI transcription confidence is never stored or displayed as `0.00%` when it is actually unavailable, and a derived confidence value is never labeled provider-reported.
- [ ] A failed voice-note transcription never blocks visit completion and never changes a performance score or salary calculation.
- [ ] A field-recorded visit is never editable by a non-admin user, while an authorized reviewer can always add or update its single review note.
- [ ] Copying a monthly plan to another employee or month never copies execution history (task status, visit records, performance scores, salary calculations, bonus decisions, or audit history).
- [ ] An admin holding only the `Employee Manager` or `Payroll Officer` role gains no additional CRM or Inventory module access.
- [ ] No AI-drafted sales opportunity or bonus suggestion is approved without a recorded human decision (deciding user and timestamp).

## 16. Open Questions

- Confirm preferred testing database engine.
- Confirm whether browser E2E tests are required now or later.

## Employees, Monthly Plans, Visits, Performance & Salary Dashboard Testing

Pest feature tests for this module live under `tests/Feature/Employees/`,
organized per resource/behavior — for example `SalesPlanLifecycleTest`,
`SalesPlanDuplicationTest`, `PlanTaskCompletionTest`, `VisitFieldLockTest`,
`VoiceNoteConfidenceTest`, `VoiceNoteTranscriptionIsolationTest`,
`SalaryRecalculationServiceTest`, `CrossModulePermissionLeakTest`, and
`DashboardFixedRoleMatrixTest`. Pure, deterministic logic is unit-tested
directly under `tests/Unit/` without touching the database: enum transition
tests for every status vocabulary in `tests/Unit/Enums/` (for example
`SalesPlanStatusTest`, `PlanTaskStatusTest`, `VisitStatusTest`,
`VoiceNoteStatusTest`), covering every allowed and rejected transition
including self-transitions, plus pure scoring-service tests
(`PerformanceScoringServiceTest`, `SalaryCalculationServiceTest`) that cover
the zero-denominator cases and the worked schedule-adherence example.

No test reaches the network. `phpunit.xml` forces
`EMPLOYEES_TRANSCRIBE_DRIVER=fake`, so every test run — including a
deliberately induced transcription failure — exercises
`FakeVoiceNoteTranscriber` instead of the OpenAI Whisper API.

`tests/Unit/ArchTest.php` extends the project's existing architecture rules
with Employees-specific bans: no class outside
`App\Services\Employees\OpenAiWhisperTranscriber` may reference the OpenAI
client, and no Filament resource may write a performance or salary row
directly — every such write must go through the domain services in
`app/Services/Employees/`.

## 17. Future Spec Kit Extraction Map

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
