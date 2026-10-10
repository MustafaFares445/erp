---
title: "Reports & Analytics"
module: reports
pages: ["overview"]
screenshots: ["reports-reports-center-overview"]
lastVerified: 2026-10-07
---

# Reports & Analytics

<IerpNavigationPath :items='["Reports", "Reports & Analytics"]' />

<IerpPageSummary purpose="Use the Reports Center as the cross-module entry point for operational/financial analysis while keeping source corrections in their owning modules." useWhen="You need to work with reports & analytics records or follow their lifecycle." owner="Reports" />

## Overview

Use the Reports Center as the cross-module entry point for operational/financial analysis while keeping source corrections in their owning modules.

Reports provides consolidated operational and audit views. Reports explain business facts; corrections must be made in the module that owns the source record.

## How to open it

Use the dashboard module switcher and follow **Reports → Reports & Analytics**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| Overview | The overview view for Reports & Analytics. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change. |

## When to use this page

Use **Reports & Analytics** when your work belongs to this Reports responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Use the page as the module's working surface and follow the visible actions/links to the owning record.

## Overview

<IerpScreenshot id="reports-reports-center-overview" title="Reports & Analytics — Overview" description="The overview view for Reports & Analytics. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change." path="Reports → Reports & Analytics" imageSrc="/images/en/reports/reports-reports-center-overview.png" />

The overview view for Reports & Analytics. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change.

## Actions and lifecycle controls

Available actions are provided by the page and record state. If an expected action is missing, check the status and your permissions.

## Business effect

Reports do not mutate source records. Resolve discrepancies in the owning module.

## Common reasons an action is unavailable

- Your role does not have the required permission.
- The record is not in the status required by that action.
- A prerequisite approval, source document or configuration is missing.
- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.
- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.

## Related workflows

- [Core business workflows](/workflows/)
