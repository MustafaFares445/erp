# Maintenance requests: diagnosis, coverage, work and billing

<IerpPageSummary purpose="Record a support-linked or standalone service request, decide warranty/customer responsibility, complete work and coordinate any chargeable Sales documents." useWhen="A case requires physical service, installation, calibration, preventive work, repair or other tracked maintenance." owner="Support and Maintenance" />

## When to use this page

A case requires physical service, installation, calibration, preventive work, repair or other tracked maintenance.

## Typical tasks

- Follow the documented “Scope and prerequisites” instructions below.
- Follow the documented “Queue and creation” instructions below.
- Follow the documented “Service assessment and warranty decision” instructions below.

## Scope and prerequisites

The canonical execution chain is **Ticket → Maintenance Request → Service Record**. A request may also be standalone. Check the customer, originating ticket, equipment/serial and ownership before opening a job. The maintenance request owns the diagnosis, coverage determination, work coordination, QA and closure. Inventory owns stock/custody; Sales owns quotations/invoices; Payments and Accounting own settlement/ledger effects.

## Queue and creation

<IerpScreenshot id="support-maintenance-request-index" title="Maintenance working queue" description="Compare active, awaiting approval, QA and closed jobs, plus the next decision and cost responsibility." path="Support and Maintenance → Maintenance Requests" />

Search and use status or customer filters to find a job. List search, sorting and grouping do not charge customers or move a job. **Awaiting Approval** needs special attention: the customer may owe a share of a partially covered job. A Closed request is not directly editable.

<IerpScreenshot id="support-maintenance-request-create" title="Create maintenance request" description="Use a linked ticket or explicit standalone service reason; verify the equipment and customer context before saving." path="Maintenance Requests → Create" />

When starting from a ticket, use its **Raise Maintenance Request** action to preserve traceability. For a standalone request, select the correct customer and describe the issue. Identify any serialized equipment from the customer's legitimate custody; do not change that custody inside Support.

## Service assessment and warranty decision

<IerpScreenshot id="support-maintenance-request-view" title="Maintenance assessment" description="Review diagnosis, warranty eligibility, coverage lines, customer cost and stage actions before committing to work." path="Maintenance Requests → View" />

1. Move to diagnosis according to the permitted stage transition and record the **Technician findings**, **Root cause** and **Failure category** using **Record Diagnosis**.
2. Check warranty activation, product/serial, coverage dates, service exclusions and any prior decisions. A customer statement alone does not prove warranty eligibility.
3. Use **Determine Coverage**. For every service/cost line, record the category, amount and **Covered %**; supply an internal reason and the separate explanation shown to the customer.
4. Review covered and customer-responsible totals before approval. **Fully covered**, **partially covered**, **goodwill** and **chargeable** are different financial decisions.
5. If customer approval or a Sales quotation is required, keep the job blocked at the appropriate stage rather than performing unapproved chargeable work.
6. Create service records, record technician work and actual labour/third-party costs. Parts consumption must go through the Inventory-owned movement service.
7. Verify repair quality and all pending work/rework before closure. Complete related installation/calibration evidence and return any loaner when relevant.

### Cost responsibility

| Decision | Operational meaning |
| --- | --- |
| Fully covered | Customer owes zero for the covered lines; service cost and supplier recovery can still exist. |
| Partially covered | Split each line according to the covered percentage; customer liability must be explained and approved. |
| Goodwill | Explicit business concession; do not mislabel it as an active warranty. |
| Chargeable | Create the permitted Sales quotation, obtain approval, then invoice/collect via the owning domains. |

**High-impact actions:** **Create Final Invoice**, **Settle Covered Repair**, **Reclassify Warranty Billing** and **Correct Warranty Information** can affect customer balances, audit history or eligibility. Read each confirmation and required reason; verify the resulting job state and linked Sales/Payment reference. Never invent payment settlement merely to close a job.

## Execution, installation and calibration

Service Records contain assigned tasks, execution stage, parts usage and evidence. Installation applies only to legitimate customer-held serialized equipment and the qualifying arrived shipment; checklist completion precedes commissioning, and passing commissioning precedes customer acceptance. Warranty trigger activation is the result of the appropriate actual milestone, not a manual assumption. Calibration is a separate kind: measured values, pass/fail result, certificate, technician and next due date must be recorded; failed calibration must not produce a successful certificate.

On-site appointments live in Field Service; technician conflicts, dispatch/check-in/out and customer sign-off are separate from maintenance request status. Preventive schedules generate due occurrences and link to actual requests; skipped or missed work must remain visible.

## Edit, correction and closure

<IerpScreenshot id="support-maintenance-request-edit" title="Edit eligible maintenance details" description="Editing is blocked after closure, cancellation or commercial billing; use authorized stage actions for subsequent workflow events." path="Maintenance Requests → Edit" />

A commercially billed, Closed or Cancelled maintenance request is locked for direct detail edits even when a role may transition a stage elsewhere. Do not bypass this by changing dates or amounts. Use explicit correction, reclassification, new service work or an authorized audit trail as appropriate. Restoration belongs only to System Admin.

At closure, check customer communication, coverage approval, QA evidence, service tasks, parts, loaner/RMA completion and the Sales/payment handoff. **Closed** is a service state, not proof of posted revenue or bank settlement.

Related: [Tickets](/screens/support/ticket), [Service Records](/screens/support/service-record), [Warranty Policies](/screens/support/warranty-policy), [Equipment 360](/screens/support/support-equipment), [Maintenance Schedules](/screens/support/maintenance-schedule).
