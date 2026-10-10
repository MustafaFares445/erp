# Identity & Authorization Domain

---
status: canonical
owner: identity
last_verified: 2026-10-07
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

The primary administration surface is Filament. Customer API authentication is now implemented with Sanctum at `/api/customer/login` and enforces Customer type plus active/approved profile state on protected customer routes. Employee mobile authentication is not exposed because there is no `/api/employee` route surface. See [API Reference](../../reference/API.md).

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
