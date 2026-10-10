# Campaigns

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Working procedure” instructions below.
- Follow the documented “List and preparation” instructions below.
- Follow the documented “Create a campaign” instructions below.

<IerpNavigationPath :items='["CRM","Pipeline","Campaigns"]' />

Prepare communications with prospective or existing customers, follow recipient delivery, and attribute responses. CRM owns campaign state and lead attribution; Notifications owns dispatch and Sales owns downstream quotations/orders.

## Working procedure

1. In the Campaigns list, inspect campaign number, channel, status, recipient count and scheduled/completed dates. Filter by status.
2. **Create** a Draft campaign with a descriptive name, channel and content template. Confirm audience/template readiness before dispatch.
3. **Build Recipients** for the chosen audience. Recipient status is separate from campaign status: Pending, Sent, Failed or Suppressed.
4. **Schedule** a campaign when delivery should occur later, or use **Send Campaign** only after validating recipients and the message. Delivery passes through Notifications.
5. After sending, open campaign details for delivery and response statistics. **Download Send Log** for review and audit; importing campaign responses can create/attribute leads through CRM, not directly Sales orders.
6. **Cancel** only while lifecycle conditions allow. Keep failed/suppressed recipients and previous send history for audit.

## List and preparation

<IerpScreenshot id="crm-campaign-index" title="Campaign management queue" description="Review status, channel, audience size and planned/completed dates." path="CRM → Pipeline → Campaigns" />

## Create a campaign

<IerpScreenshot id="crm-campaign-create" title="Campaign setup" description="Choose channel, template and campaign identity before building recipients." path="CRM → Pipeline → Campaigns → Create" />

## Results and control

<IerpScreenshot id="crm-campaign-view" title="Campaign delivery and response details" description="Inspect recipient/lead results, timing and allowed lifecycle actions." path="CRM → Pipeline → Campaigns → View" />

**Campaign states:** Draft, Scheduled, Sending, Completed, Failed and Cancelled. Missing dispatch permission, unavailable content template or incompatible status can prevent an action. Send and cancellation are stateful operations; opening the screen or downloading a log is not.

Related: [Leads](/screens/crm/lead), [Sales Opportunities](/screens/crm/sales-opportunity).
