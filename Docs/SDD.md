# Software Design Document

## 1. System Overview

IERP will be implemented as a Laravel API clean monolith with modular domain boundaries. Dashboard, customer app, and employee app consume REST APIs. The backend owns business rules for accounting, inventory, payments, tax recognition, salary/performance calculation, status transitions, and audit logging.

## 2. Technical Goals

- Keep implementation practical and maintainable.
- Use Laravel Form Requests, API Resources, services/actions, jobs, events, policies/gates, and transactions.
- Use normalized relational tables for ERP data.
- Make long operations asynchronous.
- Keep AI integration isolated and replaceable.

## 3. Architecture Style

Recommended style: modular Laravel API monolith.

Avoid microservices, event sourcing, CQRS, and unnecessary repository layers unless later justified.

## 4. Main Modules

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

## 5. Feature Design


### Authentication and User Access

#### Description
Authenticate users and separate API surfaces by user type.

#### Actors
System Admin, Customer, Employee

#### User Story
As a System Admin, I need authenticate users and separate API surfaces by user type. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for authentication and user access, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Customer Management

#### Description
Create and manage customer profiles used by sales, invoices, tickets, and CRM.

#### Actors
System Admin

#### User Story
As a System Admin, I need create and manage customer profiles used by sales, invoices, tickets, and CRM. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for customer management, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Employee Management

#### Description
Manage employee records, salary options, plan assignment, visits, and app access.

#### Actors
System Admin

#### User Story
As a System Admin, I need manage employee records, salary options, plan assignment, visits, and app access. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for employee management, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Supplier Management

#### Description
Manage suppliers and manually update supplier confirmations for pending orders.

#### Actors
System Admin

#### User Story
As a System Admin, I need manage suppliers and manually update supplier confirmations for pending orders. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for supplier management, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Products and Variants

#### Description
Manage products, variants, attributes, prices, and files.

#### Actors
System Admin, Customer, Employee

#### User Story
As a System Admin, I need manage products, variants, attributes, prices, and files. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for products and variants, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.
- Customer price resolves in order: active customer-specific tier, then the customer's general tier, then the product/variant base price.
- The final price after a tier discount must not fall below the variant minimum price (floor); if it would, the sale is blocked and requires explicit System Admin approval, which is recorded.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Multi-Warehouse Inventory

#### Description
Track stock by product variant and warehouse, with movements, transfers, reservations, and adjustments.

#### Actors
System Admin, Employee

#### User Story
As a System Admin, I need track stock by product variant and warehouse, with movements, transfers, reservations, and adjustments. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for multi-warehouse inventory, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Chart of Accounts

#### Description
Maintain account hierarchy, account types, and posting targets.

#### Actors
System Admin

#### User Story
As a System Admin, I need maintain account hierarchy, account types, and posting targets. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for chart of accounts, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Journal Entries

#### Description
Record accounting postings produced manually or by invoices, payments, credit notes, and tax recognition.

#### Actors
System Admin

#### User Story
As a System Admin, I need record accounting postings produced manually or by invoices, payments, credit notes, and tax recognition. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for journal entries, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Payment Terms

#### Description
Define due date rules, grace periods, and invoice defaults.

#### Actors
System Admin

#### User Story
As a System Admin, I need define due date rules, grace periods, and invoice defaults. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for payment terms, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Quotation Flow

#### Description
Create quotations and allow customer accept/reject actions.

#### Actors
System Admin, Employee, Customer

#### User Story
As a System Admin, I need create quotations and allow customer accept/reject actions. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for quotation flow, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Delivery Note Flow

#### Description
Convert accepted quotations to delivery notes that affect inventory without recognizing tax.

#### Actors
System Admin, Employee

#### User Story
As a System Admin, I need convert accepted quotations to delivery notes that affect inventory without recognizing tax. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for delivery note flow, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Invoice Flow

#### Description
Create invoices, generate PDFs, send by email, and confirm invoice receipt with signature.

