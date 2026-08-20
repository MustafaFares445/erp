# Phase 1 Data Model: Accounting Foundation — Chart of Accounts and Journal Entries

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

All five tables are ERD-defined. Two deviations are authorised by ADR 0007 and
marked inline; everything else matches `Docs/database/ERD.md`.

## 1. Entity Relationship Overview

```
account_types (5 seeded rows)
    |
    | 1..*
    v
chart_accounts  ---- parent_id (self, nullable) ----> chart_accounts
    |
    | 1..*
    v
journal_entry_lines  <---- 2..*  ----  journal_entries
                                            |
                                            | source_type / source_id (morph, nullable)
                                            +--> any document, or a JournalEntry (a reversal)
                                            |
                                            | fiscal_period_id (nullable until posted)
                                            v
                                       fiscal_periods
```

Cardinality notes:

- A `chart_accounts` row has at most one parent and any number of children. Only
  leaf rows may be posting targets (R-005).
- A `journal_entries` row has at least two lines once posted; a draft may
  temporarily have fewer.
- `fiscal_period_id` is null on a draft and set at posting (R-004).
- `source_type`/`source_id` is null for a manual entry, and points at the
  reversed `JournalEntry` for a reversal (R-003).

## 2. Table: `account_types` *(ERD-defined, unchanged)*

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | |
| `name` | varchar(100) | No | | Unique. Cast to `AccountElement`. |
| `normal_balance` | varchar(10) | No | | Cast to `NormalBalance` (`debit`/`credit`). |
| `created_at` / `updated_at` | timestamp | No | current | |

**Indexes**: PK on `id`; unique on `name`.

**Seeded, never user-created** (FR-002, R-007). Exactly five rows:

| `name` | `normal_balance` |
|---|---|
| `asset` | `debit` |
| `liability` | `credit` |
| `equity` | `credit` |
| `income` | `credit` |
| `expense` | `debit` |

The ERD types `normal_balance` as `enum(debit,credit)`. Stored as `varchar(10)`
with an application-level enum cast, matching how every other status/type column
in this codebase is stored — no native MySQL `ENUM` exists anywhere in the 102
migrations, and introducing one here would need a migration to add a sixth value
that will never come.

## 3. Table: `chart_accounts` *(ERD-defined, unchanged)*

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | |
| `account_type_id` | bigint unsigned | No | | FK → `account_types`, restrict on delete |
| `parent_id` | bigint unsigned | Yes | null | FK → `chart_accounts`, restrict on delete |
| `code` | varchar(50) | No | | Unique |
| `name` | varchar(255) | No | | |
| `is_postable` | boolean | No | true | Leaf accounts only (FR-007) |
| `is_active` | boolean | No | true | Blocks future postings only (FR-011) |
| `created_at` / `updated_at` | timestamp | No | current | |
| `created_by` / `updated_by` | bigint unsigned | Yes | null | FK → `users`, null on delete |
| `deleted_at` | timestamp | Yes | null | Soft deletes |

**Indexes**: PK on `id`; unique on `code`; index on `account_type_id`,
`parent_id`, `is_active`.

**Uses `TracksBlameable`.**

**Invariants**

| # | Rule | Enforced by | FR |
|---|---|---|---|
| C-1 | `code` unique across all rows including soft-deleted | Unique index + validation | FR-005 |
| C-2 | `parent_id` is neither self nor a descendant | `ChartOfAccountService` | FR-006 |
| C-3 | `is_postable` is false whenever children exist | `ChartOfAccountService` on both write paths | FR-007, FR-008 |
| C-4 | Not deletable with children | `ChartOfAccountService` | FR-009 |
| C-5 | Not deletable when referenced by any journal line | `ChartOfAccountService` | FR-010 |

C-1 is deliberately checked against soft-deleted rows too: reusing a deleted
account's code would make historical lines ambiguous when the deleted row is
restored.

## 4. Table: `fiscal_periods` *(ERD deviation **E-1**)*

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | |
| `name` | varchar(100) | No | | Unique |
| `starts_at` | date | No | | |
| `ends_at` | date | No | | |
| `is_closed` | boolean | No | false | Single lifecycle source of truth |
| `created_at` / `updated_at` | timestamp | No | current | |
| `created_by` / `updated_by` | bigint unsigned | Yes | null | FK → `users`, null on delete |

**Indexes**: PK on `id`; unique on `name`; composite index on
`(starts_at, ends_at)` for the containment lookup at posting time; index on
`is_closed`.

**No `deleted_at`** — matches the ERD.

**E-1**: the ERD's generic `status varchar(50) default 'draft/pending'` column
is omitted. `is_closed` is the real lifecycle. Carrying both would put a
period's state in two places that can disagree. Authorised by ADR 0007.

