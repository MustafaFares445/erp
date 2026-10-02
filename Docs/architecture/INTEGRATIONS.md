# Integrations

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: current service/provider interfaces and event/listener wiring
---

## Internal Domain Integrations

IERP primarily integrates modules inside one Laravel transaction/application boundary.

Important event bridges are documented in [Canonical Business Flows](../product/BUSINESS_FLOWS.md).

## Stripe

Current code includes:
- provider interface/client implementation;
- checkout-session service;
- payment reconciliation;
- provider settlement;
- Stripe refund service;
- PaymentTransaction persistence.

Important boundary:
the current runtime exposes no `api/*` routes and the checkout service comments explicitly describe HTTP/webhook adapters as future work.

Stripe success is never accepted from a mobile success screen as ERP accounting truth.

## OpenAI Whisper

Employee voice transcription has a transcriber contract with:
- OpenAI Whisper implementation;
- fake implementation for deterministic tests.

Transcription/AI output remains evidence/suggestion until Employee workflow rules accept/confirm it.

## Laravel Filesystems / Media Library

Spatie Media Library is used for domain media/documents/evidence.

Storage disks/configuration are environment-specific. A configured disk does not imply that all environments use the same physical provider.

## Notifications

Current notification infrastructure includes persisted delivery/template behavior and existing channels such as database/mail.

Customer/Employee V1 require mobile push; a push-token/provider implementation was not established in the current audit and must not be assumed.

## Maps / Geolocation

Inventory/employee/customer records contain geographic/location use cases, and employee visits persist GPS logs.

The old standalone OpenStreetMap document is not considered canonical unless the corresponding current integration is verified in code. Mobile geofence/maps implementation must be documented with the actual provider when implemented.