#### Actors
System Admin, Customer, Employee

#### User Story
As a System Admin, I need create invoices, generate PDFs, send by email, and confirm invoice receipt with signature. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for invoice flow, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Credit Notes

#### Description
Reverse or correct invoices partially or fully without destructive deletion.

#### Actors
System Admin

#### User Story
As a System Admin, I need reverse or correct invoices partially or fully without destructive deletion. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for credit notes, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Stripe Payments

#### Description
Collect online invoice and ticket payments through Stripe with idempotent webhooks.

#### Actors
Customer, Stripe, System Admin

#### User Story
As a Customer, I need collect online invoice and ticket payments through Stripe with idempotent webhooks. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for stripe payments, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Manual Payments

#### Description
Record cash, bank transfer, cheque, and custom payment methods from dashboard.

#### Actors
System Admin

#### User Story
As a System Admin, I need record cash, bank transfer, cheque, and custom payment methods from dashboard. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for manual payments, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Tax Recognition

#### Description
Recognize tax only when payment is collected, including proportional recognition for partial payment.

#### Actors
System

#### User Story
As a System, I need recognize tax only when payment is collected, including proportional recognition for partial payment. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for tax recognition, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Employee Plans and Tasks

#### Description
Create monthly sales plans, assign tasks, copy plans, and track completion.

#### Actors
System Admin, Employee

#### User Story
As a System Admin, I need create monthly sales plans, assign tasks, copy plans, and track completion. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for employee plans and tasks, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Visits and GPS Tracking

#### Description
Record visit check-in/out, GPS logs, visit duration, notes, and attachments.

#### Actors
Employee, System Admin

#### User Story
As a Employee, I need record visit check-in/out, GPS logs, visit duration, notes, and attachments. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for visits and gps tracking, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Salary and Performance

#### Description
Calculate performance from configurable factors and optional base salary.

#### Actors
System Admin, Employee

#### User Story
As a System Admin, I need calculate performance from configurable factors and optional base salary. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for salary and performance, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### AI Voice Notes

#### Description
Transcribe employee voice notes, detect keywords, and create sales opportunity drafts.

#### Actors
Employee, System Admin, AI Provider

#### User Story
As a Employee, I need transcribe employee voice notes, detect keywords, and create sales opportunity drafts. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for ai voice notes, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Tickets

#### Description
Create, assign, pay for, and resolve support tickets.

#### Actors
Customer, System Admin, Employee

#### User Story
As a Customer, I need create, assign, pay for, and resolve support tickets. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for tickets, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Maintenance

#### Description
Track maintenance records and tasks for sold products/equipment.

#### Actors
Customer, System Admin, Employee

#### User Story
As a Customer, I need track maintenance records and tasks for sold products/equipment. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for maintenance, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### CRM and Marketing

#### Description
Track leads, interactions, campaigns, recipients, and responses.

#### Actors
System Admin

#### User Story
As a System Admin, I need track leads, interactions, campaigns, recipients, and responses. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for crm and marketing, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Reports and Notifications

#### Description
Provide operational reports and send reminders, invoice notices, and task notifications.

#### Actors
System Admin, Customer, Employee

#### User Story
As a System Admin, I need provide operational reports and send reminders, invoice notices, and task notifications. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for reports and notifications, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


### Audit Logs

#### Description
Record sensitive business and financial changes.

#### Actors
System

#### User Story
As a System, I need record sensitive business and financial changes. so that the IERP business process remains complete and traceable.

#### Acceptance Criteria
- Given the actor has valid access, when they submit valid data for audit logs, then the system saves the record and returns a successful API response.
- Given required data is missing, when the actor submits the request, then the system returns validation errors without changing business data.
- Given the actor is not allowed to perform the action, when they call the endpoint, then the system returns an authorization error and records no side effect.
- Given the action affects accounting, inventory, payment, tax, or status, when it succeeds, then the related audit log and required side effects are created.

