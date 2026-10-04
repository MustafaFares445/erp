# API Reference and Conventions

---
status: canonical
owner: engineering
last_verified: 2026-10-04
verified_against: routes/api.php, Customer API controllers/requests/resources and feature tests
---

## Current Runtime API Surface

The implemented runtime API currently exposes a customer authentication surface plus a rollout-gated Support/Maintenance surface under `/api/customer`.

There is not yet a general-purpose global ERP REST API.

## Authentication

Customer authentication uses Laravel Sanctum personal access tokens.

### Login

`POST /api/customer/login`

Input:

- `login` — customer username or email;
- `password`;
- optional `device_name`.

Only `UserType::Customer` accounts with a linked, **active and approved** `CustomerProfile` may authenticate. A successful login returns a Bearer token with `customer:*` ability plus basic user/customer status. Invalid credentials return `422` with the same message whether or not the account exists (a hash comparison always runs, so timing does not reveal it); a suspended/unapproved customer receives `403`.

Tokens expire after `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 30 days). Every authenticated route requires the `customer:*` ability, so a token without it is refused with `403`.

### Logout

`POST /api/customer/logout`

Requires `auth:sanctum` and deletes the current access token; the token cannot be used afterwards.

## Customer Support API

All routes below require:

- Sanctum authentication;
- a Customer user with an active, approved profile (re-checked on every request, so suspending a customer cuts off tokens already issued);
- the `customer:*` token ability;
- `SUPPORT_CUSTOMER_API_ENABLED=true`.

Ownership is derived from the authenticated `User → CustomerProfile`. The client never supplies the authoritative `customer_id`.

### Tickets

- `GET /api/customer/support/tickets`
- `POST /api/customer/support/tickets`
- `GET /api/customer/support/tickets/{ticket}`

Ticket creation validates customer-owned serialized equipment when an internal equipment id is supplied. External equipment may be described without creating Inventory custody.

Responses expose customer-facing stage/action state, SLA due dates, warranty eligibility, diagnostic-payment state, customer-safe knowledge links, private attachment links and CSAT eligibility. Internal cost/accounting/routing details are not part of the contract.

### Conversation

- `GET /api/customer/support/tickets/{ticket}/messages`
- `POST /api/customer/support/tickets/{ticket}/messages`

Only public conversation entries are returned. Internal notes are never exposed. Customer replies do not count as the Support team's first response.

### Private ticket attachments

`GET /api/customer/support/tickets/{ticket}/attachments/{media}`

The media must belong to that ticket's private `ticket-attachments` collection, the authenticated customer must own the ticket, and the file must be customer-visible (uploaded by the customer through the API, `visibility=customer` media property). Files that staff attach to a ticket are internal: they are neither listed in the ticket resource nor downloadable here.

### Diagnostic payment

- `POST /api/customer/support/tickets/{ticket}/payment-session`
- `GET /api/customer/support/tickets/{ticket}/payment-status`

Checkout amount/currency come from the server-side pending `TicketPaymentLink`; client-supplied payment totals are not accepted.

The session endpoint accepts HTTPS/HTTP success and cancel URLs whose host is the application host or one of `SUPPORT_CUSTOMER_API_REDIRECT_HOSTS` (subdomains included), and returns the provider checkout URL and transaction state. Provider settlement still follows the Payments-domain reconciliation/service path; no public Stripe webhook route is currently exposed.

### Equipment

- `GET /api/customer/equipment`
- `GET /api/customer/equipment/{equipment}`

Only serialized units currently owned by the authenticated customer are returned.

### Maintenance

- `GET /api/customer/maintenance`
- `GET /api/customer/maintenance/{maintenance}`

Only maintenance records belonging to the authenticated customer are returned. The resource exposes customer-relevant diagnosis/coverage, the summary of a quotation or invoice once it is no longer a draft, and latest appointment information, without exposing internal service cost.

### Satisfaction

`POST /api/customer/support/tickets/{ticket}/satisfaction`

Requires `SUPPORT_CSAT_ENABLED=true`. Exactly one rating (1–5) may be submitted after the ticket is Closed.

## Knowledge Base API

These routes require both the Customer Support API flag and `SUPPORT_KNOWLEDGE_BASE_ENABLED=true`:

- `GET /api/customer/support/knowledge`
- `GET /api/customer/support/knowledge/suggestions`
- `GET /api/customer/support/knowledge/{article}`

Only published articles with customer/both visibility are exposed. Suggestions may use ticket type, issue text and authenticated customer-owned equipment. Internal articles and drafts never leave the admin channel.

## Security and Domain Rules

- Authenticate before ownership-sensitive access.
- Derive customer ownership from the authenticated principal.
- Return 404 for another customer's ticket/equipment/maintenance/media so record existence is not disclosed.
- Do not trust client-selected owner ids, commercial totals or warranty decisions.
- Use domain services for transitions, payments and operational side effects.
- Private media requires authorization on every download.
- Customer responses exclude internal notes, support costs, accounting details and other admin-only facts.
- Rate limits: login is limited per IP (20/min) and per login+IP (5/min); every authenticated route has a per-user ceiling of 120/min; ticket creation, replies, payment sessions and feedback have their own tighter limits. Exceeding a limit returns `429`.
- Replying to a closed or cancelled ticket returns `422` (validation error on `message`).
- Responses use Laravel resources (`{"data": ...}`) for tickets, messages, equipment, maintenance and knowledge; login, logout, payment and satisfaction return small bare JSON objects.
- A shared knowledge link disappears from the ticket resource as soon as its article is unpublished or becomes internal.

## Service Capability vs API Exposure

A service method is not automatically an HTTP contract.

IERP contains additional internal capabilities in Sales, Payments, Employees, Inventory and Accounting that are not exposed through `routes/api.php`.

## Documentation Strategy

Generated OpenAPI/Scramble output should describe machine-readable endpoint schemas once that publishing flow is enabled.

This canonical handwritten reference records:

- authentication;
- ownership/security rules;
- currently exposed route groups;
- cross-domain semantics and rollout gates.

It must not document hypothetical endpoints as implemented runtime truth.
