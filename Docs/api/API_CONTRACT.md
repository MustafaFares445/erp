# API Contract

## 1. API Overview

IERP exposes REST APIs for dashboard, customer app, employee app, public/auth flows, and Stripe webhooks. Backend is the source of truth for statuses, accounting, tax, inventory, payments, salary, and authorization.

## 2. Authentication

- Use token-based authentication.
- Separate route middleware by user type: admin, customer, employee.
- All protected endpoints require `Authorization: Bearer <token>`.

## 3. Common Headers

| Header | Required | Description |
|---|---|---|
| Authorization | Yes for protected APIs | Bearer token |
| Accept | Yes | `application/json` |
| Content-Type | Yes for body requests | `application/json` or `multipart/form-data` |
| X-Request-Id | Optional | Client request id for tracing |

## 4. Common Response Format

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

## 5. Error Response Format

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {}
}
```

## 6. Pagination Format

```json
{
  "success": true,
  "message": "Records fetched successfully.",
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100
  }
}
```

## 7. File Upload Rules

- Use `multipart/form-data` for attachments, payment proofs, signatures, and voice notes.
- Store sensitive files privately.
- Validate mime type, size, extension, and actor access.

## 8. Dashboard APIs

The CRM customer and pricing-tier feature approved by ADR 0002 is managed only
through the authenticated Filament `/admin` panel. It adds no REST endpoint to
this contract. In particular, there is no product-subscription API and no CRM
payment-term endpoint; the payment-term endpoint below belongs to the separate
Sales and Accounting workflow.

The Employees, Monthly Plans, Visits, Performance & Salary dashboard feature
approved by ADR 0003 is likewise managed only through the authenticated
Filament `/admin` panel. It adds **no** REST endpoint to this contract — no
`/api/employee` route and no other employee-facing API surface. This is an
explicit, intentional scope boundary (ADR 0003 / decision D10), not an
oversight: `/api/employee` endpoints, the employee mobile application,
employee-app visit capture, employee-app attendance capture, and mobile
authentication flows all remain out of scope pending their own specification
and either a separate ADR or an explicit amendment to ADR 0003. The
`/api/dashboard/sales-plans`, `/api/dashboard/plan-tasks`,
`/api/dashboard/visits`, and `/api/dashboard/ai/sales-drafts` rows below and
the Employee App APIs in Section 10 remain the pre-existing, unimplemented
aspirational surface described elsewhere in this contract — they were not
added or implemented by this feature.

### Dashboard APIs

| Path | Method | Purpose |
|---|---|---|
| `/api/dashboard/users` | GET/POST | Manage users |
| `/api/dashboard/customers` | GET/POST | Manage customers |
| `/api/dashboard/employees` | GET/POST | Manage employees and salary settings |
| `/api/dashboard/suppliers` | GET/POST | Manage suppliers |
| `/api/dashboard/products` | GET/POST | Manage products |
| `/api/dashboard/product-variants` | GET/POST | Manage variants |
| `/api/dashboard/warehouses` | GET/POST | Manage warehouses |
| `/api/dashboard/inventory/movements` | GET | View stock movements |
| `/api/dashboard/inventory/adjustments` | POST | Create adjustment |
| `/api/dashboard/inventory/transfers` | POST | Create transfer |
| `/api/dashboard/accounting/accounts` | GET/POST | Chart of accounts |
| `/api/dashboard/accounting/journal-entries` | GET/POST | Journal entries |
| `/api/dashboard/payment-terms` | GET/POST | Payment terms |
| `/api/dashboard/quotations` | GET/POST | Quotation management |
| `/api/dashboard/delivery-notes` | GET/POST | Delivery note management |
| `/api/dashboard/invoices` | GET/POST | Invoice management |
| `/api/dashboard/invoices/{id}/generate-pdf` | POST | Generate PDF |
| `/api/dashboard/invoices/{id}/send-email` | POST | Send invoice email |
| `/api/dashboard/credit-notes` | GET/POST | Credit notes |
| `/api/dashboard/payment-methods` | GET/POST | Manual payment methods |
| `/api/dashboard/manual-payments` | POST | Record manual payment |
| `/api/dashboard/tax-recognitions` | GET | Tax recognition entries |
| `/api/dashboard/orders/{id}/supplier-confirmations` | POST | Manual supplier confirmation |
| `/api/dashboard/sales-plans` | GET/POST | Monthly plans |
| `/api/dashboard/plan-tasks` | GET/POST | Tasks |
| `/api/dashboard/visits` | GET | Visits and GPS |
| `/api/dashboard/ai/sales-drafts` | GET/PATCH | Review sales drafts |
| `/api/dashboard/tickets` | GET/POST | Ticket management |
| `/api/dashboard/maintenance` | GET/POST | Maintenance |
| `/api/dashboard/crm/leads` | GET/POST | Leads |
| `/api/dashboard/marketing/campaigns` | GET/POST | Campaigns |
| `/api/dashboard/reports/{type}` | GET | Reports |
| `/api/dashboard/audit-logs` | GET | Audit logs |

## 9. Customer App APIs

### Customer APIs

| Path | Method | Purpose |
|---|---|---|
| `/api/customer/products` | GET | Browse products |
| `/api/customer/products/{id}` | GET | Product details |
| `/api/customer/quotations` | GET/POST | List/request quotations |
| `/api/customer/quotations/{id}/accept` | POST | Accept quotation |
| `/api/customer/quotations/{id}/reject` | POST | Reject quotation |
| `/api/customer/orders` | GET/POST | Orders/purchase requests |
| `/api/customer/invoices` | GET | Invoice list |
| `/api/customer/invoices/{id}` | GET | Invoice details |
| `/api/customer/invoices/{id}/pay` | POST | Stripe payment |
| `/api/customer/tickets` | GET/POST | Tickets |
| `/api/customer/tickets/{id}/pay` | POST | Pay ticket |
| `/api/customer/maintenance` | GET/POST | Maintenance requests |
| `/api/customer/notifications` | GET | Notifications |
| `/api/customer/profile` | GET/PATCH | Profile |

## 10. Employee App APIs

> The endpoints below describe a possible future employee-facing mobile API.
> They are **not implemented** by the Employees, Monthly Plans, Visits,
> Performance & Salary dashboard feature (spec 015), which is a Filament
> `/admin` dashboard extension only, approved by ADR 0003. Building any
> employee-facing API requires its own specification and either a separate
> ADR or an explicit amendment to ADR 0003.

### Employee APIs

| Path | Method | Purpose |
|---|---|---|
| `/api/employee/sales-plans` | GET | Monthly plans |
| `/api/employee/tasks` | GET | Assigned tasks |
| `/api/employee/tasks/{id}/status` | PATCH | Update task status |
| `/api/employee/visits` | GET | Visit list |
| `/api/employee/visits/{id}/check-in` | POST | Check in |
| `/api/employee/visits/{id}/check-out` | POST | Check out |
| `/api/employee/visits/{id}/gps` | POST | Upload GPS |
| `/api/employee/visits/{id}/attachments` | POST | Upload attachment |
| `/api/employee/visits/{id}/voice-notes` | POST | Upload voice note |
| `/api/employee/quotations` | POST | Create quotation during visit |
| `/api/employee/delivery-notes` | POST | Create delivery note during visit |
| `/api/employee/performance` | GET | Performance progress |
| `/api/employee/notifications` | GET | Notifications |
| `/api/employee/profile` | GET/PATCH | Profile |

## 11. Stripe Webhook APIs

### Stripe Webhook

**Method:** `POST`  
**Path:** `/api/webhooks/stripe`  
**Actor:** Stripe  
**Purpose:** Receive payment events and update payments, tax recognition, invoice/ticket statuses, and accounting entries.

#### Request Body
Stripe event payload.

#### Business Rules
- Verify Stripe signature.
- Use idempotency by event id/payment intent id.
- Create payment record only once.
- Run the same tax recognition logic used by manual payments.
- Dispatch accounting posting after successful payment.

#### Side Effects
- Payment status update.
- Invoice or ticket status update.
- Tax recognition entry.
- Journal entry.
- Notification.
- Audit log.

## 12. Status Transition APIs

Status updates must be explicit endpoint actions where transitions matter, such as quotation accept/reject, delivery confirm, invoice issue, credit note confirm, payment record, task update, visit check-in/out, ticket assignment, and maintenance close.

## 13. API Validation Rules

- Money values: decimal, non-negative unless reversal is explicitly supported.
- Quantities: decimal and positive for sale/delivery/transfer lines.
- Dates: valid dates and not conflicting with fiscal period rules.
- Status transitions: allowed transition only.
- Files: valid mime, size, and authorization.
- Inventory: product variant and warehouse required for stock-changing operations.

## 14. API Authorization Rules

| Actor | Allowed Surface |
|---|---|
| System Admin | Dashboard APIs |
| Customer | Own customer APIs and own records only |
| Employee | Own employee tasks/visits and allowed sales operations |
| Stripe | Webhook endpoint only |

## 15. Detailed Endpoint Pattern

Use this pattern for every implementation endpoint:

````md
### Endpoint Name

**Method:** `POST`  
**Path:** `/api/...`  
**Actor:** System Admin / Customer / Employee / Stripe  
**Purpose:** Short purpose.

#### Request Body
| Field | Type | Required | Description |
|---|---|---|---|

#### Response
```json
{"success": true, "message": "Operation completed successfully.", "data": {}}
```

#### Validation Rules
- Required fields.
- Ownership and status rules.

#### Business Rules
- Domain-specific rules.

#### Side Effects
- Inventory movement, journal entry, tax recognition, notification, audit log, or job dispatch as applicable.
````

## 16. Open Questions

- Confirm whether password reset is in scope for first implementation.
- Confirm exact invoice/ticket payment link expiry behavior.
- Confirm whether manual payments require approval.

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
