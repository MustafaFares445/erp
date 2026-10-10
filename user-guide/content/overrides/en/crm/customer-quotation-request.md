# Customer Quotation Requests

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Request-to-quotation workflow” instructions below.
- Follow the documented “Incoming requests” instructions below.
- Follow the documented “Create request” instructions below.

<IerpNavigationPath :items='["CRM","Customers","Quote Requests"]' />

Record customer demand for a quotation and review it before handing the accepted request to the Sales quotation lifecycle. The request is a CRM intake record, not itself a priced quotation or order.

## Request-to-quotation workflow

1. In **Quote Requests**, find submissions by request reference, customer or status. Read the requested products/quantities and original customer comments.
2. Use **Create** to select an eligible customer, delivery address when relevant, and requested product lines, then add the explanatory notes.
3. Review the submitted request against customer eligibility and the products requested. Statuses are **Submitted**, **Under Review**, **Quoted**, **Rejected** and **Cancelled**.
4. Continue with the dedicated review/quotation actions when they are visible for the current status and role. Converting an eligible request invokes the **Sales-owned quotation service**, which determines pricing, currency and subsequent approval rules.
5. Open the resulting quotation in **Sales** to handle sending, approval, order creation or later fulfillment; avoid treating the CRM request as a completed sale.
6. When rejected or cancelled, preserve the original request and reason for auditing; do not delete evidence to make the queue appear clean.

## Incoming requests

<IerpScreenshot id="crm-customer-quotation-request-index" title="Customer quotation request queue" description="Locate submitted, reviewed and quoted requests and any linked Sales quotation." path="CRM → Customers → Quote Requests" imageSrc="/images/en/crm/crm-customer-quotation-request-index.png" />

## Create request

<IerpScreenshot id="crm-customer-quotation-request-create" title="Quote request details and product lines" description="Choose customer, delivery details and the products and quantities requested." path="CRM → Customers → Quote Requests → Create" imageSrc="/images/en/crm/crm-customer-quotation-request-create.png" />

## Review request

<IerpScreenshot id="crm-customer-quotation-request-view" title="Quotation request review" description="Review lifecycle state, requested lines and any resulting Sales quotation." path="CRM → Customers → Quote Requests → View" />

Permission, customer status and lifecycle determine which conversion actions appear. Searching, viewing or creating a request does not reserve Inventory or issue an invoice.

Related: [Customers](/screens/crm/customer), [Sales Opportunities](/screens/crm/sales-opportunity).
