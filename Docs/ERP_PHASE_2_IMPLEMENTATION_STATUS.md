# ERP Phase 2 Implementation Status

Date: 2026-09-04  
Branch: `feat/cross-module-remediation`

## Package status

| Package | Implementation | Focused validation | Notes |
|---|---|---|---|
| WP-2.10 — Cross-module notification engine | Complete | Complete for its implemented notification boundaries | Shared delivery platform consumed by CRM |
| WP-2.1 — CRM foundation | **Complete** | **Green** | GitHub Actions run #342, job `CRM WP-2.1 acceptance` |

---

## WP-2.1 — CRM foundation

**Implementation status:** Complete  
**Closeout status:** Complete  
**Focused validation status:** Green on GitHub Actions run **#342** (`33875077673`) for branch head `6f7c1791aa65e6ef873b496f24084b7eb3a53b9e`.

WP-2.1 establishes the normalized CRM foundation required by Track B without duplicating customer,
sales, employee, or notification business logic. The implementation consumes WP-2.10 for campaign
delivery and uses the existing customer onboarding service for lead conversion.

### Implemented CRM behavior

- Normalized lead lifecycle with typed source and status enums.
- Duplicate customer/lead identity protection before lead creation or update.
- Lead assignment with terminal-state protection.
- Evidence-backed lead stage progression:
  - New → Contacted;
  - Contacted → Qualified;
  - terminal conversion/disqualification through dedicated workflows only.
- Shared CRM interactions against Leads and Customers.
- Lead conversion through the existing `CustomerOnboardingService`.
- Lead conversion re-parents existing CRM interaction history to the resulting customer rather than
  copying or orphaning it.
- Explicit lead-conversion and campaign-completion domain events.
- Campaign creation, recipient segmentation and reproducible recipient snapshots.
- Campaign scheduling and once-per-minute due-campaign dispatch.
- Anti-double-send recipient uniqueness.
- Campaign delivery through WP-2.10 `NotificationDispatcher` and selected notification templates.
- Communication suppression / unsubscribe checks through the shared suppression table.
- Per-recipient send state, delivery evidence, failure evidence and response history.
- Campaign response recording including interested and unsubscribed outcomes.
- CRM reporting for:
  - leads by source;
  - stage counts;
  - campaign performance;
  - lead pipeline ageing;
  - campaign-attributed collected revenue.
- Filament CRM workflow:
  - Leads resource;
  - Interactions resource;
  - Campaigns resource;
  - CRM report page and CSV export;
  - lead lifecycle actions;
  - campaign build/schedule/send/cancel/response actions;
  - CRM dashboard widgets.
- CRM permissions/policies for CRM Manager and Reviewer roles.

### Database changes

WP-2.1 adds six normalized CRM tables only:

1. `database/migrations/2026_09_05_110000_create_campaigns_table.php`
2. `database/migrations/2026_09_05_110100_create_leads_table.php`
3. `database/migrations/2026_09_05_110200_create_interactions_table.php`
4. `database/migrations/2026_09_05_110300_create_lead_stage_transitions_table.php`
5. `database/migrations/2026_09_05_110400_create_campaign_recipients_table.php`
6. `database/migrations/2026_09_05_110500_create_campaign_responses_table.php`

These migrations are additive CRM persistence. They do **not** change accounting balances,
inventory balances, tax/payment calculations, or move/backfill existing financial or inventory data.

Validation also exposed an older cross-module migration that could not be executed by Laravel's
SQLite grammar because it dropped a foreign key by constraint name. Commit `6f7c179` changed
`2026_09_04_101000_preserve_opportunity_evidence.php` to the portable column-list FK form. This
preserves the existing nullable/null-on-delete opportunity-evidence semantics while allowing
`RefreshDatabase` to rebuild the table on SQLite.

### Focused validation evidence

