# Customer App Backend Dependencies

---
status: canonical
owner: customer-app
last_verified: 2026-10-02
verified_against: current services/models/routes and adopted Customer V1 decisions
---

## Implemented Backend Foundations

### CRM / account
Implemented domain services include:
- customer onboarding/provisioning;
- admin approval / request changes / reject / reactivate;
- profile change requests;
- customer quotation requests;
- customer return requests;
- customer timeline.

### Catalog / pricing
Implemented:
- product/variant catalog models/resources;
- media;
- server-side PriceResolver/pricing tiers/floor rules.

### Sales / fulfillment
Implemented:
- quotations and customer responses;
- Sales Orders;
- invoices and electronic document support;
- shipment arrival-confirmation domain service;
- credit notes.

### Payments / Accounting
Implemented service-level foundations include:
- `PaymentTransaction`;
- Stripe checkout abstraction;
- provider reconciliation/settlement services;
- customer-deposit application;
- ERP refund accounting;
- Stripe refund execution service.

### Support
Implemented:
- ticket intake/triage/messages/lifecycle;
- ticket payment links/provider settlement;
- maintenance;
- equipment/warranty entitlement/claims/recovery.

### Notifications
Database/mail notification infrastructure and delivery records exist.

## Not Yet an Exposed Customer App Channel

At the current audit:
- no runtime `api/*` routes exist;
- no complete Customer Mobile API/authentication surface is exposed;
- Stripe service classes do not equal a public mobile Checkout endpoint;
- code comments on Stripe checkout explicitly state that HTTP/webhook adapter work is still future-facing;
- mobile push device-token/provider behavior is not established by the current notification services.

## Required Mobile/API Boundary

A Customer API should provide ownership-scoped endpoints/resources for:

- registration / session / profile;
- catalog and resolved pricing;
- quote cart/request;
- quotation decision;
- orders / fulfillment / shipment confirmation evidence;
- invoices/PDF;
- provider payment session/status;
- returns;
- support/tickets/equipment/warranty/maintenance;
- notifications;
- profile/change requests/addresses.

Controllers must call existing domain services rather than duplicate pricing, status, stock, payment or support rules.

## Payment Adapter Requirements

Future public Stripe channel still needs an explicit secure adapter around current domain services:
- authenticated endpoint to create/reuse provider payment session;
- verified provider callback/webhook/event handling;
- idempotent reconciliation into `PaymentTransaction` / ERP Payment;
- ownership checks;
- retry/reconcile observability;
- mobile-safe status resource.

Never trust a client-reported â€œpayment successfulâ€ state.

## App Readiness Rule

A screen in `customer-app-v1.pen` is a UI specification, not proof that its backend endpoint exists.

Before implementation, each interactive screen should map to:
1. existing domain service/model;
2. required API/controller/resource;
3. ownership rule;
4. lifecycle transition;
5. tests.
