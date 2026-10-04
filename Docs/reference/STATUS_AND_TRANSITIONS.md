# Status and Transition Reference

---
status: canonical
owner: product
last_verified: 2026-10-04
verified_against: current enum cases and canonical domain docs
---

This is a navigation reference, not a replacement for service guards. The owning service/enums/tests define exact allowed transitions.

| Aggregate | Current status vocabulary |
|---|---|
| Inventory Operation | Draft, Waiting, Ready, In Transit, Partially Received, Done, Canceled |
| Inventory Count | Draft, Counting, Pending Review, Confirmed, Cancelled |
| Inventory Return | Draft, Ready, Posted, Cancelled |
| Inventory Correction | Draft, Posted, Cancelled |
| Purchase Order | Draft, Pending Approval, Accepted, Rejected, Partially Received, Received, Closed, Cancelled |
| Purchase Inbound | Awaiting Allocation, Awaiting Receipt, Partially Received, Received, Cancelled |
| Quotation | Draft, Sent, Accepted, Rejected, Expired, Converted to Delivery, Cancelled, Changes Requested |
| Sales Order | Draft, Confirmed, Released, Closed, Cancelled |
| Invoice document | Draft, Issued, Sent, Written Off, Cancelled |
| Invoice financial | Not Payable Yet, Unpaid, Partially Paid, Paid, Credited, Overdue |
| Credit Note | Draft, Confirmed, Reversed, Cancelled |
| Payment | Draft, Posted, Reversed |
| Provider Payment Transaction | Pending, Requires Action, Succeeded, Failed, Cancelled, Partially Refunded, Refunded |
| Refund | Draft, Approved, Paid, Cancelled |
| Journal Entry | Draft, Posted |
| Bill | Draft, Approved, Partially Paid, Paid, Cancelled |
| Expense | Draft, Approved, Paid, Cancelled |
| Supplier Payment | Draft, Paid, Cancelled |
| Shipment | Planned, In Transit, Arrived, Cancelled |
| Customer Approval | Pending, Changes Requested, Approved, Rejected |
| Customer Quotation Request | Submitted, Under Review, Quoted, Rejected, Cancelled |
| Customer Return Request | Submitted, Under Review, Approved, Rejected, Converted, Cancelled |
| Lead | New, Contacted, Qualified, Converted, Disqualified |
| Opportunity | Qualification, Needs Analysis, Demo, Proposal, Negotiation, Closed Won, Closed Lost |
| Campaign | Draft, Scheduled, Sending, Completed, Failed, Cancelled |
| Employee Sales Plan | Draft, Active, Paused, Completed, Archived |
| Plan Task | Pending, In Progress, Completed, Cancelled |
| Visit | Planned, In Progress, Completed, Missed |
| Voice Note | Pending, Processing, Transcribed, Failed |
| Salary Calculation | Draft, Pending Confirmation, Confirmed, Superseded |
| Support Ticket | Pending, Pending Payment, Live, Assigned, In Progress, Waiting Customer, Resolved, Closed, Cancelled |
| Support Entitlement | Active, Suspended, Expired, Cancelled |
| Service Appointment | Planned, Dispatched, En Route, On Site, Completed, Cancelled |
| Knowledge Article | Draft, Published, Archived |
| Maintenance | Open, Diagnosing, Awaiting Approval, Ready For Repair, In Progress, Quality Assurance, Closed, Cancelled |
| Warranty Entitlement | Pending Activation, Active, Ended, Cancelled |
| Warranty Recovery | Draft, Submitted, Approved, Partially Received, Received, Rejected, Cancelled |
| Notification Delivery | Queued, Sent, Failed, Suppressed, Bounced |

For exact guards and side effects, follow the relevant domain `BUSINESS_RULES.md` / `WORKFLOWS.md`.
