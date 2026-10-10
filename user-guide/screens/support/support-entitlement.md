---
title: "Support Entitlements"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-support-entitlement-index", "support-support-entitlement-create", "support-support-entitlement-edit"]
lastVerified: 2026-10-07
---

# Support entitlements: who receives which service tier

<IerpPageSummary purpose="Grant a customer or specific delivered unit a service level for a valid date range with traceable contract evidence." useWhen="The Support team must verify whether a case qualifies for the purchased support tier." owner="Support and Maintenance" />

## When to use this page

The Support team must verify whether a case qualifies for the purchased support tier.

## Typical tasks

- Follow the documented “Scope is explicit” instructions below.
- Follow the documented “Create with custody validation” instructions below.
- Follow the documented “Edit and revoke” instructions below.

## Scope is explicit

A Support Entitlement links a customer to an active service level, a status and start/end dates. Leave the equipment field empty to grant the **whole customer**; select one serial to limit the grant to **that specific unit**. This is distinct from product warranty: a service entitlement does not by itself make repair parts free.

<IerpScreenshot id="support-support-entitlement-index" title="Support entitlements" description="Review customer, tier, equipment scope and dates before evaluating an SLA." path="Service Policies → Support Entitlements" />

## Create with custody validation

<IerpScreenshot id="support-support-entitlement-create" title="Create customer entitlement" description="Confirm customer, active service tier, eligible delivered serial and contract dates." path="Support Entitlements → Create" imageSrc="/images/en/support/support-support-entitlement-create.png" />

1. Choose the correct customer and active level of service.
2. If the grant is equipment-specific, select only a serial **currently in that customer's custody**. The application validates this; do not bypass it by entering IDs manually.
3. Choose status and start date; add an end date after the start date or leave it open-ended where the agreement permits.
4. Save an external contract/order reference and supporting notes for later audit.
5. Verify the applied ticket SLA after entitlement resolution; do not confuse the granted tier with a warranty coverage decision.

## Edit and revoke

<IerpScreenshot id="support-support-entitlement-edit" title="Edit an entitlement" description="Review contract evidence, effective dates and specific customer/serial scope before changes." path="Support Entitlements → Edit" imageSrc="/images/en/support/support-support-entitlement-edit.png" />

Changing status or dates can affect future service commitments. Do not retroactively erase old ticket SLA commitments. Transferring equipment to a different customer is an Inventory custody process and may require entitlement reassessment; do not use this form to fabricate transfer history.

Related: [Service Levels](/screens/support/support-service-level), [SLA Policies](/screens/support/sla-policy), [Equipment 360](/screens/support/support-equipment).
