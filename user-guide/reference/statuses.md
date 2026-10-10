---
title: Status & Transition Reference
lastVerified: 2026-10-07
---

# Status & Transition Reference

This page is a user-facing navigation reference. Exact transition guards still depend on the record, permissions and prerequisite work.

## Commercial and fulfillment records

| Record | Current status vocabulary |
| --- | --- |
| Quotation | Draft, Sent, Accepted, Rejected, Expired, Converted to Order, Cancelled, Changes Requested |
| Sales Order | Draft, Confirmed, Released, Closed, Cancelled |
| Shipment | Planned, In Transit, Arrived, Cancelled |
| Invoice document | Draft, Issued, Sent, Written Off, Cancelled |
| Invoice financial | Not Payable Yet, Unpaid, Partially Paid, Paid, Credited, Overdue |
| Credit Note | Draft, Confirmed, Reversed, Cancelled |
| Payment | Draft, Posted, Reversed |
| Provider Payment Transaction | Pending, Requires Action, Succeeded, Failed, Cancelled, Partially Refunded, Refunded |
| Refund | Draft, Approved, Paid, Cancelled |

## Purchasing and supplier records

| Record | Current status vocabulary |
| --- | --- |
| Purchase Order | Draft, Pending Approval, Accepted, Rejected, Partially Received, Received, Closed, Cancelled |
| Purchase Inbound | Awaiting Allocation, Awaiting Receipt, Partially Received, Received, Cancelled |
| Bill | Draft, Approved, Partially Paid, Paid, Cancelled |
| Supplier Payment | Draft, Paid, Cancelled |

## Inventory

| Record | Current status vocabulary |
| --- | --- |
| Inventory Operation | Draft, Waiting, Ready, In Transit, Partially Received, Done, Canceled |
| Inventory Count | Draft, Counting, Pending Review, Confirmed, Cancelled |
| Inventory Return | Draft, Ready, Posted, Cancelled |
| Inventory Correction | Draft, Posted, Cancelled |

## CRM and employees

| Record | Current status vocabulary |
| --- | --- |
| Customer Approval | Pending, Changes Requested, Approved, Rejected |
| Customer Quotation Request | Submitted, Under Review, Quoted, Rejected, Cancelled |
| Customer Return Request | Submitted, Under Review, Approved, Rejected, Converted, Cancelled |
| Lead | New, Contacted, Qualified, Converted, Disqualified |
| Opportunity | Qualification, Needs Analysis, Demo, Proposal, Negotiation, Closed Won, Closed Lost |
| Campaign | Draft, Scheduled, Sending, Completed, Failed, Cancelled |
| Employee Sales Plan | Draft, Active, Paused, Completed, Archived |
| Plan Task | Pending, In Progress, Completed, Cancelled |
| Visit | Scheduled, En Route, In Progress, Completed, Unable to Complete, Cancelled |
| Employee Sales Opportunity Review | Draft, Approved (labelled Accepted), Rejected |
| Voice Note | Pending, Processing, Transcribed, Failed |
| Salary Calculation | Draft, Pending Confirmation, Confirmed, Superseded |

## Support & maintenance

| Record | Current status vocabulary |
| --- | --- |
| Support Ticket | Pending, Pending Payment, Live, Assigned, In Progress, Waiting Customer, Resolved, Closed, Cancelled |
| Support Entitlement | Active, Suspended, Expired, Cancelled |
| Service Appointment | Planned, Dispatched, En Route, On Site, Completed, Cancelled |
| Knowledge Article | Draft, Published, Archived |
| Maintenance | Open, Diagnosing, Awaiting Approval, Ready For Repair, In Progress, Quality Assurance, Closed, Cancelled |
| Warranty Entitlement | Pending Activation, Active, Ended, Cancelled |
| Warranty Recovery | Draft, Submitted, Approved, Partially Received, Received, Rejected, Cancelled |

## Accounting and system

| Record | Current status vocabulary |
| --- | --- |
| Journal Entry | Draft, Posted |
| Expense | Draft, Approved, Paid, Cancelled |
| Notification Delivery | Queued, Sent, Failed, Suppressed, Bounced |

::: warning
A status name does not grant permission to perform the next action. Use the actions shown on the record; they apply the real guards and downstream effects.
:::
