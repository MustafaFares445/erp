# CRM Business Rules

---
status: canonical
owner: crm
last_verified: 2026-10-02
verified_against: CRM services/enums/permissions/tests
---

## Customer Approval

Customer approval states are Pending, Changes Requested, Approved and Rejected.

Profile changes use explicit change requests with Pending, Approved, Rejected and Cancelled states rather than silently overwriting review-controlled data.

## Leads

Lead statuses are New, Contacted, Qualified, Converted and Disqualified.

Lead transitions are explicit and interaction-aware. Conversion creates customer context through the CRM onboarding/provisioning path.

## Customer Quotation Requests

States are Submitted, Under Review, Quoted, Rejected and Cancelled.

Conversion into a quotation calls the Sales-owned quotation flow.

## Customer Return Requests

States are Submitted, Under Review, Approved, Rejected, Converted and Cancelled.

Conversion creates an Inventory-owned return rather than changing stock inside CRM.

## Campaigns

Campaign states are Draft, Scheduled, Sending, Completed, Failed and Cancelled. Recipient send state is tracked separately as Pending/Sent/Failed/Suppressed.

Campaign dispatch uses the Notifications domain for delivery.

## Customer Timeline

Timeline/summary may combine CRM events with Accounts Receivable context, but Accounting remains owner of receivable facts.
