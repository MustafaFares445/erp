# Feature Specification: Accounting Foundation — Chart of Accounts and Journal Entries

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

**Status**: Draft

**Input**: Extract the `006-chart-of-accounts-and-journals` entry from the canonical documentation set — `Docs/IMPLEMENTATION_PLAN.md` §6 (the load-bearing source: COA CRUD, journal entry CRUD/confirmation, balance validation, and a posting service interface for invoices, payments, tax, and credit notes, with the single acceptance criterion that an unbalanced entry cannot be confirmed), `Docs/SDD.md` §Chart of Accounts and §Journal Entries, `Docs/PRD.md` §5 (the Chart of Accounts and Journal Entries feature rows), and `Docs/database/ERD.md` (tables `account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, `journal_entry_lines`). The module fills two of the nine reserved-but-unbuilt sidebar slots already declared in `App\Filament\AdminModuleRegistry::groups()` under the `accounting` group — `admin.resources.chart_of_accounts` and `admin.resources.journal_entries`, whose translation keys already exist at `lang/en/admin.php:665-666` — and adds a third for fiscal periods, which the registry does not yet reserve. The four decisions (D1–D4) recorded in §Owner Decisions were taken by the project owner on 2026-08-18 and are binding; this specification encodes them rather than reopening them.

**Governance prerequisite**: This feature is **blocked** until ADR 0007 (`Docs/adr/0007-filament-accounting-dashboard.md`) is approved and the constitution amendment to 1.7.0 is merged. See §Governance Gate.

## Owner Decisions

These decisions were taken by the project owner on 2026-08-18 and are settled inputs, not open questions.

- **D1 — Slice at extraction entry 006 only.** This feature delivers the accounting foundation: account types, chart of accounts, fiscal periods, and manual journal entries with balance validation and a posting interface. It does **not** deliver `007-sales-flow-quotation-delivery-invoice`, `008-payments-stripe-manual-tax-recognition`, or `009-credit-notes`, and it wires **no** document to the ledger. The remaining six `accounting` navigation items stay placeholders.
- **D2 — The built `orders` table will be extended, not replaced.** Binding on `007`, not on this feature. Recorded in ADR 0007 §Decisions carried forward. No `sales_orders` table will be introduced; `order_lines` keeps its built name.
- **D3 — No `delivery_notes` table will be created.** Binding on `007`, not on this feature. The Delivery Notes surface will derive from `InventoryOperation` with `operation_type = 'delivery'`.
- **D4 — `barryvdh/laravel-dompdf` is the approved PDF dependency.** Binding on `007`, not on this feature. It is **not** installed here; nothing in this slice generates a PDF, and this feature must not add it to `composer.json`.

D2, D3, and D4 are recorded so the `007` specification does not reopen them. They create no work in this feature and no test in this feature may depend on them.

## Governance Gate

No implementation task below may begin until all three hold:

1. **ADR 0007 approved.** `Docs/adr/0007-filament-accounting-dashboard.md` exists with Status **Proposed**. It must be moved to **Accepted** by the project owner. Without it, a Filament dashboard for the Accounting module remains out of scope under constitution §Product Scope & Boundaries, and every Filament class in this feature would violate it.
2. **Constitution amended to 1.7.0.** The amendment adds the sixth narrow Filament exception and records that this work corresponds to the `006-chart-of-accounts-and-journals` extraction-order entry. Authored; merges with ADR 0007's approval.
3. **ERD deviations recorded.** Done. `Docs/database/ERD.md` now carries both deviations ADR 0007 authorises (see §ERD Divergence Register).

This feature does **not** depend on `017-purchasing-orders-suppliers`, which is specified but unimplemented and blocked on its own ADR 0006 approval. The two features are independent and may land in either order.

## ERD Divergence Register

| # | Table | Divergence | Authority |
|---|---|---|---|
| E-1 | `fiscal_periods` | The generic `status varchar(50) default 'draft/pending'` column is **omitted**. `is_closed` is the single lifecycle source of truth. Carrying both would let a period's state be recorded in two places that can disagree. | ADR 0007 |
| E-2 | `journal_entry_lines` | A `sort_order unsigned integer default 0` column is **added**, so an entry's lines render in a stable author-chosen order and a reversal's lines visibly pair with the original's. Presentational and additive only. | ADR 0007 |

**Not divergences**, recorded here because each looks like one:

- `journal_entries` **keeps** its `status` column. For this table it is the real `draft` → `posted` lifecycle, not the generator's boilerplate.
- Neither `journal_entries` nor `journal_entry_lines` gains a `deleted_at`. This matches the ERD and is required by the immutability rule in FR-024.
- The reversal link adds **no** column. A reversing entry points at the entry it reverses through the ERD's existing `source_type`/`source_id` morph (see FR-027).
- `account_types` gains no Filament resource. Its five rows are seeded reference data (FR-002), which is a UI decision, not a schema one.

## Scope

**In scope.** Seeded account types; the chart of accounts hierarchy with unique codes, an owning type, an optional parent, and `is_postable`/`is_active` flags; fiscal periods that can be closed and reopened; manual journal entries with a `draft` → `posted` lifecycle; balance validation on posting; posted-entry immutability with reversal as the only correction path; a `JournalPostingService` exposing `post()` and `reverse()`; per-account balances and a single-account ledger read surface; `accounting.*` permissions and two new fixed dashboard roles; audit logging of postings, reversals, and period closes.

**Out of scope** — every item in ADR 0007 §Out of scope, most importantly: any API surface; AR/AP subledgers; bills, expenses, refunds, tax definitions; financial reports of any kind including a trial balance; **automatic posting from any commercial document**; multi-currency; cost accounting or inventory valuation; budgets; bank reconciliation; a year-end retained-earnings close; opening-balance import; recurring entries; and approval workflow beyond `draft` → `posted`.

One exclusion deserves emphasis because it is the most natural thing to add and the most damaging to add quietly: **nothing in this feature may cause any existing document to write a journal entry.** No observer, no event listener, no service call from the Inventory, Support, CRM, Employees, or Orders modules. The posting interface is built and left unwired.

## User Scenarios and Testing

### User Story 1 — Enforce Accounting Roles and Permissions (Priority: P1)

A System Admin grants a colleague the Accountant role. That colleague can record and post journal entries but cannot close a fiscal period or reverse a posted entry; those need the Chief Accountant role.

**Why this priority**: Every other story depends on the permission catalogue existing. Ledger data is the most sensitive in the system, and separation of duties between posting and correcting is the control that makes it trustworthy.

**Acceptance scenarios**:

1. Given the accounting permission seeder has run, when a System Admin opens the Chart of Accounts, Journal Entries, and Fiscal Periods pages, then all three load.
2. Given a user holds only the Accountant role, when they open a posted journal entry, then no Reverse action is offered and calling the reversal service directly is refused.
3. Given a user holds only the Accountant role, when they open a fiscal period, then no Close action is offered and calling the close service directly is refused.
4. Given a user holds only the Reviewer role, when they open any accounting page, then it loads read-only with no create, edit, post, reverse, or close action available.
5. Given a user holds no accounting permission, when they open any accounting page, then access is refused and the sidebar omits the link.

### User Story 2 — Maintain the Chart of Accounts (Priority: P1)

An accountant reviews the seeded chart, adds a sub-account under an existing parent, and marks an unused account inactive.

**Why this priority**: Journal entries cannot be recorded without accounts to post to. The chart is the module's foundational data.

**Acceptance scenarios**:

1. Given the chart is seeded, when the accountant opens Chart of Accounts, then accounts are listed with code, name, type, parent, postable and active flags, and a current balance.
2. Given the accountant creates an account with a code already in use, when they save, then a validation error names the duplicate code and no row is created.
3. Given the accountant creates an account under a parent, when they save, then the child is created and the parent is automatically no longer postable.
4. Given an account has children, when the accountant tries to mark it postable, then a validation error explains that only leaf accounts may be posting targets.
5. Given an account has at least one posted journal line, when the accountant tries to delete it, then the deletion is refused with a message naming the posted lines.
6. Given an account has at least one posted journal line, when the accountant marks it inactive, then the change succeeds — inactive blocks *future* postings and never rewrites history.
7. Given the accountant tries to set an account's parent to one of its own descendants, when they save, then a validation error reports the cycle and nothing changes.

### User Story 3 — Open and Close Fiscal Periods (Priority: P1)

A chief accountant creates the twelve monthly periods for a year, then closes January once its numbers are final.

**Why this priority**: A journal entry needs a period to belong to, and closing is the only mechanism that stops a correction being backdated into already-reported figures.

**Acceptance scenarios**:

1. Given no periods exist, when the chief accountant creates one with a name, start date, and end date, then it is created open.
2. Given a period exists, when the chief accountant creates a second whose dates overlap it, then a validation error names the overlapping period and nothing is created.
3. Given a period whose end date precedes its start date, when it is saved, then a validation error is returned.
4. Given an open period with posted entries, when the chief accountant closes it, then it is marked closed and an audit entry records who closed it and when.
5. Given a closed period, when anyone tries to post an entry dated inside it, then posting is refused naming the closed period.
6. Given a closed period, when anyone tries to reverse an entry posted inside it, then the reversal is refused — a reversal is itself a posting and obeys the same rule.
7. Given a closed period, when the chief accountant reopens it, then it becomes open again and an audit entry records the reopening.
8. Given a period with at least one journal entry, when the chief accountant tries to delete it, then the deletion is refused.

### User Story 4 — Record and Post a Balanced Journal Entry (Priority: P1)

An accountant records a manual entry with two lines, saves it as a draft, checks it, and posts it.

**Why this priority**: This is the feature's core transaction and carries the single acceptance criterion the documentation states outright.

**Acceptance scenarios**:

1. Given postable accounts and an open period exist, when the accountant creates an entry with an entry date, description, and two balanced lines, then it is saved as a draft with a generated entry number.
2. Given a draft entry, when the accountant edits its date, description, or lines, then the changes save — drafts are freely editable.
3. Given a draft whose debit total does not equal its credit total, when the accountant posts it, then posting is refused, the entry stays a draft, and the error states both totals. *(This is `Docs/IMPLEMENTATION_PLAN.md` §6's stated acceptance criterion.)*
4. Given a draft with a line carrying both a debit and a credit amount, when the accountant posts it, then posting is refused naming the offending line.
5. Given a draft with a line carrying neither a debit nor a credit amount, when the accountant posts it, then posting is refused naming the offending line.
6. Given a draft with fewer than two lines, when the accountant posts it, then posting is refused.
7. Given a draft with a line targeting a non-postable or inactive account, when the accountant posts it, then posting is refused naming the account.
8. Given a draft whose entry date falls in no existing fiscal period, when the accountant posts it, then posting is refused.
9. Given a balanced draft in an open period, when the accountant posts it, then its status becomes posted, its fiscal period is stamped, and an audit entry records the posting.

### User Story 5 — Correct a Posted Entry by Reversal (Priority: P1)

An accountant notices a posted entry hit the wrong account. A chief accountant reverses it and records a corrected entry.

**Why this priority**: Without it the module has no correction path at all, which would make posting effectively irreversible and push users toward editing history directly.

**Acceptance scenarios**:

1. Given a posted entry, when anyone tries to edit its date, description, or lines, then the change is refused at both the service layer and the model layer.
2. Given a posted entry, when anyone tries to delete it, then the deletion is refused.
3. Given a posted entry, when a chief accountant reverses it, then a new posted entry is created whose lines mirror the original with debits and credits swapped, whose `source` points at the original entry, and whose own line total balances.
4. Given a posted entry that has already been reversed, when a chief accountant reverses it again, then the second reversal is refused naming the existing one.
5. Given a posted entry inside a closed period, when a chief accountant reverses it, then the reversal is refused.
6. Given a reversal is created, when the chief accountant supplies a reversal date, then the reversing entry uses that date rather than the original's — a correction made in a later period must be able to land in that later period.
7. Given a posted entry and its reversal, when their accounts' balances are computed, then the pair contributes zero net movement.

### User Story 6 — Inspect an Account's Balance and Ledger (Priority: P2)

An accountant opens an account to see its posted lines and its running balance.

**Why this priority**: Without it the ledger cannot be verified from the dashboard at all — but it is a read surface, so it can follow the write path.

**Acceptance scenarios**:

1. Given posted entries exist, when the accountant opens Chart of Accounts, then each account shows a balance computed from posted lines only.
2. Given an account with draft entries against it, when its balance is computed, then draft lines are excluded.
3. Given an account of a debit-normal type, when its debits exceed its credits, then the balance is positive; for a credit-normal type the sign convention is inverted, so a normal balance always reads positive.
4. Given a parent account, when its balance is shown, then it includes its descendants' balances.
5. Given the accountant opens a single account, when they view its ledger, then its posted lines are listed with entry number, date, description, debit, credit, and the account's running balance.

### Edge Cases

- An entry with lines summing to equal totals but where one line is `0.00 / 0.00` — rejected by FR-021 (every line carries exactly one non-zero side).
- Two accountants posting the same draft concurrently — the second attempt finds it already posted and is refused; the row is locked for the status transition.
- Two accountants creating accounts with the same code concurrently — the unique index rejects the loser, surfaced as a validation error rather than a 500.
- Two accountants creating overlapping periods concurrently — overlap is checked inside the same transaction that inserts, so the loser is rejected.
- Rounding: a two-line entry of `33.33 / 33.33` balances; `33.33 / 33.34` does not and is rejected. All comparison is on integer minor units, never on floats.
- An account is marked inactive after a draft targeting it was created — the draft still exists, but posting it is refused (FR-022), which is the correct time to notice.
- A fiscal period is closed after a draft dated inside it was created — the draft still exists, but posting it is refused (FR-023).
- Deleting a draft entry — permitted, and it leaves no ledger trace, because a draft was never in the ledger.
- A reversal's own reversal — refused by FR-028; the correct action is a fresh entry.

## Requirements

### Functional Requirements

**Account types (seeded reference data)**

- **FR-001**: The system MUST store account types with a name and a normal balance of either debit or credit.
- **FR-002**: The system MUST seed exactly five account types — Asset (debit), Liability (credit), Equity (credit), Income (credit), Expense (debit) — and MUST NOT expose a Filament resource for creating, editing, or deleting them. They are surfaced as a column and filter on Chart of Accounts.
- **FR-003**: The seeder MUST be idempotent, so re-running it neither duplicates nor rewrites rows.

**Chart of accounts**

- **FR-004**: The system MUST store accounts with a unique code, a name, an owning account type, an optional parent account, an `is_postable` flag, and an `is_active` flag, all blameable and soft-deletable.
- **FR-005**: The system MUST reject an account whose code duplicates an existing account's, including a soft-deleted one, with a validation error naming the code.
- **FR-006**: The system MUST reject an account whose parent is itself or any of its own descendants, with a validation error reporting the cycle.
- **FR-007**: The system MUST reject making an account postable while it has child accounts. Only leaf accounts may be posting targets.
- **FR-008**: The system MUST clear `is_postable` on an account at the moment it gains its first child.
- **FR-009**: The system MUST refuse to delete an account that has child accounts.
- **FR-010**: The system MUST refuse to delete an account referenced by any journal entry line, draft or posted.
- **FR-011**: The system MUST permit marking an account inactive regardless of its posted history. Inactive blocks future postings only; it never alters recorded history.
- **FR-012**: The system MUST seed a starting chart of accounts covering the five types with conventional codes, idempotently.

**Fiscal periods**

- **FR-013**: The system MUST store fiscal periods with a name, a start date, an end date, and an `is_closed` flag, blameable.
- **FR-014**: The system MUST reject a period whose end date precedes its start date.
- **FR-015**: The system MUST reject a period whose date range overlaps an existing period's, with a validation error naming the overlap.
- **FR-016**: The system MUST allow a period to be closed and reopened, and MUST record both in the audit log with the acting user.
- **FR-017**: The system MUST refuse to delete a period that has any journal entry.
- **FR-018**: The system MUST resolve a journal entry's fiscal period from its entry date at the moment of posting, and MUST refuse to post when no period contains that date.

**Journal entries and posting**

- **FR-019**: The system MUST store journal entries with a generated unique entry number, an entry date, an optional description, an optional `source` morph, a `draft`/`posted` status, a nullable fiscal period, and blameable columns. It MUST NOT support soft deletes.
- **FR-020**: The system MUST refuse to post an entry whose total debits do not equal its total credits, leaving it a draft and reporting both totals. *(`Docs/IMPLEMENTATION_PLAN.md` §6.)*
- **FR-021**: The system MUST refuse to post an entry where any line carries both a debit and a credit amount, or neither, naming the offending line.
- **FR-022**: The system MUST refuse to post an entry where any line targets a non-postable or inactive account, naming the account.
- **FR-023**: The system MUST refuse to post an entry whose resolved fiscal period is closed, naming the period.
- **FR-024**: The system MUST refuse to post an entry with fewer than two lines.
- **FR-025**: The system MUST treat a posted entry as immutable: its date, description, status, source, and lines cannot be changed and it cannot be deleted. This MUST be enforced in the posting service and again at the model layer, so a direct write cannot bypass it.
- **FR-026**: The system MUST allow a draft entry and its lines to be freely edited and deleted.
- **FR-027**: The system MUST create a reversal as a new posted entry whose lines mirror the original's with debit and credit swapped, and whose `source` morph points at the original entry. No dedicated reversal column is added.
- **FR-028**: The system MUST refuse to reverse an entry that is not posted, or that already has a reversal, naming the existing reversal.
- **FR-029**: The system MUST allow a reversal to carry a caller-supplied date defaulting to the original's, and MUST validate the reversal against its own resolved fiscal period, so a reversal into a closed period is refused.
- **FR-030**: The system MUST compare and total money on integer minor units, never on floating-point values.
- **FR-031**: The system MUST perform posting and reversal inside a database transaction, locking the entry row for the status transition so two concurrent posts cannot both succeed.
- **FR-032**: The system MUST expose posting and reversal through `App\Services\Accounting\JournalPostingService`, taking an explicit acting `User` and self-checking authorization against it.
- **FR-033**: The system MUST record an audit log entry for every posting, reversal, period close, and period reopen, attributed to the acting user, via `spatie/laravel-activitylog` per ADR 0005.
- **FR-034**: The system MUST NOT post automatically from any existing document. No observer, listener, or call from another module may write a journal entry in this feature.

**Balances and ledger**

- **FR-035**: The system MUST compute an account's balance from posted lines only, excluding drafts.
- **FR-036**: The system MUST present a balance signed by the account type's normal balance, so an account holding its normal balance reads positive.
- **FR-037**: The system MUST include descendants' balances in a parent account's reported balance.
- **FR-038**: The system MUST offer, on a single account, a list of its posted lines with entry number, date, description, debit, credit, and a running balance.

**Permissions and navigation**

- **FR-039**: The system MUST define an `accounting.*` permission catalogue as a string-backed enum that is the single source of truth for the seeder and the policies.
- **FR-040**: The system MUST separate posting from correcting: the permission to post an entry MUST NOT imply the permission to reverse one or to close a period.
- **FR-041**: The system MUST register two new fixed dashboard roles, Chief Accountant and Accountant, in the central `DashboardRole` enum so every module's admin-bypass check narrows consistently.
- **FR-042**: The system MUST add a Fiscal Periods navigation item to the `accounting` group, and MUST leave the six unbuilt items in that group as placeholders.
- **FR-043**: The system MUST provide English labels for all new navigation items and resource fields. Arabic keys fall back to English per the convention recorded at the top of `lang/ar/admin.php`.

### Key Entities

- **AccountType** — one of the five accounting elements, with its normal balance. Seeded, never user-created.
- **ChartAccount** — a node in the account hierarchy: code, name, owning type, optional parent, postable and active flags. Soft-deletable, blameable. The posting target.
- **FiscalPeriod** — a named date range that can be closed. Owns the "may anything still be posted here?" question.
- **JournalEntry** — a balanced set of lines with a `draft` → `posted` lifecycle, an entry date, a resolved fiscal period, and an optional polymorphic source. Immutable once posted.
- **JournalEntryLine** — one debit-or-credit amount against one account, ordered within its entry.

## Success Criteria

### Measurable Outcomes

- **SC-001**: An unbalanced entry cannot reach the ledger by any path — the Filament action, the service, or a direct model write — and each path has a test proving it.
- **SC-002**: A posted entry's date, description, status, source, and lines cannot be modified and it cannot be deleted, proven at both the service and model layer.
- **SC-003**: An entry posted into a closed period is impossible, and so is a reversal into one.
- **SC-004**: The Chart of Accounts, Journal Entries, and Fiscal Periods pages replace three placeholders in the `accounting` navigation group; the other six still render `ModulePlaceholder`.
- **SC-005**: A user with the Accountant role can post but cannot reverse or close, proven by policy tests and by a Filament test asserting the actions are absent.
- **SC-006**: An account's reported balance equals the signed sum of its own and its descendants' posted lines, and a posted entry paired with its reversal contributes zero.
- **SC-007**: `composer test` passes with no new PHPStan baseline entries, and an architecture test proves no `App\Filament` class writes a journal row directly.
- **SC-008**: No commercial document writes a journal entry. Proven by a test asserting that exercising the existing order, delivery, ticket-payment, and inventory paths creates no `journal_entries` row.

## Assumptions

- The seeded chart of accounts is a conventional starting point, not a prescribed structure. No document in the canonical set specifies account codes, so the seeder's codes are chosen by convention and are user-editable afterward.
- Single currency. `Docs/PRD.md` §12 still lists "What currencies and tax rates should be seeded first?" as an open question; until it is answered, all amounts are in one implied currency and no currency column is added.
- The five accounting elements are universal, so seeding them without a management UI removes a class of data-integrity error rather than removing a capability the owner wanted.
- Fiscal periods are created by an accountant rather than generated automatically. No document specifies a fiscal calendar, and guessing one would be worse than a short manual step.
- "Confirmation" in `Docs/IMPLEMENTATION_PLAN.md` §6 and "posting" here are the same operation. The plan's vocabulary is inconsistent with standard accounting usage; this spec uses `post` throughout and treats the plan's `confirmed` as its synonym.
- Reversal, not editing, is the correction path. The plan does not say so explicitly, but it follows from the immutability requirement that a ledger implies and that ADR 0007 makes binding.

## Dependencies and Integration Points

**Depends on (built).** `002-database-foundation`; `003-auth-users-spatie-access` for `User`, `spatie/laravel-permission`, and the `DashboardRole` catalogue; ADR 0005's `spatie/laravel-activitylog` for the audit trail; the `AdminModuleRegistry` and `AdminPanelServiceProvider` navigation contract; the `TracksBlameable` concern.

**Depended on by (unbuilt).** `007-sales-flow-quotation-delivery-invoice`, `008-payments-stripe-manual-tax-recognition`, and `009-credit-notes` will each call `JournalPostingService::post()` with their own document as the `source`. This feature defines that interface and nothing more.

**Explicitly not integrated.** Orders, deliveries, shipments, tickets and their payment links, inventory operations and movements, purchasing, and payroll. None of them writes a journal entry as a result of this feature, and SC-008 exists to keep it that way.

**New dependencies.** None. No Composer or npm package is added.
