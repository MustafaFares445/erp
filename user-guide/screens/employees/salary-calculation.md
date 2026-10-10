---
title: "Salary Calculations"
module: employees
pages: ["index", "view"]
screenshots: ["employees-salary-calculation-index", "employees-salary-calculation-view"]
lastVerified: 2026-10-07
---

# Employee Salary Calculations

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Operator procedure” instructions below.
- Follow the documented “Salary calculation list” instructions below.
- Follow the documented “Review a salary” instructions below.

<IerpNavigationPath :items='["Employees","Performance","Salary Calculations"]' />

Review compensation derived from the employee plan and performance rules. Calculation, recalculation and confirmation are separate controlled steps; only the appropriate role may confirm a payable amount.

## Operator procedure

1. Open the list, locate the employee and period, and review the current calculation status.
2. Open **View** and inspect the calculation basis, performance score, bonus suggestions and audit trail before taking action.
3. Check source plan and visits where a discrepancy is suspected. Only human-reviewed **Approved/Accepted** sales opportunities contribute to opportunity performance; AI drafts and rejected suggestions do not count.
4. Use the dedicated calculation/recalculation process for legitimate source corrections. Recalculation may supersede an earlier result; do not directly edit the confirmed historical amount.
5. Confirm the salary using the explicit confirmation action and required permission. Confirmation must not be inferred from the presence of a draft number.

## Salary calculation list

<IerpScreenshot id="employees-salary-calculation-index" title="Salary calculation register" description="Find employee salary calculations by period and status." path="Employees → Performance → Salary Calculations" />

## Review a salary

<IerpScreenshot id="employees-salary-calculation-view" title="Salary basis and approval status" description="Inspect the calculated result and its human confirmation status." path="Employees → Performance → Salary Calculations → View" />

**Statuses:** Draft, Pending Confirmation, Confirmed and Superseded. Permission to see calculations does not imply permission to confirm or approve bonuses. View-only activity never issues a payment or changes Accounting.

Related: [Performance](/screens/employees/performance), [Sales Plans](/screens/employees/monthly-plan).