#### Business Rules
- All status changes must follow approved transitions.
- Sensitive changes require audit logging.
- Backend is the source of truth for accounting, inventory, tax, salary, and payment calculations.

#### Data Requirements
The feature must use normalized relational tables, foreign keys, timestamps, and status fields when a lifecycle exists.

#### API Requirements
Expose RESTful API endpoints under the correct consumer prefix: `/api/dashboard`, `/api/customer`, `/api/employee`, or `/api/webhooks`.

#### Validation Rules
Validate required fields, ownership/access, numeric values, money values as decimals, file types, and status transition eligibility.

#### Edge Cases
- Duplicate submission.
- Invalid status transition.
- Missing dependent record.
- Failed background job or external integration.


## 6. User Stories

| User Story ID | Story |
|---|---|
| US-001 | As an admin, I want to manage products and variants so that customers and employees use the correct catalog. |
| US-002 | As an admin, I want to manage warehouses and stock movements so that available stock is accurate. |
| US-003 | As an admin, I want to create invoices and credit notes so that financial claims and corrections are controlled. |
| US-004 | As a customer, I want to view and pay invoices so that I can settle obligations online. |
| US-005 | As an employee, I want to see my monthly tasks and visits so that I can complete assigned work. |
| US-006 | As an admin, I want AI-detected sales drafts from voice notes so that potential opportunities are not missed. |

## 7. Acceptance Criteria

Acceptance criteria are included in each feature design section. Critical global criteria:

- Given an invoice is issued, when no payment is collected, then no tax recognition entry is created.
- Given a partial payment is collected, when tax recognition runs, then recognized tax equals `invoice_total_tax * (payment_amount / invoice_grand_total)`.
- Given a delivery note is confirmed, when stock is available, then inventory movement decreases stock for the selected product variant and warehouse.
- Given AI transcription fails, when the employee visit is submitted, then the visit remains completed and the AI job is marked failed/retryable.

## 8. Technical Compliance

- SOLID principles where practical.
- Thin controllers.
- Service/action classes for business logic.
- Form Requests for validation.
- API Resources for response shape.
- DB transactions for multi-step business operations.
- Queue jobs for async work.
- Audit logs for sensitive actions.

## 9. Data System Design

The system uses normalized relational tables for users, customers, employees, suppliers, products, variants, warehouses, inventory, sales documents, accounting, payments, tax, tickets, maintenance, CRM, AI, notifications, and audits.

Money fields use decimal types. Confirmed financial records are immutable and corrected by reversal documents, credit notes, or journal adjustments.

## 10. API Design Overview

API prefixes:

- `/api/dashboard/...`
- `/api/customer/...`
- `/api/employee/...`
- `/api/webhooks/stripe`

All APIs return a consistent JSON envelope with `success`, `message`, `data`, and optional `meta` or `errors`.

## 11. Security Considerations

- Token-based authentication.
- User-type access segregation.
- Stripe webhook signature verification.
- Private file storage for invoices, proofs, attachments, and voice notes.
- Authorization checks on customer and employee-owned records.
- Audit logs for sensitive changes.

## 12. Performance Considerations

- Paginate large lists.
- Index foreign keys, status columns, dates, and searchable fields.
- Queue PDF generation, emails, exports, AI transcription, and notifications.
- Cache stable settings and lookup tables.

## 13. Error Handling Strategy

- Validation errors return 422 with field-level errors.
- Authorization errors return 403.
- Missing resources return 404.
- Business rule failures return 409 when a status or state conflict exists.
- External failures store retryable job failures where applicable.

## 14. Dependencies

- Laravel API runtime.
- Relational database.
- Queue worker.
- File storage.
- Mail provider.
- Stripe.
- Replaceable AI transcription provider.

## 15. Assumptions

- Laravel API will be the primary backend.
- Dashboard framework is not locked, but API-first design supports React.
- Mobile framework is not locked, but API-first design supports Flutter or React Native.
- Website implementation is intentionally skipped now.

## 16. Future Spec Kit Extraction Map

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
