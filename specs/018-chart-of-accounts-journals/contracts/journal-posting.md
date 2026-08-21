# Contract: Journal Posting

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

This is the interface `Docs/IMPLEMENTATION_PLAN.md` §6 calls the "posting service
interface for invoices, payments, tax, credit notes". This feature builds it and
its manual caller. **No document is wired to it here** (FR-034, R-013).

## 1. Service Surface

`App\Services\Accounting\JournalPostingService`, `final readonly`.

```php
public function draft(
    User $actor,
    CarbonInterface $entryDate,
    array $lines,              // list<array{chart_account_id: int, debit: float|string, credit: float|string, description?: string|null}>
    ?string $description = null,
    ?Model $source = null,
): JournalEntry;

public function post(User $actor, JournalEntry $entry): JournalEntry;

public function reverse(
    User $actor,
    JournalEntry $entry,
    ?CarbonInterface $reversalDate = null,
    ?string $description = null,
): JournalEntry;

public function postNew(          // draft + post in one transaction
    User $actor,
    CarbonInterface $entryDate,
    array $lines,
    ?string $description = null,
    ?Model $source = null,
): JournalEntry;
```

`postNew()` exists for the later automatic callers: an invoice has no reason to
create a draft a human will approve, so it needs one atomic call. It is a
composition of `draft()` and `post()` inside a single transaction, not a second
implementation of the rules.

Every method takes an explicit `User $actor` and authorizes against it (R-010).
None of them calls `auth()`; an architecture test proves it.

## 2. `post()` — Validation Order

The order matters, because the first failure is the one reported and the most
useful message should win.

1. `Gate::forUser($actor)->authorize('post', $entry)`.
2. Open a transaction; `lockForUpdate()` the entry row (FR-031).
3. Re-read status. If already `posted`, throw `PostedEntryIsImmutable`. This is
   the concurrency guard — two racing posts serialise here and the loser fails.
4. Load lines ordered by `sort_order`. If fewer than two, throw
   `UnbalancedJournalEntry` with a line-count reason. *(FR-024)*
5. Per line, in order:
   - both `debit` and `credit` non-zero → `InvalidJournalEntryLine` (both sides)
   - both zero → `InvalidJournalEntryLine` (neither side)
   - either negative → `InvalidJournalEntryLine` (negative amount)
   - account not `is_postable` → `InvalidJournalEntryLine` (not postable)
   - account not `is_active` → `InvalidJournalEntryLine` (inactive)

   Each carries the line's 1-based index. *(FR-021, FR-022)*
6. Sum debits and credits as integer minor units (R-001). If unequal, throw
   `UnbalancedJournalEntry` carrying both totals. *(FR-020, FR-030)*
7. Resolve the fiscal period containing `entry_date`. If none, throw
   `NoFiscalPeriodForDate`. *(FR-018)*
8. If that period `is_closed`, throw `ClosedFiscalPeriod` with its name.
   *(FR-023)*
9. Set `status = posted`, `fiscal_period_id`, `updated_by = $actor->id`.
10. Write the audit entry (§5).
11. Commit; return the fresh entry.

Steps 4–8 are pure validation and perform no write, so any throw rolls back a
transaction that changed nothing.

## 3. `reverse()` — Semantics

1. `Gate::forUser($actor)->authorize('reverse', $entry)`.
2. If `$entry->status !== Posted`, throw `PostedEntryIsImmutable` inverted —
   only a posted entry can be reversed. *(FR-028)*
3. If a posted entry already exists whose `source` morph points at `$entry`,
   throw `EntryAlreadyReversed` carrying its entry number. *(FR-028, R-003)*
4. Build the reversing entry:
   - `entry_date` = `$reversalDate ?? $entry->entry_date` *(FR-029)*
   - `description` = `$description ?? "Reversal of {$entry->entry_number}"`
   - `source` = `$entry` (the morph — no new column, R-003)
   - lines mirror the original in `sort_order`, with `debit` and `credit`
     swapped and each line's description carried over
5. Post it through the **same** `post()` path, so a reversal is validated
   against its own resolved period. A reversal into a closed period fails here.
   *(FR-029)*
6. Return the reversing entry.

