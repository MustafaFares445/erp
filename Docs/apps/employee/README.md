# Employee Sales App V1

---
status: canonical
owner: employee-app
last_verified: 2026-10-02
verified_against: EMPLOYEE_APP_V1_APPROVED_SPEC.md, current employee backend services/tests, active API plan, design/employee-sales-app-v1.pen
---

## Purpose

The Employee Sales App is the field-sales channel for employee planning/tasks, customer visits, check-in/GPS, voice/AI-assisted outcome capture, opportunities, quotations, notifications, performance and salary visibility.

Inventory/warehouse operations are out of V1 scope.

## Current Design Source

`design/employee-sales-app-v1.pen`

## Current Backend State

Employee dashboard/domain services are substantially implemented, including plans/tasks/visits, GPS log model/display, voice-note/transcription/AI evidence, opportunity review, performance/salary and reports.

The current runtime still exposes no `api/*` routes. The active `Docs/plans/EMPLOYEE_VISIT_AI_API_IMPLEMENTATION_PLAN.md` therefore describes unfinished API/mobile integration work.

See [Backend Dependencies](BACKEND_DEPENDENCIES.md).

## Primary Navigation

Current design handoff uses five persistent destinations:

- Home
- Tasks
- Visits
- Sales
- Notifications

Profile / Security is opened from the top-bar avatar.

This preserves the approved feature set while avoiding bottom-nav crowding.

## Cross-Domain References

- [Employees](../../domains/employees/README.md)
- [Sales](../../domains/sales/README.md)
- [CRM](../../domains/crm/README.md)
- [Notifications](../../domains/notifications/README.md)
- [Canonical Business Flows](../../product/BUSINESS_FLOWS.md)
