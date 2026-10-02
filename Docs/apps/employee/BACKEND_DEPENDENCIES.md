# Employee App Backend Dependencies

---
status: canonical
owner: employee-app
last_verified: 2026-10-02
verified_against: current Employees/Sales services, current route table, approved spec and active employee API plan
---

## Implemented Backend Foundations

### Employee operations
Implemented:
- employee onboarding/access;
- Sales Plans;
- Plan Tasks;
- Customer Visits;
- visit review note;
- performance;
- salary/bonus calculations;
- employee reports.

### GPS
Implemented:
- `VisitGpsLog` model/persistence;
- relationship from Customer Visit;
- Filament GPS trail display;
- tests for GPS log ordering/behavior.

### Voice / AI
Implemented:
- Voice Note intake;
- transcription request/result abstraction;
- OpenAI Whisper transcriber;
- fake test transcriber;
- transcription/voice states;
- keyword detection;
- opportunity review/evidence paths.

### Sales
Implemented backend Sales Opportunity and Quotation services are reusable by a future Employee API subject to employee ownership/permissions.

## Not Yet an Exposed Employee App Channel

At the current audit:
- no runtime `api/*` routes exist;
- Employee Mobile API/authentication is not exposed;
- dedicated Device Binding model/service was not found in the current scan;
- mobile push token/provider implementation was not found in the current scan;
- current GPS persistence does not by itself provide mobile check-in/geofence/GPS-batch endpoints;
- approved two-way Visit Review Conversation requires a message model/API beyond a single review-note field unless separately implemented.

The active Employee Visit/AI API implementation plan is the authoritative future-work plan until those endpoints exist.

## Required API Surface

The approved V1 requires API capabilities for:

- login / first-password change / logout;
- device binding state;
- profile;
- current plan/history;
- tasks;
- visits;
- check-in/geofence;
- GPS batch append;
- voice upload/transcription status;
- outcome/product opportunity confirmation;
- final submit/check-out;
- opportunities;
- quotations;
- performance/salary/bonus;
- notifications/read state;
- visit review conversation;
- push token registration/rotation.

## API Implementation Rule

Controllers/resources must call existing domain services or new shared domain services. Do not duplicate:
- plan/task transitions;
- visit state;
- pricing;
- opportunity/quotation lifecycle;
- AI review decisions;
- performance/salary calculation.

## Device Binding

Approved product behavior is one active device.

When implemented, binding/reset must:
- be server-authoritative;
- invalidate old-device sessions/tokens;
- block a second unapproved device;
- provide admin reset;
- avoid exposing raw device identifiers in user-facing errors.

## Geofence / GPS

A mobile check-in service still needs an explicit contract for:
- configured radius;
- customer coordinates;
- server distance validation;
- location accuracy policy;
- batch append;
- retry/idempotency;
- timestamps and anti-tamper considerations.

## App Readiness Rule

A screen in `employee-sales-app-v1.pen` is not proof that an endpoint exists.

Each interactive screen must map to an implemented or planned service/API contract before mobile development is declared backend-ready.
