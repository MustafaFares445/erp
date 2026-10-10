---
title: "Return Requests"
module: crm
pages: ["index", "create", "view"]
screenshots: ["crm-customer-return-request-index", "crm-customer-return-request-create", "crm-customer-return-request-view"]
lastVerified: 2026-10-07
---

# Customer Return Requests

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Process” instructions below.
- Follow the documented “Working queue” instructions below.
- Follow the documented “Create” instructions below.

<IerpNavigationPath :items='["CRM","Customers","Return Requests"]' />

CRM records the customer's return request and controls its review. Physical receiving, inspection, disposition and stock changes belong to **Inventory**; financial credits and refunds follow their separate owning workflows.

## Process

1. Find the customer and completed outbound Inventory operation. A return must refer to the actual delivery provenance.
2. In **Create**, select the customer and original Inventory operation, then add original operation lines and requested quantities. Record the return reason and any customer notes.
3. On the **List**, filter by status and inspect request number, customer, source operation, item count and resulting Inventory Return.
4. Use **Start Review** to assess quantities and supporting evidence. Resolve missing source documentation before approval.
5. Use **Approve And Convert** only when eligible. This creates a **Draft Inventory Return**, not an immediately posted inbound stock movement. Use **Retry Convert** only where the controlled conversion needs repeating.
6. Use **Reject Return Request** to preserve the rejected request and its review reason. Follow actual return receipt and any credit/refund through Inventory and Finance.

## Working queue

<IerpScreenshot id="crm-customer-return-request-index" title="Return request queue" description="Find submitted and reviewed requests with their original delivery references." path="CRM → Customers → Return Requests" />

## Create

<IerpScreenshot id="crm-customer-return-request-create" title="Return request source and lines" description="Select an original delivery and specify the original lines and requested return quantities." path="CRM → Customers → Return Requests → Create" imageSrc="/images/en/crm/crm-customer-return-request-create.png" />

## Review and conversion

<IerpScreenshot id="crm-customer-return-request-view" title="Return request decision" description="Review status, source evidence, submitted quantities, review notes and any linked draft Inventory Return." path="CRM → Customers → Return Requests → View" />

**Statuses:** Submitted, Under Review, Approved, Rejected, Converted and Cancelled. Available actions depend on permission and current state. Approval or conversion here is not proof of physical receipt, a credit note or a refund.

Related: [Inventory Returns](/screens/inventory/return), [Customer Timeline](/screens/crm/customer).
