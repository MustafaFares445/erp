# ADR 0012: Activate the Customer and Employee Channel API (WP-4.5)

**Status**: Accepted

**Date**: 2026-09-12

**Deciders**: Project Owner

**Related**: `PHASE_4_PLAN.md` §1 WP-4.5, `ERP_REMEDIATION_PLAN.md` GAP-MW-19, ADR 0003 (Employees dashboard, §"Not authorised by this ADR"), ADR 0008 (Sales/Payments dashboard), `routes/api.php`, `app/Http/Controllers/Api/V1/`, `app/Services/Channels/`

## Context

ADR 0003 explicitly deferred an employee-facing API: "Not authorised by this
ADR: `/api/employee` endpoints; the employee mobile app, employee-app visit
capture, mobile authentication flows." ADR 0008 carried the equivalent
deferral for a customer-facing surface. `PHASE_4_PLAN.md` recorded both as
GAP-MW-19, Band 5, with a concrete cost of leaving it deferred: **all
customer self-service and all field capture is re-keyed by office staff, and
GPS check-in (EM-04) cannot be captured at all**, which means the work-time
adherence factor driving salary calculation (EM-09, EM-10) is fed by
office-entered timestamps rather than the field.

The plan's own case for activating this now, rather than leaving it
deferred, is that the domain was **already built channel-ready** by earlier
phases: `QuotationService::recordDecision()` preserves both the decider and
the recorder, `ShipmentService::confirmByCustomer()` already exists,
`ShipmentConfirmationSource` already distinguishes `Customer | AdminUser |
System`. Every one of those was, until this decision, invoked only by an
admin recording someone else's action. The remaining work was transport,
authentication, and authorization — not new domain design — which is why
this package was judged cheap enough to pull out of Phase 4B into ordinary
engineering work rather than needing its own funding decision.

## Decision

Activate `/api/v1`, versioned, token-authenticated via Laravel Sanctum, as
the sixth application surface alongside the Filament admin panel — a
**customer self-service channel** and an **employee field-capture channel**,
both calling the exact same domain services the admin panel already calls.

### In scope, and what was actually built

- **`routes/api.php`**: `POST /api/v1/auth/token` (rate-limited
  `throttle:10,1`) and `DELETE /api/v1/auth/token` for token issuance/
  revocation, then two prefixed, `auth:sanctum`-gated route groups.
- **Customer surface** (`CustomerChannelController`,
  `CustomerChannelService`): catalogue at resolved prices, quotation listing
  and request, order listing and detail, invoice listing/detail/document
  download, account statement, ticket listing/detail/creation.
- **Employee surface** (`EmployeeChannelController`,
  `EmployeeChannelService`): plan/task listing, visit listing, visit
  check-in and check-out **with GPS**, voice-note upload, lead capture,
  interaction capture, opportunity creation, van-warehouse sale.
- **`ChannelActorResolver`**: resolves the authenticated Sanctum user to a
  `CustomerProfile`/`EmployeeProfile`-linked actor and refuses the request
  (`AuthorizationException`) when the token holder has no active profile of
  the matching kind — a customer token cannot reach the employee surface and
  vice versa, independent of whatever Filament permissions that same user
  might separately hold.
- **No controller calls a model directly.** Every write goes through the
  same service Filament calls, so the two channels cannot diverge on
  business rules. This is enforced as code, not as a review convention:
  `tests/Unit/ApiArchitectureTest.php` fails the build if any file under
  `app/Http/Controllers/Api/V1` references `App\Models\`, `::query()`,
  `::create(`, or `::update(`.
- **Policies are reused unchanged.** Neither channel controller nor channel
  service defines its own authorization rules; both call the same Filament
  policies, which is the only way XC-05's "role boundaries hold identically
  across every path" is guaranteed rather than merely asserted.

### Out of scope

This decision does **not** authorize:

- `dedoc/scramble`-generated API documentation or a rewritten
  `Docs/api/API_CONTRACT.md` — the plan named this as part of the package;
  it was not built in this pass and remains open (see Consequences).
- `tests/Feature/Api/ChannelParityTest.php` — the plan's own description of
  this as "the important one," asserting that the same business action
  through the API and through Filament produces byte-identical domain
  records, was **not built**. `tests/Feature/Api/ChannelApiTest.php` and
  `tests/Unit/ApiArchitectureTest.php` exist and pass, but neither is a
  parity test, and neither is a contract test per endpoint. This is the
  single largest gap this ADR records rather than papers over.
- Any channel beyond customer and employee (no supplier portal, no public
  unauthenticated endpoints beyond token issuance).
- Changing any policy, service, or business rule to accommodate the API —
  every rule the API enforces already existed for the admin panel.

## Consequences

**Positive.** SL-13, SL-14, EM-03, EM-04, EM-05, SU-01, and CR-01 are no
longer permanently blocked on a future decision — the two most
labor-intensive re-keying gaps (customer self-service, field GPS capture)
are closed. F-19 and F-20 can now run. Because every write already went
through a shared service, no new business logic had to be invented or
audited for the second channel — the risk this ADR carried was almost
entirely in transport and auth, which is what `ChannelActorResolver` and the
architecture test now guard.

**Negative.** Test coverage is thinner than the plan specified: 21 routes
across the two surfaces are exercised by 4 tests in `ChannelApiTest.php`,
not a contract test per endpoint, and there is no parity test proving the
API and Filament paths agree byte-for-byte. This is a real, currently open
gap, not a resolved one — see the final remediation report for the
recommended follow-up scope. The `/api/dashboard` documentation the plan
called for was likewise not generated in this pass.

**Neutral.** A Sanctum-issued token now carries the same authorization
weight as a Filament session for whatever it's scoped to; token revocation
(`DELETE /api/v1/auth/token`) is therefore the operational control that
matters if a device is lost, and it exists and is tested
(`ChannelApiTest.php`'s revoke-then-401 case, which uncovered and was fixed
for a Sanctum guard-caching artifact specific to sequential calls within one
test method, not a production behavior — see the remediation report).

**Enforcement.** `tests/Unit/ApiArchitectureTest.php` keeps the "no
controller touches a model" invariant enforceable in CI, not just in this
document. Per `.ai/feature-development` rule 8, this test may not be
weakened to make a future change pass.
