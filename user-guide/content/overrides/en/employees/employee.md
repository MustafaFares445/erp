# Employees

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Daily administration” instructions below.
- Follow the documented “Employee directory” instructions below.
- Follow the documented “Create an employee” instructions below.

<IerpNavigationPath :items='["Employees","Workforce","Employees"]' />

Manage employee records and authorized application access, then associate eligible staff with monthly plans, tasks, visits and reporting. Employee accounts are not customer accounts; only authorized administrators can change access.

## Daily administration

1. Search the list by employee code, name, username, email, phone or job title.
2. Use **Active**, **Inactive**, **Newly Hired** and **Archived** tabs to distinguish availability and lifecycle; Archived records use a separate soft-delete scope, not the ordinary active/inactive filter.
3. **Create** an employee with identity, contact details and a linked user account according to onboarding rules. Review assigned roles and access before activation.
4. **View** employment and linked operational context before changing assignments.
5. **Edit** eligible records using permissions. Use the dedicated Employee access process for enable, disable, archive or restore, preserving the audit trail.
6. Check plan/task and visit ownership when deactivating; access changes do not erase completed performance, visits or historical salary records.

## Employee directory

<IerpScreenshot id="employees-employee-index" title="Employee master register" description="Search and switch active, inactive, recently hired and archived views." path="Employees → Employees" />

## Create an employee

<IerpScreenshot id="employees-employee-create" title="Employee onboarding" description="Set identity and access context before assigning field work." path="Employees → Employees → Create" />

## Employee details

<IerpScreenshot id="employees-employee-view" title="Employee profile and assignments" description="Inspect the employment record before changes to access or planning." path="Employees → Employees → View" />

## Edit and lifecycle

<IerpScreenshot id="employees-employee-edit" title="Controlled employee changes" description="Update editable staff details while honoring permissions and archived state." path="Employees → Employees → Edit" />

The existing dashboard supports employee management, but the current runtime has **no /api/employee endpoints** for the mobile app. Do not claim an API integration based on these screens.

Related: [Monthly Plans](/screens/employees/monthly-plan), [Visits](/screens/employees/visit), [Salary Calculations](/screens/employees/salary-calculation).
