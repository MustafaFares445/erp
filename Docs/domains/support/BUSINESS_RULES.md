# Support & Maintenance Business Rules

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: Support services/enums/permissions/tests
---

## Ticket State

Ticket statuses are Pending, Pending Payment, Live, Assigned, In Progress, Waiting Customer, Resolved, Closed and Cancelled.

Priority is Low/Normal/High/Urgent. Triage can resolve the service path as Remote Support, Maintenance or On-Site Visit.

Lifecycle, assignment, messages and SLA timing are separate services.

## SLA

SLA service reacts to ticket creation/live/waiting/resume/priority changes and maintains breach flags. Waiting-customer state can pause/resume SLA behavior through the domain service.

## Ticket Payment

Support owns the ticket payment-link/business relationship. Provider transaction execution belongs to Payments. Settlement is coordinated through dedicated ticket/provider settlement services.

## Maintenance

Maintenance status is Open, Diagnosing, Awaiting Approval, Ready For Repair, In Progress, Quality Assurance, Closed or Cancelled.

Maintenance may originate from a ticket or standalone request. Labour/third-party cost recording and billing classification use dedicated services.

Chargeable maintenance can create Sales-owned quotation/invoice documents rather than implementing a parallel billing document inside Support.

## Parts

Service-record parts are consumed/reversed through `ServiceRecordPartService`, which must integrate with Inventory-owned stock behavior.

## Warranty

Warranty status resolution is explicit. Entitlements use Pending Activation, Active, Ended or Cancelled states.

Warranty activation is linked to shipment fulfillment. Customer returns/replacements can end or replace entitlement through explicit services.

Warranty recovery is tracked separately through Draft/Submitted/Approved/Partially Received/Received/Rejected/Cancelled.

## Permissions

Support permissions separately cover ticket manage/assign/work/message/payment settlement, SLA, maintenance/service execution, parts consume/reverse, costs/billing, schedules, diagnosis, warranty decisions/override/policies/recovery, reports and audit.
