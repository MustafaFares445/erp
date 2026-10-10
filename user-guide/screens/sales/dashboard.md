---
title: "Sales Dashboard"
module: sales
pages: ["overview"]
screenshots: ["sales-dashboard-overview"]
lastVerified: 2026-10-07
---

# Sales Dashboard

<IerpNavigationPath :items='["Sales", "Sales Dashboard"]' />

<IerpPageSummary purpose="Use the Sales Dashboard to understand current selling activity, customer/salesperson performance, commercial queues and the records that need attention before opening detailed Sales pages." useWhen="You need to work with sales dashboard records or follow their lifecycle." owner="Sales" />

## Overview

Use the Sales Dashboard to understand current selling activity, customer/salesperson performance, commercial queues and the records that need attention before opening detailed Sales pages.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Sales Dashboard**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| Overview | The overview view for Sales Dashboard. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change. |

## When to use this page

Use **Sales Dashboard** when your work belongs to this Sales responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Change Date range, Salesperson or Customer to focus the dashboard without changing source records.
- Use KPI/funnel/trend widgets to understand commercial performance, then open the source record for follow-up.
- Use Requires Attention as the current-state work queue even when you are reviewing a historical date period.

## Verified operating behavior

- The date-range filter controls period-based Sales metrics; Salesperson and Customer narrow the same dashboard to the selected commercial owner/customer. Reset Filters restores the dashboard defaults.
- The canonical Sales value on this dashboard is confirmed order value, not invoiced revenue or collected cash. It sums orders that are Confirmed, Released or Closed and dates them by confirmation time.
- Salesperson attribution comes from the quotation's employee assignment. A direct order without a source quotation is not silently attributed from the order's logistics/responsible user.
- KPI cards show confirmed sales value, confirmed order count, average order value and quotation conversion. The sales performance chart compares the selected period with an equal-length previous period.
- The funnel follows Quotations → Accepted → Orders → Delivered → Invoiced. It is a commercial progression view, not a stock or cash ledger.
- Requires Attention is a current-state work queue. Its items are intentionally not limited by the selected date period, although Customer/Salesperson filters still apply.
- Top Customers and Top Products rank confirmed order value. Salesperson Performance can be hidden when no quotation carries salesperson ownership data.

## Overview

<IerpScreenshot id="sales-dashboard-overview" title="Sales Dashboard — Overview" description="The overview view for Sales Dashboard. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change." path="Sales → Sales Dashboard" imageSrc="/images/en/sales/sales-dashboard-overview.png" />

The overview view for Sales Dashboard. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change.

### Dashboard filters

Use **Date range** to choose the reporting period. **Salesperson** narrows quotation-owned sales activity to that commercial owner, while **Customer** narrows the dashboard to one customer. **Reset filters** restores the default dashboard scope.

The filters change what you are looking at; they do not edit Sales records. Requires Attention remains a current-state queue rather than a purely date-bounded report, although Customer/Salesperson filters still apply.

### Reading the widgets

Confirmed sales value/count and average order value come from confirmed commercial orders. Quotation conversion and the funnel describe commercial progression. Top Customers/Products rank confirmed order value, while Salesperson Performance depends on quotation salesperson ownership. Open the source records from the widgets when follow-up is required.

## Dashboard widgets and queues

The dashboard is not only a summary page. Its widgets combine headline metrics, trends and operational queues:

- **Sales Kpi Cards** — Shows confirmed order value, confirmed order count, average order value and quotation conversion for the active period. The first two cards also compare against the equal-length previous period.
- **Sales Performance Chart** — Plots confirmed order value for the selected period against the equal-length previous period; the bucket size changes with the selected period.
- **Top Products Chart** — Ranks products by confirmed order value inside the active dashboard filters.
- **Top Customers Widget** — Ranks customers by confirmed order value and shows order count plus average order value.
- **Salesperson Performance Widget** — Shows quotation-owned salesperson performance, including quotation count/conversion, converted order count and confirmed order value. It is hidden when no quotation carries salesperson ownership.
- **Sales Funnel Widget** — Shows quotation → accepted → order → delivered → invoiced counts/values and stage-to-stage conversion percentages.
- **Quotation Performance Widget** — Summarizes open, awaiting-decision, accepted and rejected/expired quotation segments together with conversion rate and median decision time.
- **Requires Attention Widget** — Lists current Sales bottlenecks that require action. It is intentionally current-state rather than period-bounded, while Customer/Salesperson filters still apply.
- **Recent Sales Activity Widget** — Shows recent Sales events so the operator can open the source record and understand what changed.

Dashboard filters apply to the widgets that are designed to use them. Open the linked source record when you need to change business state.

## Actions

Dashboard controls filter or navigate to source records. They do not change commercial, stock or accounting state.

## Business effect

The Sales Dashboard reads and links to source facts only. Filtering or opening a widget does not change business state.

## When something is missing

- Your role may not have permission to view the underlying metric/report or source record.
- The selected filters or date scope may contain no matching data.
- Some dashboard widgets intentionally hide when there is no meaningful source data to show.

## Related workflows

- [Quote To Cash](/workflows/quote-to-cash)
- [Customer Return To Refund](/workflows/customer-return-to-refund)
- [Sales Shortage Procurement](/workflows/sales-shortage-procurement)
