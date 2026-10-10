# SLA calendars: business hours and exceptions

<IerpPageSummary purpose="Define the business-time windows during which response and resolution commitments are measured." useWhen="Service policies need local working periods, a 24×7 commitment or holiday exceptions." owner="Support and Maintenance" />

## When to use this page

Service policies need local working periods, a 24×7 commitment or holiday exceptions.

## Typical tasks

- Follow the documented “Calendars control the SLA clock” instructions below.
- Follow the documented “Create valid working time” instructions below.
- Follow the documented “Edit with care” instructions below.

## Calendars control the SLA clock

An active calendar defines timezones, weekly working periods and holiday/working-day exceptions. An SLA policy points to this calendar; the applicable calendar is snapshotted when a ticket milestone starts. Editing a calendar affects future timing and cannot silently erase an existing breach.

<IerpScreenshot id="support-sla-calendar-index" title="SLA calendars" description="Compare default, active, 24×7 and regional working-calendar settings." path="Service Policies → SLA Calendars" imageSrc="/images/en/support/support-sla-calendar-index.png" />

## Create valid working time

<IerpScreenshot id="support-sla-calendar-create" title="New working calendar" description="Choose timezone and either 24×7 operation or explicit non-overlapping working windows." path="SLA Calendars → Create" imageSrc="/images/en/support/support-sla-calendar-create.png" />

Select the contractual timezone. Use **Runs 24×7** only for round-the-clock service; otherwise define weekly windows by day with proper opening and closing times. Multiple shifts on one weekday are allowed but **cannot overlap**, and each interval must end after it begins.

Add holidays as non-working exceptions. A working-day exception replaces the normal weekly schedule with its specific hours. Each date may occur only once in the exception list. Only one calendar may be the default.

## Edit with care

<IerpScreenshot id="support-sla-calendar-edit" title="Edit SLA working hours" description="Verify the consequences for future due dates and at-risk warnings before changing business hours." path="SLA Calendars → Edit" imageSrc="/images/en/support/support-sla-calendar-edit.png" />

Changing business hours, timezone or holiday exceptions can alter upcoming response/resolution deadlines. Record the reason, test DST or seasonal variations and ensure a change is not used to hide a historical SLA breach. The policy, not the calendar, chooses which milestones pause while waiting for the customer.

Related: [SLA Policies](/screens/support/sla-policy), [Tickets](/screens/support/ticket).
