# Customers

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Daily workflow” instructions below.
- Follow the documented “Customers queue” instructions below.
- Follow the documented “Onboard a customer” instructions below.

<IerpNavigationPath :items='["CRM","Customers","Customers"]' />

Manage customer identity, commercial defaults and onboarding approvals from the CRM master record. Related Sales, Inventory, Support and Accounting histories are links to the owning workflows, not editable copies of those facts.

## Daily workflow

1. Open **Customers** and search by code, company or linked account. Use the tabs **Default**, **Pending approval**, **Approved**, **Inactive**, **Starred** and saved views to focus work.
2. When **Creating**, verify company and contact identity, customer type/group, default currency, active price list, payment terms, assigned salesperson and billing/tax information. The price list must have the same currency as the customer's default currency.
3. Inspect approval state **Pending**, **Changes Requested**, **Approved** or **Rejected**. Use explicit approval and change-request actions; sensitive profile updates must not silently bypass review.
4. In **View**, check account activity, owner and related Sales quotations/orders, invoices/payments, Equipment, Support tickets and Visits.
5. Use **Timeline** to reconstruct communication and commercial/service events. Receivables may appear as context, but Accounting owns their balances.
6. Use **Edit** only where allowed. Customer groups must be active and the assigned salesperson must be an eligible Employee account.

## Customers queue

<IerpScreenshot id="crm-customer-index" title="Customer master list" description="Search, group and filter customer records including approval and activity status." path="CRM → Customers" />

## Onboard a customer

<IerpScreenshot id="crm-customer-create" title="Customer registration and commercial defaults" description="Record identity, account, pricing currency and required customer information." path="CRM → Customers → Create" />

## Master detail and linked work

<IerpScreenshot id="crm-customer-view" title="Customer details" description="Review the approved customer context and links to owning operational records." path="CRM → Customers → View" />

## Controlled changes

<IerpScreenshot id="crm-customer-edit" title="Customer profile amendment" description="Edit eligible fields without overriding the formal approval process." path="CRM → Customers → Edit" />

## Customer timeline

<IerpScreenshot id="crm-customer-timeline" title="Customer activity timeline" description="Trace contact, Sales, service and billing context in chronological order." path="CRM → Customers → Timeline" />

If a customer is missing from a selector, verify approval/active status, currency compatibility and access permissions. Do not create duplicate customer profiles to bypass a review hold.

Related: [Quote Requests](/screens/crm/customer-quotation-request), [Return Requests](/screens/crm/customer-return-request).
