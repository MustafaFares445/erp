# Roles and Permissions

---
status: canonical
owner: identity
last_verified: 2026-10-02
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

- `inventory.*`
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

## Customer and Employee Ownership

Future mobile APIs must derive the customer/employee identity from the authenticated user.

The client must not be trusted to select an arbitrary `customer_id` or `employee_id`.

See:

- [Identity Domain](../domains/identity/README.md)
- [Customer App Backend Dependencies](../apps/customer/BACKEND_DEPENDENCIES.md)
- [Employee App Backend Dependencies](../apps/employee/BACKEND_DEPENDENCIES.md)
