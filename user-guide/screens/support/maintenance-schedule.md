---
title: "Maintenance Schedules"
module: support
pages: ["index", "create", "calendar", "view", "edit"]
screenshots: ["support-maintenance-schedule-index", "support-maintenance-schedule-create", "support-maintenance-schedule-calendar", "support-maintenance-schedule-view", "support-maintenance-schedule-edit"]
lastVerified: 2026-10-07
---

# Maintenance schedules: due work and occurrences

<IerpPageSummary purpose="Plan recurring preventive service, inspection or calibration and track generated work through a linked maintenance request." useWhen="Equipment needs scheduled service with recorded due dates and accountable follow-up." owner="Support and Maintenance" />

## When to use this page

Equipment needs scheduled service with recorded due dates and accountable follow-up.

## Typical tasks

- Follow the documented “Understand scheduled versus completed work” instructions below.
- Follow the documented “Create a recurring service rule” instructions below.
- Follow the documented “Calendar, status and generation” instructions below.

## Understand scheduled versus completed work

A Maintenance Schedule defines future work for a customer's eligible equipment. Generating an occurrence means **work is due or raised**, not that a technician has serviced the device. A linked Maintenance Request records actual diagnosis, work, warranty decisions and closure.

<IerpScreenshot id="support-maintenance-schedule-index" title="Maintenance schedules" description="Review active recurrence rules and due status across customer equipment." path="Support and Maintenance → Maintenance Schedules" />

Search by equipment, customer and active status; pay attention to overdue and missed occurrences. Changing a filter does not generate service work or change a customer's bill.

## Create a recurring service rule

<IerpScreenshot id="support-maintenance-schedule-create" title="New recurring schedule" description="Select legitimately delivered equipment, schedule kind, interval, first due date and billing responsibility." path="Maintenance Schedules → Create" imageSrc="/images/en/support/support-maintenance-schedule-create.png" />

Confirm the equipment and its current customer custody. Choose **Preventive**, **Inspection** or **Calibration** as the work kind. Set recurrence interval, first due date, lead time and intended billing classification. Validate warranty eligibility at each actual service request rather than assuming future coverage.

## Calendar, status and generation

<IerpScreenshot id="support-maintenance-schedule-calendar" title="Schedule calendar" description="Review planned visits and upcoming occurrences without interpreting them as completed work." path="Maintenance Schedules → Calendar" />

The generation command raises due occurrences and, where applicable, linked maintenance requests. Keep missed, skipped, cancelled and completed occurrences distinguishable. A calibration schedule raises a calibration-specific request and notification. Avoid duplicate requests by checking previously generated occurrences.

<IerpScreenshot id="support-maintenance-schedule-view" title="Schedule details and occurrences" description="Inspect the recurrence rule and its linked maintenance work before rescheduling or pausing." path="Maintenance Schedules → View" imageSrc="/images/en/support/support-maintenance-schedule-view.png" />

## Edit and downstream billing

<IerpScreenshot id="support-maintenance-schedule-edit" title="Edit an eligible schedule" description="Correct future recurrence terms while preserving past service evidence and generated history." path="Maintenance Schedules → Edit" imageSrc="/images/en/support/support-maintenance-schedule-edit.png" />

A change to dates or work type may affect future reminders, customer expectations and technician capacity. Document why a schedule is paused or rescheduled; do not hide an overdue service by silently shifting its due date. Actual field appointment dispatch and customer sign-off are separate Field Service operations.

**Financial consequence:** scheduled billing type is a planning classification. Partially covered or chargeable work requires diagnosis, coverage decisions, authorization and the Sales/Payment path on the real maintenance request. Generating a schedule is not an invoice.

Related: [Maintenance Requests](/screens/support/maintenance-request), [Service Records](/screens/support/service-record), [Equipment 360](/screens/support/support-equipment).