A reversal is therefore never a special case in the validation rules. It is an
ordinary posting whose lines happen to be a mirror, which is why "a reversal of a
reversal" needs no separate rule: step 3 already refuses it, because the first
reversal's source points at the original and the second's would point at the
first — checked, found, refused.

**Note on step 3's query**: it looks for a *posted* reversal. A draft entry
manually created with its source pointing at a posted entry does not block a
real reversal, which is correct — a draft is not in the ledger.

## 4. Immutability Enforcement

Two layers, by design (R-002, FR-025).

**Service layer.** `post()` refuses an already-posted entry (step 3).
`draft()` only ever creates. There is no `update()` method on the service, so
there is no service path that edits a posted entry.

**Model layer.** `JournalEntry::booted()`:

- on `updating`: if the **persisted** status is `posted`, throw
  `PostedEntryIsImmutable` — with one exception, the `draft` → `posted`
  transition itself, identified by the persisted status being `draft`
- on `deleting`: if the persisted status is `posted`, throw
  `PostedEntryIsImmutable`

`JournalEntryLine::booted()`:

- on `creating`, `updating`, `deleting`: if the parent entry's persisted status
  is `posted`, throw `PostedEntryIsImmutable`

The line guard covers `creating` too, so a line cannot be appended to a posted
entry — which would silently unbalance it. Reversal lines are written *before*
their entry is posted, so they are unaffected.

Both `booted()` overrides need `ArchTest` strict-preset exemptions, for the same
required-Eloquent-override reason as `TicketMessage` and `MaintenanceRecord`.

## 5. Audit Trail

Per ADR 0005, through the `activity()` helper — matching
`TicketLifecycleService`'s shape exactly:

```php
activity()
    ->performedOn($entry)
    ->causedBy($actor)
    ->withChanges([
        'old' => ['status' => JournalEntryStatus::Draft->value],
        'attributes' => ['status' => JournalEntryStatus::Posted->value],
    ])
    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
    ->log('accounting.journal_entry.posted');
```

Event names. Note that `activity()->log($name)` writes `$name` to the
**`description`** column, not to `log_name`, which stays at the package's
configured `default`. That is the existing convention across every module — see
`ServiceRecordTest`, which asserts on `description` — so assertions here do the
same rather than introducing `useLog()` for one module.

| Event | `description` |
|---|---|
| Entry posted | `accounting.journal_entry.posted` |
| Entry reversed | `accounting.journal_entry.reversed` |
| Period closed | `accounting.fiscal_period.closed` |
| Period reopened | `accounting.fiscal_period.reopened` |

A reversal writes **two** activity rows: `reversed` on the original (recording
that it was reversed, and by whom) and `posted` on the new entry (because it
genuinely was posted). Reconstructing the ledger's history from the audit log
alone requires both.

Drafting is not audited. A draft is not in the ledger, its `created_by` is
already recorded by `TracksBlameable`, and logging every keystroke-level draft
edit would bury the events that matter.

## 6. Balance Calculation

`App\Services\Accounting\AccountBalanceService`:

```php
public function balanceFor(ChartAccount $account, bool $includeDescendants = true): string;

/** @return array<int, string> keyed by chart_account_id */
public function balancesForAll(bool $includeDescendants = true): array;

/** @return Collection<int, JournalEntryLine> */
public function ledgerFor(ChartAccount $account): Collection;
```

Rules:

- Posted lines only. Draft lines are excluded. *(FR-035)*
- Signed by the account type's normal balance via `NormalBalance::sign()`, so an
  account holding its normal balance reads positive. *(FR-036)*
- A parent includes its descendants, resolved recursively over the account tree.
  *(FR-037)*
- Summed on integer minor units and formatted back to a 2-decimal string, so no
  float ever participates. *(FR-030)*

`balancesForAll()` exists so the Chart of Accounts table renders in one
aggregate query plus one tree walk rather than N queries per row. It is the only
concession to performance in this feature and it is a read path, so it cannot
affect correctness of the ledger.

## 7. What This Contract Does Not Cover

- Automatic posting from any document. The interface is defined; wiring is each
  document's own feature and ADR (FR-034).
- Trial balance, P&L, or balance sheet. Out of scope per ADR 0007;
  `balancesForAll()` is what they would later be built from.
- Multi-currency. Single implied currency until `Docs/PRD.md` §12 is answered.
- Year-end close. No retained-earnings roll-up exists.
