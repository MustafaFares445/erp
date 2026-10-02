# Notifications Domain

---
status: canonical
owner: notifications
last_verified: 2026-10-02
verified_against: app/Services/Notifications, notification enums/resources, tests/Feature/Notifications
---

## Purpose

Notifications owns template rendering, delivery creation/dispatch/retry and notification delivery state. Business domains own the event/reason for notifying.

## Main Code Anchors

- `NotificationDispatcher.php`
- `NotificationTemplateRenderer.php`
- notification template/preference/delivery models/resources

Delivery states are Queued, Sent, Failed, Suppressed and Bounced.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
