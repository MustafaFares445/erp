# Identity & Authorization Domain

---
status: canonical
owner: identity
last_verified: 2026-10-02
verified_against: app/Services/Identity, user/role/permission enums, policies and authorization tests
---

## Purpose

Identity owns user identity, dashboard role assignment and the access-control foundation used by every business domain.

## Main Code Anchors

- `app/Services/Identity/DashboardRoleAssignmentService.php`
- `App\Enums\UserType`
- `App\Enums\DashboardRole`
- Spatie Permission roles/permissions
- `app/Policies/`
- permission seeders and authorization tests

## Boundary

Business domains define their own abilities through domain permission enums/policies. Identity supplies the actor/role foundation and must not grant a cross-domain ability merely because a user can enter the dashboard.

## Current Surface

The implemented administration surface is Filament. The current runtime has no `api/*` route surface, so mobile/API authentication is not documented as implemented here.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
