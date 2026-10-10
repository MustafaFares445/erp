# Warranty policies and coverage decisions

<IerpPageSummary purpose="Configure manufacturer/offer warranty terms for eligible products, and understand how actual equipment warranty eligibility drives service coverage." useWhen="Support Managers maintain warranty rules or review whether a service job may be covered." owner="Support and Maintenance" />

## When to use this page

Support Managers maintain warranty rules or review whether a service job may be covered.

## Typical tasks

- Follow the documented “Policy configuration is not a claim decision” instructions below.
- Follow the documented “Create or edit warranty terms” instructions below.
- Follow the documented “From policy to service decision” instructions below.

## Policy configuration is not a claim decision

A **Warranty Policy** defines eligibility and duration/terms. It does not itself confirm a customer's delivered serial, approve a service claim, pay a supplier or create a repair invoice. Actual serial warranty activation follows the applicable shipment arrival or installation/commissioning milestone and the configured trigger. Check Equipment 360 and the Maintenance Request before communicating coverage to the customer.

<IerpScreenshot id="support-warranty-policy-index" title="Warranty policy catalog" description="Compare active policy terms and applicability before assigning or changing rules." path="Support and Maintenance → Service Policies → Warranty Policies" />

Use search and view to check which policy controls a product. Do not change an active rule casually: new or future entitlement resolutions may depend on its effective terms. Existing auditable warranty snapshots should not be silently rewritten.

## Create or edit warranty terms

<IerpScreenshot id="support-warranty-policy-create" title="New warranty policy" description="Define the eligible product/variant and term, including the correct activation trigger." path="Warranty Policies → Create" imageSrc="/images/en/support/support-warranty-policy-create.png" />

Enter specific terms, supported equipment scope, duration and activation trigger; verify that legal/service wording matches the agreed sale. Prefer an explicit versioned policy change over an unexplained edit when terms may affect customer rights.

<IerpScreenshot id="support-warranty-policy-view" title="Review warranty policy" description="Read the rule and applicability before changing coverage outcomes on an individual maintenance case." path="Warranty Policies → View" imageSrc="/images/en/support/support-warranty-policy-view.png" />

<IerpScreenshot id="support-warranty-policy-edit" title="Edit warranty policy" description="Check the effect on future service and existing entitlement evidence before saving changes." path="Warranty Policies → Edit" imageSrc="/images/en/support/support-warranty-policy-edit.png" />

## From policy to service decision

1. Confirm the customer's equipment, serial and custody, shipment arrival/installation evidence and applicable activation date.
2. Inspect entitlement end date, product-specific conditions, exclusions and relevant prior maintenance.
3. **Record Diagnosis** on the maintenance request with technician findings and root cause.
4. Use **Determine Coverage** in the maintenance workspace to decide full/partial/no coverage, goodwill and individual covered percentages. Record the internal reason and the customer explanation.
5. If a claim is covered, close the cost classification correctly. Supplier recovery can be tracked separately and does not automatically equal customer revenue.
6. If partially covered or chargeable, confirm customer-owned service amounts and the required quotation, approval, invoice and payment workflow before executing billable work.

## High-impact changes and corrections

**Correct Warranty Information** or **Reclassify Warranty Billing** changes eligibility or financial responsibility and requires authorized justification. Read the confirmation, preserve the previous audit trail and confirm visible coverage totals afterward. A policy editor cannot use this screen to override a closed maintenance request, backdate customer entitlement without evidence or change Inventory custody.

Only Support users with the relevant view/manage permissions see policy operations; determining coverage, overriding warranty, recording recovery and billing have distinct abilities. Reviewers cannot mutate policy data.

Related: [Maintenance Requests](/screens/support/maintenance-request), [Equipment 360](/screens/support/support-equipment), [Tickets](/screens/support/ticket).
