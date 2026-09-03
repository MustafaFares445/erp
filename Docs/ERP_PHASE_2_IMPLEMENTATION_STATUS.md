# ERP Phase 2 Implementation Status

Date: 2026-09-03  
Branch: `feat/cross-module-remediation`

## Current package

### WP-2.10 — Cross-module notification engine

**Implementation status:** Complete  
**Validation status:** Automatic GitHub Actions validation pending on the latest branch head.

WP-2.10 was already partially implemented when this session started. This continuation finished the
remaining package instead of starting a second notification architecture.

## What is now implemented

- One `NotificationDispatcher` entry point for Mail and Database notifications.
- Localized template rendering with strict declared-variable validation and locale fallback.
- Auditable `notification_deliveries` rows with queued/sent/failed/suppressed evidence.
- Per-user notification preferences and communication suppressions.
- Retry policy with persisted variables and persisted attachment metadata.
- Invoice PDF email migrated off `InvoiceMail` / direct `Mail::to()`.
- Missing/disabled template failures become Failed delivery evidence and do not roll back or surface
  as an error from an already-committed business workflow.
- Domain events wired for:
  - invoice issued;
  - payment received after posting;
  - quotation decided;
  - task assigned;
  - ticket updated;
  - SLA breach;
  - newly activated low/out-of-stock condition;
  - inventory reservation expired.
- Scheduled notification sweeps:
  - `notifications:overdue-invoices` daily;
  - `notifications:expiring-lots` daily;
  - `notifications:pending-approvals` daily;
  - `notifications:visits-due` daily;
  - `notifications:retry-failed` hourly.
- Future event templates pre-seeded for `LeadConverted`, `CampaignCompleted`, and
  `MaintenanceRecordBilled`.
- Filament administration and observability:
  - Notification Templates resource with preview;
  - Notification Deliveries read-only resource with retry action;
  - Notification Preferences resource;
  - database-notification bell;
  - Failed Notifications (24h) dashboard widget.
- Architecture guard prevents `App\\Services` from directly using Mail/Notification facades
  outside the notification dispatcher boundary.

## Database change

Migration added:

`database/migrations/2026_09_05_100400_add_attachments_to_notification_deliveries_table.php`

It adds nullable JSON `attachments` to `notification_deliveries`. This is notification delivery
metadata only. It does not modify accounting, inventory balances, payment calculations, or migrate
existing financial data.

## Main commits produced in this continuation

- `36e13b3` — delivery pipeline, invoice-mail consolidation, reminder sweeps.
- `42f6b88` — core business domain-event bridge.
- `5ab39f6` — SLA and inventory alert bridge plus localized templates.
- `0f8a170` — Filament notification administration and observability.
- `2f4cda0` — WP-2.10 regression and architecture tests.
- `6b1d12f` — raw persisted-channel assertion correction.
- `c7baa85` — failure isolation and invoice send semantics.
- `adeebfd` — immediate notification handoff through framework dispatcher.
- `8318ae8` — future cross-module event template coverage.

## Tests added or updated

- Notification dispatch, suppression, retry and queue-failure behavior.
- Attachment metadata persistence across retry.
- Missing-template isolation into Failed delivery evidence.
- Domain-event notification fan-out.
- 7/30/60-day overdue reminder behavior.
- Daily/hourly scheduler registration.
- Filament templates/deliveries/preferences/widget coverage.
- Direct Mail/Notification facade architecture guard.
- Legacy invoice mailable removal guard.

## Remaining risks / validation

- The latest branch head still requires a completed CI run. Earlier CI runs in this session were
  cancelled by a newer branch push before completion; no failing conclusion was observed.
- SMS and WhatsApp remain enumerated but intentionally fail with delivery evidence until providers
  are configured.
- Pending-approval reminders treat Draft as the maker/checker approval boundary for Bills,
  Expenses, Refunds, and Receivable Write-offs because those canonical lifecycles do not expose a
  separate `pending_approval` state. Purchase Orders use their explicit `pending_approval` state.
- CRM campaign/lead events and maintenance billing events are template-ready but will be emitted by
  their owning work packages rather than from WP-2.10.

## Next implementation package

The next Phase-2 package to implement is **WP-2.1 — CRM foundation**. It can now consume the
notification platform for campaign delivery and future lead-conversion events without adding a
second mail/notification path.
