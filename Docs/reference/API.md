# API Reference and Conventions

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: current Laravel route table and mobile active plans
---

## Current Runtime API Surface

As of 2026-10-02, the application route table contains no `api/*` routes.

There is therefore no canonical implemented REST endpoint inventory to document yet.

Older documents that list `/api/dashboard`, `/api/customer` or `/api/employee` endpoints are historical/planned material and must not be used as runtime truth.

## Service Capability vs API Exposure

IERP already has services that a future API can call, including:
- Customer onboarding/quote/return workflows.
- Sales quotation/order/invoice.
- Shipment arrival confirmation.
- Stripe/provider payment services.
- Employee plans/visits/GPS/voice/AI/performance.
- Support/warranty/maintenance.

A service method is not an HTTP contract.

## Future API Conventions

When mobile APIs are implemented:

- authenticate first;
- derive customer/employee ownership from the authenticated principal;
- do not trust client-selected owner IDs;
- use domain services for transitions/calculations;
- server derives trusted prices/payment amounts;
- expose stable enum/status values intentionally;
- private media requires authorization;
- high-impact POST actions should be idempotent where retries are expected;
- provider callbacks must be verified server-side;
- responses must avoid internal cost/accounting/warehouse fields not required by the client.

## Documentation Strategy

Use generated OpenAPI/Scramble output for actual implemented endpoints.

Handwritten docs should explain:
- authentication;
- ownership;
- idempotency;
- errors;
- cross-domain workflow semantics.

Do not manually maintain a hypothetical global endpoint list.
