---
title: "Knowledge Articles"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-knowledge-article-index", "support-knowledge-article-create", "support-knowledge-article-edit"]
lastVerified: 2026-10-07
---

# Knowledge articles: controlled answers for customer support

<IerpPageSummary purpose="Write, review and publish reusable support guidance with explicit audience, language and issue targeting." useWhen="A Support Manager needs approved troubleshooting instructions or agents need vetted answers in ticket conversations." owner="Support and Maintenance" />

## When to use this page

A Support Manager needs approved troubleshooting instructions or agents need vetted answers in ticket conversations.

## Typical tasks

- Follow the documented “Article list” instructions below.
- Follow the documented “Create and review” instructions below.
- Follow the documented “Edit and lifecycle” instructions below.

## Availability and visibility

**Knowledge Base is an optional Support rollout**. When disabled, the navigation and direct routes are denied even for administrators; this is expected, not a reason to bypass authorization. The underlying categories and articles must be created legitimately after the feature is enabled. An unseeded Edit URL is not a valid screenshot target.

Published articles can be **Internal**, **Customer** or **Both**. Only published customer-visible guidance can be shared with customers or returned by the customer Support API (when that separate channel is enabled). An internal note or unpublished draft must never be exposed to a customer.

## Article list

<IerpScreenshot id="support-knowledge-article-index" title="Knowledge article library" description="Review article status, language and audience before giving a customer any troubleshooting steps." path="Advanced Support → Knowledge Articles" />

Search existing answers and check their status and last approved revision. Avoid duplicate instructions for the same issue. A retired or archived procedure must not be linked as current resolution guidance.

## Create and review

<IerpScreenshot id="support-knowledge-article-create" title="Write a knowledge article" description="Provide a unique slug, language, audience, summary and instructions; optionally target products and ticket types." path="Knowledge Articles → Create" />

1. Supply a descriptive title and unique slug, and choose a relevant active category.
2. Set the **Visibility** and **Language** deliberately. English and Arabic are separate article records; do not assume automatic translation.
3. Write a concise summary and accurate procedural body. Include preconditions, warnings, what to verify and when to escalate.
4. Target the correct product/variant or ticket type when relevant; targeting improves deterministic suggestions but is not a security boundary.
5. Keep unreviewed content in Draft. Publishing requires the separate publishing permission; check customer visibility before approval.

## Edit and lifecycle

<IerpScreenshot id="support-knowledge-article-edit" title="Edit an authorized article" description="Review status, language, visibility and revised instructions before publishing or republishing." path="Knowledge Articles → Edit" />

Edits to published instructions can affect active customer cases. Review the wording, product applicability and escalation criteria and keep a clear revision history. If an article is unpublished or changed to Internal, previously shared article content must no longer be served to customers. In the ticket workspace, agents can mark appropriate articles as used or share only eligible published guidance.

**Safety:** do not publish sensitive diagnostic, customer, credential, pricing or internal cost information. A knowledge article does not replace technician diagnosis, warranty eligibility or a financial approval.

Related: [Tickets](/screens/support/ticket), [Knowledge Categories](/screens/support/knowledge-article-category).
