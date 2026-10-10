---
title: "Tickets"
module: support
pages: ["index", "create", "view", "edit"]
screenshots: ["support-ticket-index", "support-ticket-create", "support-ticket-view", "support-ticket-edit"]
lastVerified: 2026-10-07
---

# Tickets: customer case to resolution

<IerpPageSummary purpose="Track every customer issue from intake and payment-aware triage to ownership, SLA execution, service work, resolution and closure." useWhen="A customer reports a problem, needs technical support, or requests service." owner="Support and Maintenance" />

## When to use this page

A customer reports a problem, needs technical support, or requests service.

## Typical tasks

- Follow the documented “List and queues” instructions below.
- Follow the documented “Create a ticket” instructions below.
- Follow the documented “Case Workspace and stage actions” instructions below.

## When to use Tickets

Every customer issue starts as a case. Create a ticket with the correct **Customer**, **Type**, reported impact, proposed **Priority**, title and description; attach evidence only to the authorized private attachments area. Do not choose warranty coverage or invent a serial number during intake. Service path, customer equipment context and diagnostic fee belong to triage.

## List and queues

<IerpScreenshot id="support-ticket-index" title="Ticket queue" description="Search by number, customer, title or description; use stage, blocker, assignment and SLA to prioritize the next action." path="Support and Maintenance → Service Desk → Tickets" imageSrc="/images/en/support/support-ticket-index.png" />

Use the list tabs and filters to distinguish Pending/Payment, assigned work, SLA breach and resolved cases. Grouping, search and filter changes do not transition a ticket. A queue record may be visible to a reviewer even though its action is not permitted. Check **Workspace Stage**, **Blocked By**, **Next Action**, **Assigned Employee** and **SLA State** together; colour alone is not proof of readiness.

## Create a ticket

<IerpScreenshot id="support-ticket-create" title="Ticket intake" description="Record the actual customer complaint before triage; the impact suggestion does not force a priority." path="Tickets → Create" imageSrc="/images/en/support/support-ticket-create.png" />

1. Identify the right customer; check for an existing open case or related past ticket.
2. Choose ticket type and customer-reported impact. Confirm or override the proposed priority based on the facts.
3. Enter a specific title and useful reproduction details. Attach only allowed image/PDF evidence, and verify the customer/serial ownership before linking product-quality records.
4. Save. The new Pending ticket needs authorized triage; saving is not the same as assigning a worker.

## Case Workspace and stage actions

<IerpScreenshot id="support-ticket-view" title="Ticket case workspace" description="Review current stage, customer conversation, equipment, SLA, blocker and linked maintenance before taking a stage action." path="Tickets → View" imageSrc="/images/en/support/support-ticket-view.png" />

| Ticket stage | Main next action | Important rule |
| --- | --- | --- |
| Pending | Triage ticket | Choose equipment source, service path and whether a diagnostic fee is required. |
| Pending Payment | Settle Payment | Only an authorized settlement and valid payment reference permit the paid path to become Live. |
| Live | Assign Employee | Routing may suggest a team if enabled; confirm an actual eligible assignee before work. |
| Assigned | Start Work | Working as an agent requires assignment to that employee. |
| In Progress | Wait for Customer / Resolve Ticket / Raise Maintenance Request | Confirm the stage change and supply a resolution summary when resolving. |
| Waiting Customer | Resume Work or Resolve Ticket | Waiting affects SLA clocks; document what information is outstanding. |
| Resolved | Close Ticket or Reopen Ticket | Reopen only if work is truly incomplete; closing ends the active ticket. |
| Closed / Cancelled | No normal forward transition | Use a separate follow-on ticket or authorized restoration procedure; do not overwrite history. |

**Public reply vs. internal note:** a public support reply can establish the first-response SLA milestone; internal notes must not be shown to customers and do not satisfy first-response timing. Never paste sensitive internal cost or payment information into a public message. Check the conversation after sending.

**Confirmation:** lifecycle actions require confirmation. For **Resolve Ticket**, the resolution summary is mandatory. A dismissed dialog must leave the stage unchanged. When a transition fails, read the error and blocker instead of clicking repeatedly.

**Ticket payment:** a diagnostic-fee ticket may be blocked Pending Payment. **Settle Payment** is a privileged, financially consequential action; verify the method, real settlement evidence and unique reference before confirming. Support owns the ticket link, Payments owns provider transactions and Accounting owns ledger truth.

## Edit and audit

<IerpScreenshot id="support-ticket-edit" title="Edit permitted case facts" description="Change only fields authorized for the ticket’s current stage and your role; use case actions for status." path="Tickets → Edit" imageSrc="/images/en/support/support-ticket-edit.png" />

Direct edit is for case facts, not bypassing triage, assignment, payment or closure. System Admin alone can restore deleted records. Support Managers may manage and assign, but the Support Manager role does not inherit the dedicated settlement or restoration permission. Assigned Support Agents can work/message on their own tickets; Reviewers can view without mutation.

## When to raise maintenance

Raise a linked Maintenance Request from the case workspace for a maintenance or on-site service path. Diagnose the equipment and determine customer responsibility in that request; do not mark the ticket Resolved merely because a job was created. Later service execution, warranty coverage, parts consumption and chargeable Sales quotation/invoice are separate audited steps.

## Before closure

Check that the customer received the public resolution, linked service work is actually complete, rework and unpaid customer liability are addressed, and the SLA/resolution summary reflects what happened. A closed ticket is not automatically proof that an invoice was paid or a warranty claim was approved.

Related: [Maintenance Requests](/screens/support/maintenance-request), [Service Records](/screens/support/service-record), [Service Policies](/screens/support/sla-policy), [Warranty Policies](/screens/support/warranty-policy).
