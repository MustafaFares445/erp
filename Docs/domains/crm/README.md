# CRM Domain

---
status: canonical
owner: crm
last_verified: 2026-10-02
verified_against: app/Services/Crm, CRM enums/permissions, tests/Feature/Crm
---

## Purpose

CRM owns customer onboarding/approval/change requests, leads/interactions, campaigns/responses, customer quotation requests, customer return requests and customer timeline context.

## Main Code Anchors

Customer onboarding/provisioning/approval services, LeadService/LeadConversionService, InteractionService, CampaignService/Dispatch/Response, CustomerQuotationRequestService, CustomerReturnRequestService and CustomerTimelineService.

## Main UI Surfaces

Customers, Leads, Interactions, Campaigns, CRM Reports, Customer Quotation Requests, Customer Return Requests and related pricing/customer timeline surfaces.

The CRM dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards). It has a lead-source filter, customer growth beside new leads by status, and the dormant-lead queue (no interaction in 14+ days) beside per-campaign delivery and lead yield.

The Customers list uses the standard list-table experience ([ADR 0014](../../adr/0014-standard-list-table-experience.md)): a view tab bar (Default, Pending approval, Approved, Inactive, Starred, saved views), per-user favorites, Group by and slide-over rule filters. The "inactive 90 days" and Trashed filters stay as separate quick filters.

## Boundary

CRM requests conversion into Sales quotations and Inventory returns through owning-domain services; it does not duplicate those workflows.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
