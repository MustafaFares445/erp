# SLA policies: response and resolution commitments

<IerpPageSummary purpose="Define when response and resolution timers start, how working calendars apply, and when cases become at risk or breached." useWhen="Support Managers configure commitments by priority, service path, team or customer." owner="Support and Maintenance" />

## When to use this page

Support Managers configure commitments by priority, service path, team or customer.

## Typical tasks

- Follow the documented “Why this policy matters” instructions below.
- Follow the documented “Create or adjust targets” instructions below.
- Follow the documented “Working calendar and exceptions” instructions below.

## Why this policy matters

An SLA Policy selects a **business calendar**, ticket matching conditions and time targets. It is applied and snapshotted when the ticket's relevant milestone starts. Editing a policy does **not silently rewrite historical SLA deadlines**; review previous and future tickets separately.

<IerpScreenshot id="support-sla-policy-index" title="SLA policy catalog" description="Review precedence, active rules, calendars and response/resolution targets before selecting a policy." path="Service Policies → SLA Policies" />

## Create or adjust targets

<IerpScreenshot id="support-sla-policy-create" title="New SLA policy" description="Set matching rules, the working calendar, targets and explicit milestone pausing behavior." path="SLA Policies → Create" imageSrc="/images/en/support/support-sla-policy-create.png" />

1. Choose a unique code and meaningful name. Lower precedence numbers win; empty match conditions are wildcards.
2. Choose an active calendar matching the contractual timezone and working hours.
3. Define response and resolution compatibility targets in minutes. Add milestone-specific targets where needed; milestones take precedence over compatibility values.
4. Set **Warn before breach** and **Pause while waiting for customer** only for the appropriate milestone.
5. Test normal, urgent, waiting-for-customer and holiday scenarios in isolation before activating the rule.

<IerpScreenshot id="support-sla-policy-edit" title="Change an SLA policy" description="Assess prospective timing and escalation effects before changing an active policy." path="SLA Policies → Edit" imageSrc="/images/en/support/support-sla-policy-edit.png" />

**Operational consequences:** an internal note is not the same as a first public response. Waiting for customer only pauses milestones explicitly configured to pause. An SLA breach is a measure of elapsed eligible working time, not automatic permission to charge the customer or cancel a ticket. A resolved ticket still needs actual closure.

## Working calendar and exceptions

Related calendar maintenance is independent of this page. A 24×7 calendar ignores weekly working windows; a normal calendar needs non-overlapping working intervals and explicit holidays/exceptions. Dates/timezones must match the customer's actual service commitment. Do not backdate changes to disguise breaches.

Related: [SLA Calendars](/screens/support/sla-calendar), [Tickets](/screens/support/ticket), [Service Levels](/screens/support/support-service-level).
