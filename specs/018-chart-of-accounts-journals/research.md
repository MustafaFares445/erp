# Phase 0 Research: Accounting Foundation — Chart of Accounts and Journal Entries

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

Each item below is a decision reached before implementation, with the evidence
that forced it. Items marked **(precedent)** reuse a pattern already in the
codebase rather than inventing one.

## R-001 — Money is compared on integer minor units, never floats

`journal_entry_lines.debit` and `.credit` are `decimal(15,2)` in the ERD. The
one documented acceptance criterion is an equality test between two sums of
those columns. PHP floats cannot be relied on for that: `0.1 + 0.2 !== 0.3`,
and a two-line entry of `33.33 / 33.33` must balance while `33.33 / 33.34` must
not.

**Decision**: every total, comparison, and negation inside the posting service
converts to integer cents first (`(int) round($value * 100)`), sums as `int`,
and compares as `int`. Decimal strings are read from the database and cast back
only for display. No `==`, `<`, or `>` is ever applied to a float amount.

**Rejected**: `bcmath`. It would work, but it introduces a string-arithmetic
idiom that appears nowhere else in this codebase, and the amounts here are
bounded well inside PHP's integer range at two decimal places.

## R-002 — Immutability is enforced twice, at the service and the model

A ledger whose history can be rewritten is not an audit trail. The service is
the authorized write path, but a service check alone is bypassed by any direct
`$entry->update()` — including one written later by a developer who does not
know the rule.

**Decision**: `JournalPostingService` refuses to mutate a posted entry, **and**
`JournalEntry::booted()` throws on `updating`/`deleting` when the persisted
status is `posted` (allowing only the `draft` → `posted` transition itself),
**and** `JournalEntryLine::booted()` throws on `updating`/`deleting` when its
parent is posted.

**(precedent)** This is exactly the defense-in-depth pattern spec 016
established for `MaintenanceRecord` and `MaintenanceTask`, and spec 014/015
established for `TaskStatusLog`, `VisitGpsLog`, `TicketAssignment`, and
`TicketMessage`. Each required an `ArchTest` exemption for a `protected static
booted()` override; these two models join that list for the same reason, which
is that `booted()` is Eloquent's own required signature and not a design choice.

## R-003 — Reversal reuses the `source` morph; no new column

The ERD gives `journal_entries` a `source_type`/`source_id` pair described as
"Source document type/id". A reversing entry's producing document genuinely
*is* the original entry, so the morph already expresses the link.

**Decision**: a reversal sets `source_type = JournalEntry::class` and
`source_id = $original->id`. "Has this been reversed?" is answered by querying
for a posted entry whose source points at it. No `reverses_journal_entry_id`
column and no `is_reversed` flag is added, so this is not an ERD deviation.

**Rejected**: a dedicated FK. It would be marginally faster to query and would
have needed its own ERD deviation, for a lookup that happens once per reversal
attempt on an indexed morph pair.

## R-004 — A closed period is resolved at posting time, not at draft time

`journal_entries.fiscal_period_id` is nullable in the ERD, which is the ERD
telling us a draft need not have a period yet.

**Decision**: `fiscal_period_id` stays null on a draft and is resolved from
`entry_date` at the moment of posting. Posting fails when no period contains the
date, and fails when the containing period is closed. A period closed *after* a
draft was created therefore blocks that draft at posting — which is the correct
moment to discover it.

**Consequence**: the same rule applies to reversals, because a reversal is a
posting. A reversal is validated against its own resolved period, not the
original's, so a reversal dated into a later open period succeeds while one into
a closed period does not.

## R-005 — Only leaf accounts may be posting targets

The ERD's `is_postable` is described as "Allows journal lines". If a parent were
postable, its own balance would mix directly-posted amounts with rolled-up
child amounts, and no report could separate them.

**Decision**: `is_postable` is refused on an account that has children, and is
cleared automatically the moment an account gains its first child. The seeder
produces postable leaves under non-postable parents.

**Rejected**: allowing it and disambiguating in reports. It pushes a permanent
reporting complication into every consumer to avoid one validation rule.

## R-006 — Two new fixed dashboard roles, registered centrally

`DashboardRole::fixedRoleNames()` is consulted by every module's
`isAdmin()`-bypass check, so a role added there automatically narrows every
other module's bypass.

**Decision**: add `ChiefAccountant` and `Accountant` to `DashboardRole`. The
split exists to keep FR-040's separation of duties: Accountant may record and
post; Chief Accountant may additionally reverse a posted entry and close or
reopen a period.

**(precedent)** Identical to spec 015's R-006 and spec 016's two support roles.

**Regression risk**: adding a case to `DashboardRole` narrows the admin bypass
for CRM, Inventory pricing, Employees, and Support. Any user holding a new
accounting role loses the blanket admin bypass in those modules and falls back
to explicit permission checks. This is the intended behaviour of that design,
and the existing cross-module policy tests cover it.

