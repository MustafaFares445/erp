---
title: "Sales Opportunity"
module: crm
pages: ["index", "create", "view", "edit"]
screenshots: ["crm-sales-opportunity-index", "crm-sales-opportunity-create", "crm-sales-opportunity-view", "crm-sales-opportunity-edit"]
lastVerified: 2026-10-07
---

# Sales Opportunities

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Working procedure” instructions below.
- Follow the documented “Working list” instructions below.
- Follow the documented “Create an opportunity” instructions below.

<IerpNavigationPath :items='["CRM","Pipeline","Sales Opportunity"]' />

Track commercially qualified opportunities, their owners, stage and expected closing date. Human review is a separate gate for AI-originated visit evidence; only valid approved opportunities can progress to downstream sales.

## Working procedure

1. Find an opportunity by title, customer, source visit, owner or detected product. Filter by stage or **AI review status** before acting.
2. **Create** or **Edit** the opportunity only while its stage and permissions allow changes. Preserve the currency, estimated value and expected close date.
3. Open **View** to check the source visit, detected product and review evidence. AI-originated records retain provenance even when a transcription attachment is later removed.
4. For AI-generated drafts, authorized reviewers use **Approve AI review** or **Reject AI review**. Rejection requires a reason. An unreviewed AI suggestion cannot become an authoritative opportunity.
5. Only **Approved** opportunities may use **Change stage**; closing requires the relevant reason/note. Closed opportunities cannot use the ordinary Edit action.
6. Use **Create quotation** only on a quotable opportunity not already linked to a quotation. The Sales-owned service creates the quotation, and CRM does not bypass Sales pricing or fulfillment.

## Working list

<IerpScreenshot id="crm-sales-opportunity-index" title="Opportunity register" description="Review stage, AI review status, estimated value, owner and source visit." path="CRM → Sales Opportunity" />

## Create an opportunity

<IerpScreenshot id="crm-sales-opportunity-create" title="Opportunity capture" description="Register the customer, commercial forecast and source context." path="CRM → Sales Opportunity → Create" imageSrc="/images/en/crm/crm-sales-opportunity-create.png" />

## Review opportunity evidence

<IerpScreenshot id="crm-sales-opportunity-view" title="Opportunity details and human review" description="Inspect the source, review decision, provenance and permitted sales actions." path="CRM → Sales Opportunity → View" />

## Edit before closing

<IerpScreenshot id="crm-sales-opportunity-edit" title="Edit an open opportunity" description="Adjust eligible commercial fields before the opportunity reaches a closed stage." path="CRM → Sales Opportunity → Edit" />

**AI review states:** Draft (Pending review), Approved (Accepted), Rejected. These are distinct from opportunity commercial stages. Performance and salary must not count unreviewed or rejected AI opportunities.

Related: [Leads](/screens/crm/lead), [Visits](/screens/employees/visit), [Quotations](/screens/sales/quotation).
