---
title: "Interactions"
module: crm
pages: ["index"]
screenshots: ["crm-interaction-index"]
lastVerified: 2026-10-07
---

# CRM Interactions

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Record and investigate an interaction” instructions below.
- Follow the documented “Interaction log” instructions below.
- Follow the documented “Audit and boundary” instructions below.

<IerpNavigationPath :items='["CRM","Pipeline","Interactions"]' />

An Interaction is auditable evidence of a call, meeting, message or related contact with a lead or customer. Record the real date, direction, subject and outcome; interactions support CRM transitions but do not replace them.

## Record and investigate an interaction

1. Open **Interactions** and search for a customer, lead, summary, contact or date. Review **Type**, **Direction**, **Occurred at**, related party and employee.
2. Follow the linked lead or customer when context is missing. The interaction subject can be a potential customer before conversion or a registered customer afterward.
3. Record a new interaction through the relevant Lead/Customer workflow, with the actual communication details and optional outcome/notes. For a lead, use the dedicated **Log Interaction** action.
4. An eligible Lead status advance may be combined with interaction logging as **one atomic operation**. If the requested stage advance is invalid, neither the stage nor the attempted interaction should be committed.
5. During lead conversion, interaction context remains linked to the resulting customer so relationship history is not lost. Review the customer's timeline for subsequent activity.

## Interaction log

<IerpScreenshot id="crm-interaction-index" title="CRM contact and interaction history" description="Inspect the type, direction, subject, summary, employee and timestamp of recorded contact." path="CRM → Pipeline → Interactions" />

## Audit and boundary

An interaction is not itself a Sales quotation, campaign dispatch or financial posting. Do not manually fabricate activity to satisfy a qualification requirement. Permission and provenance determine which party records can be contacted or viewed.

Related: [Leads](/screens/crm/lead), [Customers](/screens/crm/customer), [Campaigns](/screens/crm/campaign).