## R-007 — Account types are seeded with no management UI

The five accounting elements are fixed by double-entry accounting itself. A
sixth is meaningless; renaming one breaks the normal-balance semantics every
balance calculation depends on.

**Decision**: seed exactly five rows, expose no Filament resource, and surface
the type as a column and select filter on Chart of Accounts.

**(precedent)** `SlaPolicySeeder` seeds four fixed rows and `SlaPolicyResource`
offers List + Edit with no Create or Delete. This goes one step further and
offers no resource at all, because unlike an SLA target there is no field on an
account type an operator would legitimately tune.

## R-008 — Balances are computed on demand, not stored

A stored balance is a cache that must be invalidated on every posting and
reversal, and it can drift from the lines that justify it. The ledger's whole
value is that the lines *are* the truth.

**Decision**: compute balances with an aggregate query over posted lines,
signed by the account type's normal balance. A parent's balance includes its
descendants via a recursive descendant-id resolution on the already-loaded
account tree.

**Rejected**: a `balance` column maintained by the posting service. Fast, and
the first thing to silently disagree with reality after any direct write. If
performance ever demands it, the aggregate query is the thing a cache would be
derived from, so nothing here blocks adding one later.

**Scale note**: charts of accounts are small (tens to low hundreds of rows) and
this feature posts nothing automatically, so the aggregate cost is negligible at
the volumes this slice can produce.

## R-009 — Entry numbers follow the established sequence approach

**(precedent)** `InventoryOperationService::nextOperationNumber()` takes
`max(operation_number)` under `lockForUpdate()` inside the enclosing
transaction and formats `sprintf('OP-%06d', …)`.

**Decision**: mirror it exactly as `sprintf('JE-%06d', …)`, generated when the
draft is created so an accountant can refer to a draft by number before it is
posted. The unique index on `entry_number` is the backstop if two racing
creations ever defeat the lock.

**Rejected**: a date-prefixed number such as `JE-202608-0001`. It reads better
but makes the sequence non-monotonic across months and complicates the
`max()`-based generator for no requirement in any document.

## R-010 — Services take an explicit actor and self-check authorization

**(precedent)** Spec 016 established this for `App\Services\Support` and backed
it with an architecture test proving those services never call `auth()`.
Journal postings are at least as sensitive as ticket transitions.

**Decision**: every mutating method on `App\Services\Accounting` takes an
explicit `User $actor` and calls `Gate::forUser($actor)->authorize(…)` before
doing anything. The architecture test banning `auth` inside
`App\Services\Support` is extended to `App\Services\Accounting`, so a direct
service call can never be an authorization bypass.

## R-011 — Filament writes go through the service, enforced by an arch test

**(precedent)** `ArchTest` already forbids `App\Filament` from touching
`InventoryStock`/`InventoryMovement`, and forbids it from writing
`EmployeePerformanceScore`/`EmployeeSalaryCalculation` outside two read-only
resource namespaces.

**Decision**: add the same shape for the ledger. `App\Filament` may not use
`JournalEntryLine` at all outside the Journal Entries and Chart of Accounts
namespaces, which need it for the lines repeater and the account ledger read
surface. Posting and reversal are invoked only through
`JournalPostingService`, so the Filament layer never performs the status
transition itself.

## R-012 — Draft lines are edited as a Filament repeater; posting is an action

The draft/posted split maps cleanly onto Filament's form-versus-action divide.

**Decision**: a draft's lines are edited through a repeater on the entry form,
saved by ordinary Eloquent writes since a draft carries no invariant beyond its
foreign keys. Posting and reversing are `Action`s that call
`JournalPostingService` and surface its exception message as a notification.
A posted entry's form is read-only, so the repeater renders disabled.

**Consequence**: balance validation lives in the service and fires on post, not
in the form. A draft is *allowed* to be unbalanced — that is what makes it a
draft. Showing a live running total on the form is a convenience, not the
enforcement point.

## R-013 — No commercial document is wired to the ledger

ADR 0007 excludes automatic posting, and constitution 1.7.0 states that a ledger
existing is not permission to post to it.

**Decision**: this feature adds no observer, no event listener, and no call from
any other module's service into `JournalPostingService`. SC-008 is a test that
exercises the existing order, delivery, ticket-payment, and inventory paths and
asserts `journal_entries` is still empty afterward — a regression guard against
a future change wiring one up without its own ADR.

## Open Items Carried Into Planning

- **Currency.** `Docs/PRD.md` §12 still asks which currencies to seed. Until
  answered, amounts are single-currency with no currency column. Adding one
  later is additive.
- **Fiscal calendar.** No document specifies the fiscal year's start month, so
  periods are created manually rather than generated. A generator is additive if
  a calendar is later specified.
- **Trial balance.** Deliberately out of scope (ADR 0007), and the per-account
  balance surface in FR-035–038 is what a trial balance would be built from when
  `014-reporting-notifications-audit` arrives.
