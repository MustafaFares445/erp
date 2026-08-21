# ADR 0005: Back the Audit Trail with `spatie/laravel-activitylog`

**Status**: Accepted

**Date**: 2026-08-10

**Deciders**: Project Owner

**Related**: `specs/003-stock-adjustments/contracts/audit-log.md`, `specs/004-stock-transfers/contracts/audit-log.md`, ADR 0003 (Employees), ADR 0004 (Support and Maintenance)

## Context

FI-3 (`specs/003-stock-adjustments`) introduced a bespoke audit trail: a
`audit_logs` table (`actor_user_id`, `action`, `entity_type`, `entity_id`,
`old_values`, `new_values`, `source_channel`, `ip_address`), a single writer
service `App\Services\Audit\AuditLogger`, and later a read-only Filament
`AuditLogResource`. Every subsequent module that needed to audit a sensitive
action (stock transfers, reservations, pricing, CRM, Employees) was told to
reuse this infrastructure rather than build its own (ADR 0003, ADR 0004): "no
parallel audit store." By the time of this ADR, 29 call sites across
`app/Services/*`, three model observers, and one queued job depended on
`AuditLogger::log()`.

That reuse policy is unchanged by this ADR — only what the shared
infrastructure is built on changes. `AuditLogger` was hand-rolled: it
duplicated ecosystem-standard concerns (subject/causer morphs, before/after
diffing, a query API) that `spatie/laravel-activitylog` already provides, is
already a project dependency family (`spatie/laravel-data`,
`spatie/laravel-medialibrary`, `spatie/laravel-permission` are all already in
use), and is maintained upstream.

## Decision

Replace the custom audit trail with `spatie/laravel-activitylog`:

- The `audit_logs` table is dropped; Spatie's `activity_log` table is used
  instead (`log_name`, `description`, `subject_type`/`subject_id`,
  `causer_type`/`causer_id`, `event`, `attribute_changes`, `properties`).
- `App\Services\Audit\AuditLogger` is deleted. Every call site now calls
  Spatie's `activity()` helper directly:
  ```php
  activity()
      ->performedOn($entity)
      ->causedBy($actor)   // omit, or pass null, to fall back to auth()->user()
      ->withChanges(['old' => $oldValues, 'attributes' => $newValues])
      ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
      ->log($action);
  ```
- `App\Models\AuditLog` is kept as the app-facing name, but now as a thin
  subclass of `Spatie\Activitylog\Models\Activity` (bound via
  `config('activitylog.activity_model')`) instead of a plain `Model`. This
  preserves `AuditLog::factory()` for tests and adds two read-only accessors,
  `source_channel`/`ip_address`, over Spatie's generic `properties` column —
  it does not reintroduce the old field names or the `AuditLogger` API.
- Because the class keeps its name and namespace, `AuditLogPolicy`, the
  `AuditLogResource` Filament resource, its route, and the
  `CrmPermission::AuditView` permission gate are all unchanged.
- No model in the app is converted to Spatie's `LogsActivity` trait (which
  auto-logs every attribute change). Call sites keep writing exactly what
  they wrote before, one explicit `activity()->log()` call per audited event
  — this is a like-for-like swap of the writer, not a change to what gets
  audited or when.
- The old `audit_logs` table held only dev/seed data, so no historical-row
  migration was performed — the new table starts empty.

## Consequences

- `action` moves to the `description` column; `entity_type`/`entity_id` move
  to `subject_type`/`subject_id`; `actor_user_id` moves to
  `causer_type`/`causer_id` (relation renamed `actor` → `causer`);
  `old_values`/`new_values` move into `attribute_changes` (`old`/`attributes`
  keys); `source_channel`/`ip_address` move into the generic `properties`
  column, exposed back out through model accessors of the same name.
- `specs/003-stock-adjustments/contracts/audit-log.md` and
  `specs/004-stock-transfers/contracts/audit-log.md` are updated to describe
  the `activity()` call shape instead of the retired `AuditLogger` contract.
- Any future sensitive action still reuses the same shared infrastructure —
  now Spatie's `activity()` helper — rather than introducing a parallel audit
  store, per the standing policy from ADR 0001–0004.
- Adopting `spatie/laravel-activitylog` also makes its ecosystem features
  available if a later feature needs them (e.g. `LogsActivity` for
  auto-diffed model logging, log cleanup via `clean_after_days`) — none of
  that is turned on by this ADR, but the path is open without another
  migration.
