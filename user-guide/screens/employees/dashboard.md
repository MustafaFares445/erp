---
title: "Employees Dashboard"
module: employees
pages: ["overview"]
screenshots: ["employees-dashboard-overview"]
lastVerified: 2026-10-07
---

# Employees Dashboard

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Operational review” instructions below.
- Follow the documented “Application boundary” instructions below.

<IerpNavigationPath :items='["Employees","Overview","Dashboard"]' />

Use the dashboard to assess field workforce performance, plan execution and visit workload. Its figures come from operational records rather than manually entered totals.

## Operational review

1. Set the dashboard date range and optionally filter by **Employee**.
2. Compare completed tasks and tasks due by status. Use the overdue queue to locate tasks requiring follow-up.
3. Review completed and outstanding visits, field activity and location warnings. Open individual visits for GPS, outcome and follow-up evidence.
4. Compare the top employees and performance charts. Follow-up tasks are excluded from the base monthly task denominator.
5. Only opportunities **Approved** after human review contribute to opportunity performance. AI Draft and Rejected suggestions do not count.
6. Use **Plans**, **Tasks**, **Visits**, **Performance** and **Salary Calculations** for real corrections. Dashboard viewing and filters do not confirm salaries or create quotations.

## Workforce overview

<IerpScreenshot id="employees-dashboard-overview" title="Employee activity and performance overview" description="Review task progress, visit workload, top employees and overdue work." path="Employees → Overview" imageSrc="/images/en/employees/employees-dashboard-overview.png" />

## Application boundary

The employee backend and administration dashboard exist, but this runtime still exposes **no /api/employee endpoints** for the planned mobile app. These visual reports do not prove that mobile check-in, voice transcription or GPS APIs are deployed.

Related: [Monthly Plans](/screens/employees/monthly-plan), [Tasks](/screens/employees/task), [Visits](/screens/employees/visit).
