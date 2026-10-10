---
title: "Sales Reports"
module: sales
pages: ["index"]
screenshots: ["sales-sales-report-index"]
lastVerified: 2026-10-07
---

# Sales Reports

<IerpNavigationPath :items='["Sales", "Reports", "Sales Reports"]' />

<IerpPageSummary purpose="Analyze Sales facts such as quotations, orders, invoicing and commercial performance without changing source transactions." useWhen="You need to work with sales reports records or follow their lifecycle." owner="Sales" />

## Overview

Analyze Sales facts such as quotations, orders, invoicing and commercial performance without changing source transactions.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Reports → Sales Reports**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Sales Reports list. Use it to search, filter, sort, open records and run the actions available to your role. |

## When to use this page

Use **Sales Reports** when your work belongs to this Sales responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Choose the business question from the Report selector and set the applicable From/To or As of date.
- Read the metrics and supporting tables, then drill into the owning source record when a value needs investigation.
- Export the currently selected report/date scope as CSV without changing source data.

## Verified operating behavior

- Sales Reports are read-only analytical surfaces. They do not create/edit Sales records and should never be used as a repair path for bad source data.
- The Report selector switches among nine report types. Most reports use From/To dates; Delivered Not Invoiced, Invoiced Not Collected, Returns Without Credit and Customer Revenue use an As of date instead.
- Quotation Funnel shows quotation volume by status. Win/Loss Analysis explains closed outcomes. Conversion Velocity measures time from sent quotation to decision/conversion.
- Delivered Not Invoiced identifies completed deliveries still missing an invoice; Invoiced Not Collected identifies issued receivables still outstanding.
- Tax Recognition Summary reports recognized sales tax. Discount and Floor Overrides audits approved pricing exceptions and their provenance.
- Returns Without Credit identifies posted customer returns that are not yet covered by a confirmed credit note. Customer Revenue compares invoiced and collected value by customer.
- Export Current downloads the currently selected report and filters as CSV. Export does not change any business record.
- When a value is wrong, correct the owning source transaction/workflow and rerun the report rather than editing the report output.

## List page

<IerpScreenshot id="sales-sales-report-index" title="Sales Reports — List page" description="The main Sales Reports list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Reports → Sales Reports" imageSrc="/images/en/sales/sales-sales-report-index.png" />

The main Sales Reports list. Use it to search, filter, sort, open records and run the actions available to your role.

### Choose the question first

The **Report** selector contains nine read-only views:

| Report | What it answers |
| --- | --- |
| Quotation Funnel | How quotation volume is distributed across statuses, employees and customers. |
| Win Loss Analysis | Closed opportunities, win/loss counts, win rate, owners and loss reasons. |
| Conversion Velocity | Median time from sent quotation to decision and from acceptance to order conversion. |
| Delivered Not Invoiced | Completed deliveries that still require an invoice. |
| Invoiced Not Collected | Issued receivables still outstanding, including ageing and control-account tie-out. |
| Tax Recognition Summary | Output/input tax recognition and reversal position for the reporting period. |
| Discount And Floor Overrides | Approved price-floor exceptions, approver, date, attempted/floor price and reason. |
| Returns Without Credit | Posted customer returns still missing a confirmed credit note. |
| Customer Revenue | Invoiced and collected revenue by customer. |

### Date behavior

Most reports use **From** and **To**. Delivered Not Invoiced, Invoiced Not Collected, Returns Without Credit and Customer Revenue use a single **As of** date instead. Clearing the filters removes the date restriction.

### Reading and exporting results

Metrics summarize the selected report; tables show the supporting rows and may link to the owning Customer, Inventory Return/Operation, Product Variant or other source record. Correct bad source data in that owning module.

**Export current report** downloads the selected report/date scope as CSV. It does not modify source records.

## Actions

- **Export current report** — downloads the selected report and current date scope as CSV. It does not change source records.

## Business effect

Sales Reports read source facts and export analysis only; correct discrepancies in the owning source workflow.

## When something is missing

- Your role may not have permission to view the underlying metric/report or source record.
- The selected filters or date scope may contain no matching data.
- Some dashboard widgets intentionally hide when there is no meaningful source data to show.

## Related workflows

- [Quote To Cash](/workflows/quote-to-cash)
- [Customer Return To Refund](/workflows/customer-return-to-refund)
- [Sales Shortage Procurement](/workflows/sales-shortage-procurement)
