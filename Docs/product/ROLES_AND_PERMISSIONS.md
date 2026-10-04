# Roles and Permissions

---
status: canonical
owner: identity
last_verified: 2026-10-04
verified_against: DashboardRole, UserType and domain permission enums/policies
---

## User Types

Current `UserType` values:

- `admin`
- `customer`
- `employee`

User type is not a substitute for domain permission checks.

## Fixed Dashboard Roles

Current `DashboardRole` values:

- System Admin
- CRM Manager
- Pricing Manager
- Reviewer
- Employee Manager
- Payroll Officer
- Support Manager
- Support Agent
- Chief Accountant
- Accountant
- Purchasing Manager
- Purchasing Officer
- Warehouse Manager
- Sales Manager
- Sales Officer
- Billing Officer
- System Integration

## Permission Model

Permissions are grouped by business domain.

Examples:

- `inventory.*` (including `inventory.loan.manage` and `inventory.supplier-custody.manage`, held by Warehouse Manager, which Support actions that move custody additionally require)
- `purchase.*`
- `sales.*`
- `accounting.*`
- `crm.*`
- `employees.*`
- `support.*`
- `system.*`

Each domain's permission enum is the canonical code-level catalogue for that domain.

## Enforcement

Authorization may exist at several layers:

1. Filament navigation/action visibility.
2. Policy/Gate authorization.
3. Domain service authorization/guards.
4. Ownership validation for customer/employee channels.

UI visibility alone is never the security boundary.

## System Admin

System Admin is the highest fixed dashboard role.

Some maker-checker workflows explicitly contain a System Admin exemption to prevent single-admin deployments from deadlocking. Such exemptions are workflow-specific and must not be generalized into ordinary roles.

## Support Permission Highlights

`SupportPermission` is the canonical Support permission catalogue. In addition to ticket, maintenance, service-record, warranty, schedule, cost, report and audit permissions, it includes:

- team/queue/routing permissions and `support.ticket.route`;
- automation view/manage;
- field-service appointment view/manage/execute;
- Equipment 360 view;
- knowledge view/manage/publish;
- SLA calendar, service-level and support-entitlement view/manage;
- installation view/manage/complete (`support.installation.*`; managers get all three, agents view and complete, reviewers view);
- calibration view/manage/complete (`support.calibration.*`; managers get all three, agents view and complete, reviewers view);
- loaner view/manage (`support.loaner.*`) and supplier-repair view/manage (`support.rma.*`; managers get both, agents view, reviewers view);
- CSAT report visibility.

`Support Manager` receives the operational/configuration Support permissions except System-Admin-only abilities such as record restoration and manual ticket-payment settlement. `Support Agent` receives own-work execution permissions plus read access appropriate to day-to-day service. `Reviewer` receives read/report/audit visibility.

Feature switches are an additional rollout boundary; granting a permission does not enable a disabled staged resource.

## Customer and Employee Ownership

The implemented Customer Support API derives customer identity from the authenticated user. Future employee/mobile endpoints must follow the same ownership rule.

The client must not be trusted to select an arbitrary `customer_id` or `employee_id`.

See:

- [Identity Domain](../domains/identity/README.md)
- [Customer App Backend Dependencies](../apps/customer/BACKEND_DEPENDENCIES.md)
- [Employee App Backend Dependencies](../apps/employee/BACKEND_DEPENDENCIES.md)
