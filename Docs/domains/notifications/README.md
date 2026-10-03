# Notifications Domain

---
status: canonical
owner: notifications
last_verified: 2026-10-03
verified_against: app/Services/Notifications, notification enums/resources, tests/Feature/Notifications
---

## Purpose

Notifications owns template rendering, delivery creation/dispatch/retry and notification delivery state. Business domains own the event/reason for notifying.

## Main Code Anchors

- `NotificationDispatcher.php`
- `NotificationTemplateRenderer.php`
- notification template/preference/delivery models/resources

Delivery states are Queued, Sent, Failed, Suppressed and Bounced.

## Administration Surface

The only notification screen in the admin panel is **Notification templates**. It is a business view over the system-defined notifications:

- Administrators edit wording per language (English / العربية) and per fixed message format (email, in-app) beside a live preview with realistic sample values, switch a notification on or off, and reset it to the seeded default (header "More" menu).
- Dynamic information is edited as inline pills (`NotificationMessageEditor`, `resources/js/filament/notification-message-editor.js`). The stored text keeps the existing `{{ name }}` placeholder format that `NotificationTemplateRenderer` reads; the editor converts between pills and placeholders on load and save, so no admin screen shows raw placeholders.
- Events, locales and channels are system-defined. The screen has no channel, locale, event or variable fields, no create or delete flow, and never writes those columns.
- Available information (variables) comes from `NotificationTemplateCatalog`; saving rejects placeholders the notification does not provide. When a notification gains or loses a variable, update the catalog, the seeder and the dispatcher together (a test keeps the catalog and seeded declarations aligned).
- Only `mail` and `database` templates for `NotificationEventKey` events are shown; campaign content templates and other keys/channels are not managed here.
- Notification deliveries and preferences have no admin screens. The `notification_deliveries` and `notification_preferences` tables, dispatch/retry (`notifications:retry-failed`) and channel selection stay as backend infrastructure.


## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
