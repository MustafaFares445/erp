# Contract: Lifecycle Audit — `StockTransferObserver` + service

Satisfies FR-014 / FR-014a: **every** transfer lifecycle action writes exactly one `audit_logs` row via the reused `App\Services\Audit\AuditLogger` (`source_channel = 'dashboard'`). This is the one behavioral divergence from FI-3 (which audits only confirmation).

## Writers

| Action | Written by | `action` string | Payload notes |
|--------|-----------|-----------------|---------------|
| Draft created | `StockTransferObserver::created` | `inventory.transfer.created` | new: header attributes |
| Draft edited (header or lines) | `StockTransferObserver::updated` | `inventory.transfer.edited` | old/new: dirty attributes; item edits bump parent via `touch()` |
| Draft discarded (soft delete) | `StockTransferObserver::deleted` | `inventory.transfer.discarded` | old: header snapshot |
| Draft restored | `StockTransferObserver::restored` | `inventory.transfer.restored` | new: header snapshot |
| Confirmed | `StockTransferService::confirm` | `inventory.transfer.confirmed` | old/new: status **and** before/after source & destination balances |

## `StockTransferObserver`

**Location**: `app/Observers/StockTransferObserver.php`. Registered via `#[ObservedBy(StockTransferObserver::class)]` on the `StockTransfer` model (no provider edit).

```php
final class StockTransferObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function created(StockTransfer $t): void;   // 'inventory.transfer.created'
    public function updated(StockTransfer $t): void;   // 'inventory.transfer.edited'
    public function deleted(StockTransfer $t): void;   // 'inventory.transfer.discarded'
    public function restored(StockTransfer $t): void;  // 'inventory.transfer.restored'
}
```

Each method calls `$this->auditLogger->log(<action>, $t, oldValues: …, newValues: …, actor: auth()->user(), sourceChannel: 'dashboard')`.

## Avoiding a double audit on confirm

`confirm()` flips `status`/`transfer_number` with **`saveQuietly()`**, which suppresses model events — so the observer's `updated` does **not** fire for the confirmation transition. Confirmation is therefore audited exactly once, by the service, with the richer before/after-balance payload. (The alternative — letting the observer also fire and de-duplicating — is explicitly rejected in research D5.)

## Invariants

- Exactly one audit row per lifecycle action; no action is silently unaudited (Constitution VI).
- Confirmation audit is written **inside** the confirm transaction (rolls back with a failed confirm — a rejected confirm leaves no `confirmed` audit row).
- Observer audits use the acting dashboard user; `AuditLogger` fills `ip_address` from the request.

## Test obligations

- Creating a draft ⇒ one `inventory.transfer.created` row (actor, channel).
- Editing header, and adding/removing an item line (via relation manager), ⇒ `inventory.transfer.edited` row(s).
- Discarding a draft ⇒ `inventory.transfer.discarded`; restoring ⇒ `inventory.transfer.restored`.
- Confirming ⇒ exactly **one** `inventory.transfer.confirmed` row (not an additional `edited` row), carrying before/after balances.
- A rejected confirm (e.g. insufficient stock) ⇒ **no** `confirmed` audit row (rolled back).