**Invariants**

| # | Rule | Enforced by | FR |
|---|---|---|---|
| P-1 | `ends_at >= starts_at` | `FiscalPeriodService` + form validation | FR-014 |
| P-2 | No date-range overlap with any other period | `FiscalPeriodService`, inside the insert transaction | FR-015 |
| P-3 | Not deletable with any journal entry | `FiscalPeriodService` | FR-017 |
| P-4 | Closed periods reject postings and reversals | `JournalPostingService` | FR-023 |

P-2 is checked inside the transaction that inserts, not before it, so two
concurrent creations cannot both pass the check. Overlap is
`starts_at <= other.ends_at AND ends_at >= other.starts_at`.

## 5. Table: `journal_entries` *(ERD-defined, unchanged)*

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | |
| `fiscal_period_id` | bigint unsigned | Yes | null | FK → `fiscal_periods`, restrict on delete. Null until posted. |
| `entry_number` | varchar(100) | No | | Unique. `JE-%06d` (R-009) |
| `entry_date` | date | No | | Accounting date; resolves the period |
| `description` | text | Yes | null | |
| `source_type` | varchar(100) | Yes | null | Morph type; `JournalEntry` for a reversal |
| `source_id` | bigint unsigned | Yes | null | Morph id |
| `status` | varchar(50) | No | `draft` | Cast to `JournalEntryStatus` |
| `created_at` / `updated_at` | timestamp | No | current | |
| `created_by` / `updated_by` | bigint unsigned | Yes | null | FK → `users`, null on delete |

**Indexes**: PK on `id`; unique on `entry_number`; index on
`fiscal_period_id`, `status`, `entry_date`; composite index on
`(source_type, source_id)` for the reversal lookup.

**No `deleted_at`** — matches the ERD, and required by FR-025. A posted entry
cannot be removed by any means; a draft is hard-deleted because it was never in
the ledger.

**Uses `TracksBlameable`.**

**Invariants**

| # | Rule | Enforced by | FR |
|---|---|---|---|
| J-1 | Debits total equals credits total, on integer minor units | `JournalPostingService` | FR-020, FR-030 |
| J-2 | At least two lines | `JournalPostingService` | FR-024 |
| J-3 | Every line's account is postable and active | `JournalPostingService` | FR-022 |
| J-4 | Resolved fiscal period exists and is open | `JournalPostingService` | FR-018, FR-023 |
| J-5 | A posted entry is immutable and undeletable | `JournalPostingService` **and** `JournalEntry::booted()` | FR-025 |
| J-6 | Only `draft` → `posted`; never the reverse | `JournalPostingService` | FR-019 |
| J-7 | At most one reversal per entry | `JournalPostingService` | FR-028 |

J-5 is the one invariant enforced twice by design (R-002). `booted()` permits
exactly one status change — `draft` → `posted` — and rejects every other
mutation on a row whose persisted status is already `posted`.

## 6. Table: `journal_entry_lines` *(ERD deviation **E-2**)*

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | |
| `journal_entry_id` | bigint unsigned | No | | FK → `journal_entries`, cascade on delete |
| `chart_account_id` | bigint unsigned | No | | FK → `chart_accounts`, restrict on delete |
| `debit` | decimal(15,2) | No | 0 | |
| `credit` | decimal(15,2) | No | 0 | |
| `description` | text | Yes | null | |
| `sort_order` | unsigned integer | No | 0 | **E-2** — display order within the entry |
| `created_at` / `updated_at` | timestamp | No | current | |

**Indexes**: PK on `id`; index on `journal_entry_id`, `chart_account_id`;
composite index on `(journal_entry_id, sort_order)`.

**E-2**: `sort_order` is added so an entry's lines render in a stable
author-chosen order rather than insertion order, and so a reversal's lines
visibly pair with the original's. Presentational and additive. Authorised by
ADR 0007.

The `cascade on delete` from `journal_entries` is safe precisely because a
posted entry can never be deleted — the cascade only ever fires for a draft.

**Invariants**

| # | Rule | Enforced by | FR |
|---|---|---|---|
| L-1 | Exactly one of `debit`/`credit` is non-zero | `JournalPostingService` at post time | FR-021 |
| L-2 | Both amounts are non-negative | Form validation + service | FR-021 |
| L-3 | Not mutable once the parent is posted | `JournalEntryLine::booted()` | FR-025 |

L-1 is checked at posting, not on save: a draft is allowed to be incomplete,
which is what makes it a draft (R-012).

## 7. Enums

### `AccountElement` (string-backed) — `app/Enums/AccountElement.php`

