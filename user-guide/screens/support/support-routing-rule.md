---
title: "Routing Rules"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-support-routing-rule-index", "support-support-routing-rule-create", "support-support-routing-rule-edit"]
lastVerified: 2026-10-07
---

# Support routing rules: controlled team and skill assignment

<IerpPageSummary purpose="Define auditable matching rules that direct triaged tickets to the correct team and optionally qualified employee." useWhen="Support Managers need to route incoming work consistently by service path, type, product or customer location." owner="Support and Maintenance" />

## When to use this page

Support Managers need to route incoming work consistently by service path, type, product or customer location.

## Typical tasks

- Follow the documented “When routing is available” instructions below.
- Follow the documented “Create a routing rule” instructions below.
- Follow the documented “Edit and verify” instructions below.

## When routing is available

Smart Routing is a feature-flagged workspace. Enabling it does not grant permission by itself: your role must also be allowed to manage Support routing. When disabled, even a direct link is not an accessible work page. Confirm your organization's rollout before configuring production rules.

<IerpScreenshot id="support-support-routing-rule-index" title="Routing rules and precedence" description="Inspect matching order, activation, target team and required skills before changing ticket distribution." path="Advanced Support → Routing Rules" />

Rules use **precedence**: lower numbers are evaluated before less specific rules. Empty match fields are wildcards, so broad rules may intercept tickets unintentionally. Check the target team, required skill and auto-assign policy together.

## Create a routing rule

<IerpScreenshot id="support-support-routing-rule-create" title="Create routing rule" description="Define explicit criteria, lower precedence for specific matches, and the eligible destination team." path="Routing Rules → Create" />

1. Name the operational purpose and choose its precedence.
2. Select type, service path, product/variant/category or city only when the condition is important. Do not enter unsupported criteria.
3. Select an **active target team**; choose required skill if the service needs a specific qualification.
4. Enable **Auto assign a qualified agent** only after confirming capacity, active agent profiles and skill membership.
5. Test representative triaged cases without impacting live queues before enabling the rule.

## Edit and verify

<IerpScreenshot id="support-support-routing-rule-edit" title="Edit existing routing rule" description="Review activation, priority, targeting and team/skill before changing production assignment behavior." path="Routing Rules → Edit" />

An inactive rule can be staged and reviewed without routing tickets. Activating or reprioritizing rules can redirect future work and alter SLA outcomes; record why and verify which rule matched. Re-running routing on an existing ticket is a separate authorized action; do not assume editing a rule reassigns every previous case.

**Authority and audit:** Support Manager/System Admin assignment privileges are separate from agent-specific ticket work. Never circumvent authorization through direct routes or by assigning a ticket to an employee without the required qualification.

Related: [Teams](/screens/support/support-team), [Skills](/screens/support/support-skill), [Queues](/screens/support/support-queue), [Tickets](/screens/support/ticket).
