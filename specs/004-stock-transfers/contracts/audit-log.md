# Contract: Lifecycle Audit — `StockTransferObserver` + service

Satisfies FR-014 / FR-014a: **every** transfer lifecycle action writes exactly one `activity_log` row via `spatie/laravel-activitylog` (`properties.source_channel = 'dashboard'`). This is the one behavioral divergence from FI-3 (which audits only confirmation).

> Backing store: as of ADR 0005, the audit trail is `spatie/laravel-activitylog` (table `activity_log`, model `App\Models\AuditLog extends Spatie\Activitylog\Models\Activity`), not a bespoke `audit_logs` table/service. The `action` string described below is stored in the `description` column; `entity_type`/`entity_id` are `subject_type`/`subject_id`; the acting user is the `causer`; before/after payloads live in `attribute_changes` (`old`/`attributes` keys); `source_channel`/`ip_address` live inside `properties`.

## Writers

| Action | Written by | `description` string | Payload notes |
|--------|-----------|-----------------|---------------|
| Draft created | `StockTransferObserver::created` | `inventory.transfer.created` | attributes: header attributes |
| Draft edited (header or lines) | `StockTransferObserver::updated` | `inventory.transfer.edited` | old/attributes: dirty attributes; item edits bump parent via `touch()` |
| Draft discarded (soft delete) | `StockTransferObserver::deleted` | `inventory.transfer.discarded` | old: header snapshot |
| Draft restored | `StockTransferObserver::restored` | `inventory.transfer.restored` | attributes: header snapshot |
| Confirmed | `StockTransferService::confirm` | `inventory.transfer.confirmed` | old/attributes: status **and** before/after source & destination balances |

## `StockTransferObserver`

**Location**: `app/Observers/StockTransferObserver.php`. Registered via `#[ObservedBy(StockTransferObserver::class)]` on the `StockTransfer` model (no provider edit).

```php
final readonly class StockTransferObserver
{
    public function created(StockTransfer $t): void;   // 'inventory.transfer.created'
    public function updated(StockTransfer $t): void;   // 'inventory.transfer.edited'
    public function deleted(StockTransfer $t): void;   // 'inventory.transfer.discarded'
    public function restored(StockTransfer $t): void;  // 'inventory.transfer.restored'
}
```

Each method calls Spatie's fluent logger directly, e.g.:

```php
activity()
    ->performedOn($t)
    ->withChanges(['old' => …, 'attributes' => …])
    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
    ->log('inventory.transfer.created');
```

No `causedBy()` call is needed here — when omitted, Spatie's `ActivityLogger` falls back to `auth()->user()` automatically, which is the acting dashboard user for every observer-triggered write.

## Avoiding a double audit on confirm

`confirm()` flips `status`/`transfer_number` with **`saveQuietly()`**, which suppresses model events — so the observer's `updated` does **not** fire for the confirmation transition. Confirmation is therefore audited exactly once, by the service, with the richer before/after-balance payload. (The alternative — letting the observer also fire and de-duplicating — is explicitly rejected in research D5.)

## Invariants

- Exactly one audit row per lifecycle action; no action is silently unaudited (Constitution VI).
- Confirmation audit is written **inside** the confirm transaction (rolls back with a failed confirm — a rejected confirm leaves no `confirmed` audit row).
- Observer audits use the acting dashboard user (via `activity()`'s automatic `auth()->user()` fallback); every call also sets `properties.ip_address` from the request.

## Test obligations

- Creating a draft ⇒ one `inventory.transfer.created` row (actor, channel).
- Editing header, and adding/removing an item line (via relation manager), ⇒ `inventory.transfer.edited` row(s).
- Discarding a draft ⇒ `inventory.transfer.discarded`; restoring ⇒ `inventory.transfer.restored`.
- Confirming ⇒ exactly **one** `inventory.transfer.confirmed` row (not an additional `edited` row), carrying before/after balances.
- A rejected confirm (e.g. insufficient stock) ⇒ **no** `confirmed` audit row (rolled back).