`Asset = 'asset'`, `Liability = 'liability'`, `Equity = 'equity'`,
`Income = 'income'`, `Expense = 'expense'`.

Exposes `normalBalance(): NormalBalance` so the seeder derives the pairing from
one place, and `label(): string` for display.

### `NormalBalance` (string-backed) — `app/Enums/NormalBalance.php`

`Debit = 'debit'`, `Credit = 'credit'`.

Exposes `sign(): int` returning `1` for debit and `-1` for credit, which is the
single place FR-036's sign convention lives.

### `JournalEntryStatus` (string-backed) — `app/Enums/JournalEntryStatus.php`

`Draft = 'draft'`, `Posted = 'posted'`.

Exposes `isPosted(): bool` and `label(): string`.

### `AccountingPermission` (string-backed) — `app/Enums/AccountingPermission.php`

The full catalogue is specified in `contracts/permissions.md`. It exposes
`values(): array` and, per the `SupportPermission` precedent, deliberately has
no `fixedRoleNames()` of its own — only `DashboardRole::fixedRoleNames()` is
consulted for the admin-bypass check.

### `DashboardRole` additions

`ChiefAccountant = 'Chief Accountant'` and `Accountant = 'Accountant'` are added
to the existing enum (R-006).

## 8. Validation Rules

Form-level (fail before a write is attempted):

| Field | Rule |
|---|---|
| `chart_accounts.code` | required, max 50, unique including trashed |
| `chart_accounts.name` | required, max 255 |
| `chart_accounts.account_type_id` | required, exists |
| `chart_accounts.parent_id` | nullable, exists, not self, not a descendant |
| `fiscal_periods.name` | required, max 100, unique |
| `fiscal_periods.starts_at` | required, date |
| `fiscal_periods.ends_at` | required, date, `after_or_equal:starts_at` |
| `journal_entries.entry_date` | required, date |
| `journal_entries.description` | nullable |
| line `chart_account_id` | required, exists, must be postable and active |
| line `debit` / `credit` | numeric, `min:0`, max 13 integer digits + 2 decimals |

Service-level (the invariants above). Every failure throws a domain exception
from `App\Services\Accounting\Exceptions`, never a bare `RuntimeException`, so
the Filament layer can surface a specific message.

## 9. Domain Exceptions

`App\Services\Accounting\Exceptions`:

- `UnbalancedJournalEntry` — carries both totals, for FR-020's message.
- `InvalidJournalEntryLine` — carries the offending line's index and reason
  (both sides set, neither side set, non-postable account, inactive account).
- `ClosedFiscalPeriod` — carries the period name.
- `NoFiscalPeriodForDate` — carries the entry date.
- `PostedEntryIsImmutable` — thrown by both the service and the model guard.
- `EntryAlreadyReversed` — carries the existing reversal's entry number.
- `AccountNotDeletable` — carries the reason (has children, has lines).
- `AccountHierarchyCycle` — carries the offending parent.
- `OverlappingFiscalPeriod` — carries the overlapping period's name.

**(precedent)** Scoped under `App\Services\Accounting\Exceptions` next to the
services that throw them, following `App\Services\Employees\Exceptions` and
`App\Services\Support\Exceptions`. This needs the same `ArchTest` Laravel-preset
exemption those two namespaces already carry.

## 10. State Ownership

| State | Owned by | Written by nothing else |
|---|---|---|
| `journal_entries.status` | `JournalPostingService` | Filament actions call the service; no resource writes the column |
| `journal_entries.fiscal_period_id` | `JournalPostingService` | Set once, at posting |
| `journal_entries.entry_number` | `JournalEntry::booted()` on create | Generated once, never reassigned |
| `journal_entry_lines.*` on a posted entry | nobody | Model guard rejects all writes |
| `fiscal_periods.is_closed` | `FiscalPeriodService` | Filament action calls the service |
| `chart_accounts.is_postable` | `ChartOfAccountService` | Cleared automatically when a child appears |
| Account balances | nobody — computed | No stored column exists (R-008) |

## 11. Migration Order

Foreign keys force this sequence:

1. `account_types`
2. `chart_accounts` (needs `account_types`, self-references)
3. `fiscal_periods`
4. `journal_entries` (needs `fiscal_periods`)
5. `journal_entry_lines` (needs `journal_entries`, `chart_accounts`)

`chart_accounts.parent_id` is added inside the same migration as the table, as a
self-referencing FK — the table exists by the time the constraint is declared
within `Schema::create`, which is how the built `product_categories` migration
already handles its own self-reference.

No existing table is altered. No data backfill is required, because nothing in
the codebase currently writes an accounting record.
