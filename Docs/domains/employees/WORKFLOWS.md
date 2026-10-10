# Employees Workflows

---
status: canonical
owner: employees
last_verified: 2026-10-07
verified_against: employee services/tests
---

## Monthly Planning

`Employee -> Draft Sales Plan -> tasks -> Active -> execution -> Completed/Archived`

Plans can be duplicated to another employee/month through the dedicated duplication service.

## Task / Visit

`Plan Task Pending -> In Progress -> Completed/Cancelled`

Visits use `Scheduled -> En Route -> In Progress -> Completed`, with Unable to Complete / Cancelled terminal alternatives, and track GPS/media/review facts. A customer-owned serialized unit may be attached as visit context only when custody belongs to that customer.

When follow-up is required, `FollowUpCreationService` creates or reuses one follow-up task for the visit and carries available visit/opportunity/quotation/order/equipment provenance into that task.

## Employee Sales Action

The employee experience remains centered on `Today -> Visit -> Customer -> Product / Sales Action -> Outcome -> Follow-Up -> Complete`.

Employee-authored quotations delegate customer price-list resolution, quantity breaks and sales-UOM/packaging conversion to backend services, and preserve pricing provenance downstream. Employees do not bypass Sales Order creation: the commercial lifecycle remains `Quotation -> Sales Order -> Delivery / fulfillment -> Invoice -> Payment`. Supplier cost, replenishment configuration, lot selection, serial assignment, FEFO, picking and inventory posting stay outside the normal Employee workflow and remain backend/warehouse responsibilities.

## Voice / AI

`Visit -> Voice Note intake -> queued transcription -> transcript -> keyword detection -> Draft opportunity -> human review -> Approved/Rejected`

The transcriber is replaceable; failure is recorded rather than allowed to corrupt visit state. Only Approved opportunities contribute to the performance opportunity factor.

## Performance / Salary

`Plan execution facts -> performance score -> salary calculation/recalculation -> Pending Confirmation -> Confirmed`

Recalculation creates/supersedes calculation state through its services rather than rewriting confirmed history silently.
