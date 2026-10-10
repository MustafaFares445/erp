---
title: "Automation Rules"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-support-automation-rule-index", "support-support-automation-rule-create", "support-support-automation-rule-edit"]
lastVerified: 2026-10-07
---

# Support automation rules: safe event responses

<IerpPageSummary purpose="Configure approved rule-based responses to ticket or SLA events without running arbitrary code or bypassing support permissions." useWhen="A Support Manager needs a repeatable notification, internal note, follow-up or permitted routing action." owner="Support and Maintenance" />

## When to use this page

A Support Manager needs a repeatable notification, internal note, follow-up or permitted routing action.

## Typical tasks

- Follow the documented “Rollout and safety” instructions below.
- Follow the documented “Create a restricted action” instructions below.
- Follow the documented “Edit, errors and consequence” instructions below.

## Rollout and safety

Support Automation is disabled by default until its rollout flag is approved. Direct URLs remain gated; enabling the feature does not remove the underlying role checks. Rules are evaluated by precedence and may stop further processing. An active rule with no conditions matches **every occurrence** of its selected event.

<IerpScreenshot id="support-support-automation-rule-index" title="Support automation catalog" description="Check event, precedence, active state and stop-processing behavior before editing operational rules." path="Advanced Support → Automation Rules" />

The allowed event catalog includes ticket creation, triage, assignment, priority/status change, messages, SLA at-risk/breach, stale waiting-customer and maintenance events. Operators must distinguish these triggers to avoid duplicate notifications.

## Create a restricted action

<IerpScreenshot id="support-support-automation-rule-create" title="Create automation rule" description="Choose an event, safe conditions and a permitted action; leave it inactive until tests pass." path="Automation Rules → Create" />

1. Name the business purpose; select the exact **Event** and order its **Precedence**.
2. Add matching conditions with safe field/operator combinations. Empty conditions mean the rule always matches the event.
3. Define one or more allowed actions: assign team, auto-assign, set priority, post **internal** note, add follower or create follow-up.
4. Use **Stop after this matching rule** only when lower-priority rules truly should not run.
5. Keep a new rule inactive until an isolated test confirms the visible ticket outcome and audits, including repeated-event/idempotency behavior.

## Edit, errors and consequence

<IerpScreenshot id="support-support-automation-rule-edit" title="Edit an automation rule" description="Review triggers and conditions alongside actions before activating or changing precedence." path="Automation Rules → Edit" />

Rules cannot run arbitrary PHP, SQL, shell commands or webhooks. An internal note is not a public response and must not incorrectly meet the first-response SLA. Repeated event processing must not duplicate follow-ups or reassign completed tickets. If a rule fails, investigate execution logs and domain constraints rather than bypassing ticket state guards.

Automation never authorizes a payment settlement, invoice, warranty decision or irreversible action outside its explicitly supported action catalogue.

Related: [Routing Rules](/screens/support/support-routing-rule), [Tickets](/screens/support/ticket), [SLA Policies](/screens/support/sla-policy).