A dedicated permanent GitHub Actions job, **`CRM WP-2.1 acceptance`**, was added so WP-2.1 can be
validated independently from unrelated accumulated remediation-branch quality debt.

Run **#342** / `33875077673` completed the CRM job successfully:

- Focused PHPStan: **43 production paths analyzed, 0 errors**.
- Focused Pest suite: **14 tests passed, 78 assertions**.
- No focused CRM test was skipped or weakened.

Validated business boundaries include:

- evidence-backed lead lifecycle progression;
- illegal stage-transition rejection;
- lead conversion and interaction-history preservation;
- terminal lead immutability;
- campaign recipient snapshot and delivery behavior;
- communication suppression and suppressed-delivery evidence;
- Reviewer read-only Filament access;
- CRM Manager create access;
- fixed CRM permission matrix / policy boundaries;
- due-campaign scheduler registration every minute;
- existing notification reminder scheduler registrations.

### Validation/closeout commits

- `201bffc` — add permanent WP-2.1 focused acceptance job.
- `284df635` — make CRM service/Filament/report boundaries statically explicit; remove 72 focused PHPStan errors without adding baseline suppressions.
- `4b2f885` — clear the final five focused PHPStan errors.
- `6f7c179` — make the opportunity-evidence FK migration SQLite portable so `RefreshDatabase` reaches the CRM assertions.

### Selected implementation commits

- `7bc29ff` — CRM persistence foundation.
- `f409fcd` — lead lifecycle, evidence and conversion.
- `d5664d6` — CRM → WP-2.10 notification seam.
- `2d89fc1` — campaign recipient/sending/response workflow.
- `045d6d0` — CRM permissions and policies.
- `795e732` — Filament CRM operator workflow.

### Remaining dependency / risk

- **Monetary pipeline value remains intentionally deferred to WP-2.2.** WP-2.1 reports pipeline
  ageing, but it does not invent a monetary lead value because the canonical Lead → Opportunity
  relationship is owned by WP-2.2.
- The repository-wide `composer test` job covers the entire accumulated remediation branch and has
  surfaced static-analysis debt outside the WP-2.1 acceptance scope. WP-2.1 closeout therefore uses
  the dedicated green CRM acceptance gate above; this document does not claim the whole remediation
  branch is globally green until that separate debt is resolved.

### Next Track-B package

The next CRM-track package is **WP-2.2 — Opportunity first-class**. It depends on WP-2.1 and
WP-1.10 and should establish the canonical Lead → Opportunity seam, opportunity value/stage/expected
close behavior, and complete the monetary pipeline reporting dependency intentionally left open by
WP-2.1.

---

## WP-2.10 — Cross-module notification engine

**Implementation status:** Complete

WP-2.10 provides the notification platform consumed by WP-2.1 and later packages.

### Implemented notification behavior

- One `NotificationDispatcher` entry point for Mail and Database notifications.
- Localized template rendering with strict declared-variable validation and locale fallback.
- Auditable `notification_deliveries` rows with queued/sent/failed/suppressed evidence.
- Per-user notification preferences and communication suppressions.
- Retry policy with persisted variables and persisted attachment metadata.
- Invoice PDF email migrated off the legacy direct-mail path.
- Domain-event notification bridge for invoice, payment, quotation, task, ticket, SLA, stock and
  reservation events.
- Scheduled overdue-invoice, expiring-lot, pending-approval, visit-due and retry sweeps.
- Filament templates, delivery observability, preferences and failed-notification widget.
- Architecture guard preventing direct Mail/Notification facade use from service classes outside
  the notification delivery boundary.
- Future templates for CRM and maintenance events.

### Notification database change

`database/migrations/2026_09_05_100400_add_attachments_to_notification_deliveries_table.php`
adds nullable JSON attachment metadata only; it does not alter accounting or inventory calculations.

### Remaining platform note

SMS and WhatsApp remain enumerated but intentionally produce delivery-failure evidence until their
providers are configured.
