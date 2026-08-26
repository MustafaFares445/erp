# Feature Specification: Accounting Financial Reports

**Feature Directory**: `020-accounting-financial-reports`

**Created**: 2026-08-23

**Status**: Draft

**Input**: Deliver the read-only reporting layer over the general ledger built by `018-chart-of-accounts-journals`. The load-bearing sources are `Docs/PRD.md` §5 FR-023 ("Reports must cover sales, invoices, payments, tax, inventory, employees, tickets, and CRM"), `Docs/SDD.md` §Reports and Notifications together with §Chart of Accounts and §Journal Entries, and `Docs/database/ERD.md` tables `account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, and `journal_entry_lines`. No table is added, altered, or removed by this feature. The `admin.resources.financial_reports` navigation slot and its English label already exist (`lang/en/admin.php:888`) and are registered twice in `App\Filament\AdminModuleRegistry::groups()`; resolving that duplication is in scope (see §Navigation Defect Register). The five decisions D1–D5 recorded in §Owner Decisions were taken by the project owner on 2026-08-23 and are binding; this specification encodes them rather than reopening them.

**Governance prerequisite**: ✅ **CLEARED 2026-08-23.** ADR 0009 (`Docs/adr/0009-accounting-financial-reports.md`) is **Accepted**, the constitution is amended to **1.9.0** with the eighth narrow Filament dashboard exception recorded, and `Docs/PRD.md` §11 carries the qualifier. Implementation is unblocked. See §Governance Gate for what the gate required and what it deliberately did not relax.

## Owner Decisions

These decisions were taken by the project owner on 2026-08-23 and are settled inputs, not open questions.

- **D1 — Slice at reporting only.** This feature delivers five read-only statements over the posted ledger and nothing else. It does **not** deliver accounts-receivable or accounts-payable subledgers, supplier bills, expenses, refunds, or tax definitions, and it wires **no** document to the ledger. The `accounts_receivable`, `accounts_payable`, `bills`, `expenses`, `refunds`, and `taxes` navigation items stay placeholders.
- **D2 — A new ADR 0009, not an amendment to ADR 0007.** ADR 0007 §Out of scope permits either form ("their own specification and either a separate ADR or an explicit amendment to ADR 0007"). A separate ADR is chosen because it matches the one-ADR-per-scope-expansion precedent of 0001–0008 and because a standalone document is easier to review than a diff against an ADR already marked Accepted.
- **D3 — Financial Reports lives in the shared `reports` navigation group, not in `accounting`.** The duplicate `accounting` registration is removed. This matches every sibling report resource already built — inventory, employee, support, and purchasing — and the fifth, operational, which is reserved in that group as a placeholder. It matches the convention already recorded in the `PurchasingReportResource` docblock: "`AdminModuleRegistry` already establishes that every module's reports live together." The accounting group is left with six placeholders instead of seven. The alternative — keep the `accounting` entry and drop the `reports` one — is a one-line registry change and is recorded in §Navigation Defect Register should the owner prefer it.
- **D4 — The Balance Sheet's computed equity line is a single accumulated figure, not a retained-earnings/current-year split.** The schema has no fiscal-year grouping — `fiscal_periods` is a flat list of named date ranges with no year parent — so a split would require inventing a fiscal-year concept, which is out of scope. One line, computed from all income and expense movement from ledger inception through the as-of date, is both correct and implementable. See FR-034.
- **D5 — One resource, five report types, selected on the page.** A single Financial Reports surface offering a report-type choice, mirroring the established `InventoryReportType` pattern, rather than five sibling navigation entries. The `financial_reports` slot is one slot; five would need five labels, five permissions decisions, and five registry entries the registry does not reserve.

## Governance Gate — CLEARED

Both conditions held on 2026-08-23. Recorded here rather than deleted, because what the gate required is also the list of things the approval did *not* grant.

1. ✅ **ADR 0009 Accepted.** `Docs/adr/0009-accounting-financial-reports.md` moved from Proposed to **Accepted** by the project owner. This was not a formality. Financial reports of every kind — *explicitly including a trial balance, profit and loss, and balance sheet* — were named in the out-of-scope list of ADR 0007, of constitution §Product Scope & Boundaries, and of `Docs/PRD.md` §11. Without ADR 0009, every Filament class in this feature would violate the constitution.
2. ✅ **Constitution amended to 1.9.0.** The eighth narrow Filament dashboard exception is recorded, scoped to read-only reporting over the posted ledger, together with a §Specification Governance entry noting that this work has **no** corresponding entry in the documented extraction order (`001`–`014`). It is therefore an owner-prioritised addition, as `017-purchasing-orders-suppliers` was, not a reordering. It skips no prerequisite: its only hard dependency, `006-chart-of-accounts-and-journals`, is built as `018`.
3. ✅ **`Docs/PRD.md` §11 qualified.** The out-of-scope entry now records the ADR 0009 exception in the same form §11 already uses for ADR 0006 and ADR 0007, and states that every other report named there — aged receivables, sales, tax — remains out of scope.

The amendment MUST also state what this feature does not relax, because a reporting layer is the most natural place to quietly acquire posting behaviour:

- ADR 0007's **no-automatic-posting** rule is untouched. No document posts to the ledger as a result of this feature.
- The Purchasing prohibition recorded at constitution 1.6.0 and reaffirmed at 1.7.0 is untouched. A ledger that can now be *reported on* is still not permission to *post to* it.

## Navigation Defect Register

| # | Defect | Detail | Resolution |
|---|---|---|---|
| N-1 | `admin.resources.financial_reports` is registered **twice** | Once in the `accounting` group (`AdminModuleRegistry.php:137`) and once in the shared `reports` group (`AdminModuleRegistry.php:236`). Both currently resolve to `ModulePlaceholder`, distinguished only by their `?group=` query parameter, so the duplication is invisible today. The moment `FinancialReportResource` exists, `resolveLink()` resolves both slots to the same resource URL: the item renders twice in the sidebar and `activeGroupKey()` cannot say which group the request belongs to. | Remove the `accounting` entry, keep the `reports` entry (D3). Add a regression test asserting no navigation label appears in more than one group, so the class of defect cannot recur for any module. |

The regression test is the point of this register entry. Fixing one duplicate by hand fixes one duplicate; asserting the invariant fixes the next one too.

## Scope

**In scope.** Five read-only statements over posted journal lines — Trial Balance, General Ledger, Profit and Loss, Balance Sheet, and Posting Register; date-range and as-of-date scoping, with fiscal-period selection as a convenience; parent-account roll-up and the normal-balance sign convention reused from `018`; integer-minor-unit arithmetic throughout; a streamed CSV export per report gated on the same permission as the screen; one new `accounting.report.view` permission added to the existing `AccountingPermission` catalogue; resolution of navigation defect N-1 and a regression test for its whole class.

**Out of scope.** Every item in ADR 0009 §Out of scope, most importantly:

- **Any write of any kind.** This feature creates, edits, posts, reverses, and deletes nothing. It is read-only end to end.
- **Automatic posting from any commercial document.** ADR 0007's rule stands; `tests/Feature/Accounting/NoAutomaticPostingTest.php` must continue to pass unchanged.
- **A year-end retained-earnings close.** Current accumulated earnings are computed for presentation only. Nothing is posted to Retained Earnings (`3200`).
- **Accounts-receivable and accounts-payable subledgers and aging reports.** Receivables are specified separately as `021-accounting-receivables-tax-refunds`, which depends on the invoices and payments delivered by `019-sales-lifecycle-payments-credits`. Payables are specified separately as `022-accounting-payables-expenses-bills`.
- **Supplier bills, expenses, refunds, and tax definitions.** Bills and expenses belong to `022`; refunds and the tax register belong to `021`. Tax *rate* configuration is settled by `019` decision D7 as a `sales_settings` default rather than a `tax_definitions` table, and that navigation slot stays a placeholder.
- **Any API surface.**
- Cash-flow statement; budget-versus-actual; comparative or multi-period columns; consolidation; segment or dimension reporting; multi-currency and revaluation; cost accounting, inventory valuation, and cost-of-goods-sold derivation; bank reconciliation; PDF rendering; scheduled or emailed report delivery; saved report definitions; and report-result caching.
- **No new dependency.** No Composer or npm package is added. In particular `barryvdh/laravel-dompdf` is **not** installed here — ADR 0007 D4 reserves it for the sales flow, and nothing in this feature generates a PDF.

One exclusion deserves emphasis because it is the most natural thing to add and the most damaging to add quietly: **a report may not correct the data it reports on.** If a trial balance does not foot, the report says so and stops. It does not adjust, suppress, round, or plug the difference. A reporting layer that can silently alter what it reports is worse than no reporting layer, because it destroys the only reason to trust it.

## User Scenarios & Testing

### User Story 1 — Grant and Withhold Report Access (Priority: P1)

A System Admin grants a colleague permission to read financial statements. Another colleague, who may post journal entries, is not granted it and cannot reach any statement or export.

**Why this priority**: Every other story depends on the permission existing. A financial statement aggregates the most sensitive data in the system into its most portable form, so who may read it — and who may download it — is the first question, not the last.

**Independent Test**: Fully testable by seeding the permission catalogue, assigning roles, and asserting page access and export access for each role. Delivers the access-control guarantee on its own, before any report renders a number.

**Acceptance Scenarios**:

1. **Given** the accounting permission seeder has run, **When** a System Admin opens Financial Reports, **Then** the page loads and every report type is selectable.
2. **Given** a user holds `accounting.report.view`, **When** they open Financial Reports, **Then** every report renders and every export succeeds.
3. **Given** a user holds `accounting.journal-entry.post` and `accounting.ledger.view` but **not** `accounting.report.view`, **When** they open Financial Reports, **Then** access is refused and the navigation link is absent.
4. **Given** a user holds only the Reviewer role, **When** they open Financial Reports, **Then** every report renders read-only with no action that changes any record.
5. **Given** a user without `accounting.report.view`, **When** they request an export URL directly rather than through the page, **Then** the export is refused.

---

### User Story 2 — Prove the Ledger Foots with a Trial Balance (Priority: P1)

An accountant selects a date range and reads the trial balance: every account with movement, its opening balance, its debits and credits for the range, and its closing balance — with the column totals shown so the ledger's integrity is visible rather than assumed.

**Why this priority**: The trial balance is the report the other three statements are checked against. It is also the only one that can *prove* something rather than merely present it, and that proof is the whole reason to build a reporting layer over a ledger.

**Independent Test**: Fully testable by posting a known set of entries and asserting the report's rows, totals, and footing proof. Delivers verifiable ledger integrity on its own.

**Acceptance Scenarios**:

1. **Given** posted entries exist in the range, **When** the accountant opens the trial balance, **Then** each account with movement or a non-zero opening balance is listed with its code, name, type, opening balance, range debits, range credits, and closing balance.
2. **Given** any set of posted entries, **When** the trial balance is produced, **Then** total range debits equal total range credits, and the report displays that equality as an explicit footing proof.
3. **Given** a posted entry and its reversal both fall inside the range, **When** the trial balance is produced, **Then** the pair contributes equal amounts to both column totals and zero to every closing balance.
4. **Given** a draft entry dated inside the range, **When** the trial balance is produced, **Then** none of its lines appear and no total changes.
5. **Given** an account with no movement in the range and a zero opening balance, **When** the trial balance is produced, **Then** the account is omitted rather than listed as a row of zeroes.
6. **Given** the column totals somehow differ, **When** the trial balance is produced, **Then** the discrepancy is displayed prominently as an error rather than hidden, rounded away, or corrected.

---

### User Story 3 — Read a Profit and Loss for a Period (Priority: P1)

An accountant selects a fiscal period and reads income and expense movement for it, subtotalled by account type, with the resulting net profit or loss.

**Why this priority**: It is the statement most often asked for, and it is the input to the Balance Sheet's computed equity line, so the Balance Sheet cannot be trusted before it is.

**Independent Test**: Fully testable by posting income and expense entries across period boundaries and asserting subtotals and the net figure. Delivers period performance reporting on its own.

**Acceptance Scenarios**:

1. **Given** posted income and expense entries in the range, **When** the accountant opens the profit and loss, **Then** income accounts and expense accounts are listed separately, each subtotalled, with net profit or loss as income less expense.
2. **Given** asset, liability, and equity accounts also carry movement in the range, **When** the profit and loss is produced, **Then** none of them appears — the statement covers income and expense only.
3. **Given** expenses exceed income in the range, **When** the profit and loss is produced, **Then** the net figure is presented as a loss and is unambiguously distinguishable from a profit.
4. **Given** a parent income account with posting children, **When** the profit and loss is produced, **Then** the parent's figure includes its descendants' and the children are not double-counted in the subtotal.
5. **Given** the ledger holds no income or expense movement in the range, **When** the profit and loss is produced, **Then** it renders with zero subtotals and a zero net figure rather than an error or an empty screen.

---

### User Story 4 — Read a Balance Sheet as of a Date (Priority: P1)

An accountant picks a date and reads assets, liabilities, and equity as of that date, including a clearly labelled computed earnings line, with the accounting equation shown as a proof.

**Why this priority**: It is the second statement every reader expects, and the one that cannot be produced naively: with no year-end close in the system, equity is incomplete unless accumulated earnings are computed. Getting this wrong produces a statement that silently does not balance, which is worse than not shipping it.

**Independent Test**: Fully testable by posting across asset, liability, equity, income, and expense accounts and asserting the equation holds at several as-of dates. Delivers position reporting on its own.

**Acceptance Scenarios**:

1. **Given** posted entries exist, **When** the accountant opens the balance sheet as of a date, **Then** asset, liability, and equity accounts are listed with balances as of that date, each group subtotalled.
2. **Given** income and expense accounts carry movement up to that date, **When** the balance sheet is produced, **Then** their net is presented as a single computed equity line, labelled to state that it is computed and not posted.
3. **Given** any as-of date, **When** the balance sheet is produced, **Then** total assets equal total liabilities plus total posted equity plus the computed earnings line, and the report displays that equality as an explicit proof.
4. **Given** an as-of date earlier than the first posted entry, **When** the balance sheet is produced, **Then** every subtotal is zero and the equation still holds.
5. **Given** a posted entry dated after the as-of date, **When** the balance sheet is produced, **Then** it is excluded entirely.
6. **Given** a profit and loss for a range ending on the as-of date and a balance sheet at that date, **When** both are produced over a ledger whose first entry falls inside that range, **Then** the profit-and-loss net figure equals the balance sheet's computed earnings line.

---

### User Story 5 — Trace a Figure Through the General Ledger (Priority: P2)

An accountant sees a number they do not expect on the trial balance, opens the general ledger filtered to that account and range, and reads the individual posted lines that produced it.

**Why this priority**: It is what makes the P1 statements actionable rather than merely readable — but it is a drill-down, so it can follow the statements it explains.

**Independent Test**: Fully testable by posting entries to several accounts and asserting the filtered line list and its running balance. Delivers ledger drill-down on its own.

**Acceptance Scenarios**:

1. **Given** posted entries exist, **When** the accountant opens the general ledger for a range, **Then** posted lines are listed in date order with entry number, entry date, account code and name, description, debit, credit, and a per-account running balance.
2. **Given** the accountant filters to one account, **When** the general ledger is produced, **Then** only that account's own lines appear and the running balance restarts from that account's opening balance for the range.
3. **Given** the accountant filters to a parent account, **When** the general ledger is produced, **Then** the descendants' lines are included and each line states which account it was posted to.
4. **Given** a draft entry exists, **When** the general ledger is produced, **Then** none of its lines appear.
5. **Given** an account's closing balance on the trial balance, **When** the general ledger is read for the same account and range, **Then** the last running-balance figure equals that closing balance.

---

### User Story 6 — Trace an Entry Back to Its Source in the Posting Register (Priority: P2)

An auditor reads the chronological register of posted entries — each with its lines, its fiscal period, who posted it, and what document it came from — and reconciles a statement figure back to the act that created it.

**Why this priority**: It closes the audit loop, and it is the surface that will show commercial documents once they begin posting. It is a read-only register, so it follows the statements.

**Independent Test**: Fully testable by posting entries with and without a source morph and asserting the register's rows and source rendering. Delivers the audit trail on its own.

**Acceptance Scenarios**:

1. **Given** posted entries exist, **When** the auditor opens the posting register for a range, **Then** entries are listed in date and entry-number order with their lines, resolved fiscal period, and the user who posted them.
2. **Given** an entry has no source, **When** the register is produced, **Then** the source column is shown as empty rather than as an error or a placeholder that looks like data.
3. **Given** an entry is a reversal whose source points at the entry it reverses, **When** the register is produced, **Then** the source column identifies that original entry by its entry number.
4. **Given** an entry's source morph points at a model type the register does not specifically recognise, **When** the register is produced, **Then** the source renders as a readable type-and-identifier label and the register does not fail. *(This is the forward-compatibility guarantee: when `019-sales-lifecycle-payments-credits` begins posting invoices, payments, and credit notes, those documents must appear here with no change to this feature.)*
5. **Given** an entry's source morph points at a record that no longer resolves, **When** the register is produced, **Then** the row renders with the unresolved reference labelled as such and the register does not fail.
6. **Given** a draft entry exists, **When** the register is produced, **Then** it does not appear — the register is a record of postings, not of intentions.

---

### User Story 7 — Export a Statement for Circulation (Priority: P2)

An accountant exports the statement on screen as a spreadsheet-readable file to attach to a month-end pack.

**Why this priority**: It is how the report leaves the system and the main reason a reader would otherwise re-key figures by hand. It depends on the reports existing, so it follows them.

**Independent Test**: Fully testable by requesting each export and asserting its rows, its headers, and its permission gate. Delivers circulation on its own.

**Acceptance Scenarios**:

1. **Given** a user holds `accounting.report.view` and a report is on screen, **When** they export it, **Then** a file downloads whose rows and totals match the report exactly, including its footing or equation proof.
2. **Given** the report was scoped to a date range or as-of date, **When** it is exported, **Then** the export covers exactly that scope and states it, so a detached file cannot be misread as covering a different period.
3. **Given** a user does not hold `accounting.report.view`, **When** they request any export, **Then** it is refused. *(An export that checked a weaker rule than the screen it exports would be a way to read the report without being allowed to see it.)*
4. **Given** the report is empty for the chosen scope, **When** it is exported, **Then** the file downloads containing its headers and zero data rows rather than failing.

---

### Edge Cases

- **A range whose end precedes its start** — refused with a validation error before any aggregation runs; an inverted range must not silently return an empty report that reads as "no activity".
- **A single-day range** where start equals end — valid, and includes entries dated exactly that day.
- **Boundary dates** — an entry dated exactly on the start date and one dated exactly on the end date are both included; one dated the day before the start and one the day after the end are both excluded. The comparison is inclusive at both ends.
- **An entirely empty ledger** — every report renders with zero rows and zero totals; no error, no division by zero, and the balance-sheet equation still holds trivially.
- **An account whose type is missing** — prevented by the non-nullable `account_type_id` foreign key, but the sign convention must degrade to debit-normal rather than fail, matching how `AccountBalanceService::signFor()` already behaves.
- **A hierarchy cycle introduced by a direct database write** — the roll-up must terminate rather than recurse forever, matching the `$visited` guard already used in `AccountBalanceService::rollUp()` and `ChartAccount::selfAndDescendantIds()`.
- **A soft-deleted account with posted history** — its lines still exist and still belong on the statements; history is never rewritten by a later deletion. The account appears, marked as deleted.
- **An inactive or non-postable account with posted history** — appears normally. `is_active` and `is_postable` govern *future* postings and have no bearing on what is reported.
- **Rounding** — no report may introduce a rounding difference. All aggregation is on integer minor units and converts to a decimal string only at the display boundary, so a total is never the sum of rounded parts.
- **An as-of date inside a closed fiscal period** — perfectly valid. Closing a period stops postings, not reads.
- **A range spanning a closed period, an open period, and dates in no period at all** — valid; reports scope by date, not by period membership, and an entry can only exist inside a period because posting resolved one.
- **Two accounts whose codes sort differently as text than as numbers** — ordering is stable and by account code, and must be specified so that a report's row order does not vary between runs or between database engines.
- **A very large ledger** — the reports must aggregate in a bounded number of queries rather than one query per account, matching the single-aggregate-plus-tree-walk approach `AccountBalanceService::balancesForAll()` already uses.

## Requirements

### Functional Requirements

**Access and permissions**

- **FR-001**: The system MUST add exactly one permission, `accounting.report.view`, to the existing `AccountingPermission` catalogue, which remains the single source of truth consumed by the permission seeder and the policy concern.
- **FR-002**: The system MUST NOT let any existing permission imply `accounting.report.view`. In particular `accounting.ledger.view`, which grants a single account's ledger, MUST NOT grant the aggregate statements.
- **FR-003**: The system MUST grant `accounting.report.view` to the Chief Accountant, Accountant, and Reviewer fixed dashboard roles, and MUST NOT grant it to any role outside the accounting catalogue.
- **FR-004**: The system MUST refuse access to every report surface, and omit its navigation link, for a user lacking `accounting.report.view`.
- **FR-005**: The system MUST gate every export on `accounting.report.view` — the same permission as the screen that produces it — enforced server-side on the export request itself rather than only on the control that triggers it.
- **FR-006**: The permission seeder MUST remain idempotent, so re-running it neither duplicates nor rewrites rows.

**Behaviour common to every report**

- **FR-007**: The system MUST derive every reported figure from posted journal entry lines only, excluding draft entries entirely.
- **FR-008**: The system MUST derive every reported figure from the lines themselves, never from a stored, cached, or denormalised balance column.
- **FR-009**: The system MUST scope every report by date — a start and end date for movement reports, an as-of date for position reports — with both bounds inclusive.
- **FR-010**: The system MUST reject a range whose end date precedes its start date with a validation error, rather than returning an empty result.
- **FR-011**: The system MUST offer fiscal-period selection as a convenience that resolves to that period's start and end dates, and MUST offer open and closed periods alike.
- **FR-012**: The system MUST compute all monetary aggregation on integer minor units and convert to a decimal string only at the presentation boundary, so no floating-point value participates in any total.
- **FR-013**: The system MUST sign every reported balance by its account type's normal balance, so an account holding its normal balance reads positive, reusing the existing `NormalBalance::sign()` rather than reimplementing the rule.
- **FR-014**: The system MUST include an account's descendants in its reported figure, and MUST NOT double-count a descendant within its ancestor's subtotal.
- **FR-015**: The system MUST terminate its hierarchy roll-up even when the stored hierarchy contains a cycle.
- **FR-016**: The system MUST order rows deterministically by account code, so the same scope produces the same row order on every run.
- **FR-017**: The system MUST render every report over an empty ledger as zero rows and zero totals, without error.
- **FR-018**: The system MUST include a soft-deleted account's posted lines, and MUST mark such an account as deleted where it appears.
- **FR-019**: The system MUST include an inactive or non-postable account's posted lines unchanged; those flags govern future postings only.
- **FR-020**: The system MUST aggregate in a number of queries that does not grow with the number of accounts or the number of posted lines.

**Trial Balance**

- **FR-021**: The system MUST present, for a date range, each account carrying movement in the range or a non-zero opening balance, with its code, name, account type, opening balance, range debit total, range credit total, and closing balance.
- **FR-022**: The system MUST omit an account with neither movement in the range nor a non-zero opening balance.
- **FR-023**: The system MUST display total range debits and total range credits, and MUST display their equality as an explicit footing proof.
- **FR-024**: The system MUST display a discrepancy prominently as an error when the two column totals differ, and MUST NOT round, suppress, adjust, or plug the difference.
- **FR-025**: The system MUST compute an account's opening balance as its net movement from ledger inception through the day before the range's start date.

**General Ledger**

- **FR-026**: The system MUST present, for a date range, posted lines with entry number, entry date, account code and name, entry description, debit amount, credit amount, and a running balance.
- **FR-027**: The system MUST allow filtering to a single account, and MUST include that account's descendants' lines when it has any, labelling each line with the account it was posted to.
- **FR-028**: The system MUST begin the running balance from the filtered account's opening balance for the range, so the final running-balance figure equals the trial balance's closing balance for the same account and range.
- **FR-029**: The system MUST order lines by entry date, then entry, then the line's stored sort order, matching the ordering `AccountBalanceService::ledgerFor()` already establishes.

**Profit and Loss**

- **FR-030**: The system MUST present, for a date range, income and expense accounts only, grouped and subtotalled by account type, with net profit or loss as income less expense.
- **FR-031**: The system MUST exclude asset, liability, and equity accounts from this statement regardless of their movement in the range.
- **FR-032**: The system MUST present a net loss unambiguously distinguishably from a net profit.

**Balance Sheet**

- **FR-033**: The system MUST present, as of a date, asset, liability, and equity accounts with their balances as of that date, each group subtotalled.
- **FR-034**: The system MUST present a single computed equity line holding the net of all income and expense movement from ledger inception through the as-of date, and MUST label it to state that it is computed rather than posted.
- **FR-035**: The system MUST NOT post anything to Retained Earnings or to any other account in order to produce this statement.
- **FR-036**: The system MUST display total assets, total liabilities, total posted equity, and the computed earnings line, and MUST display the accounting equation as an explicit proof.
- **FR-037**: The system MUST display a discrepancy prominently as an error when the equation does not hold, and MUST NOT adjust any figure to make it hold.
- **FR-038**: The system MUST exclude every entry dated after the as-of date.

**Posting Register**

- **FR-039**: The system MUST present, for a date range, posted entries in date and entry-number order with their entry number, date, description, lines, resolved fiscal period, and the user who posted them.
- **FR-040**: The system MUST exclude draft entries.
- **FR-041**: The system MUST render an entry's optional source generically: resolving the morph target where it resolves, identifying a reversal's original entry by entry number, and degrading to a readable type-and-identifier label for a type it does not specifically recognise.
- **FR-042**: The system MUST render a row whose source morph no longer resolves with the reference labelled as unresolved, without failing the report.
- **FR-043**: The system MUST render an entry with no source as an empty source rather than as an error or as a placeholder indistinguishable from data.

**Export**

- **FR-044**: The system MUST offer an export of each report whose rows and totals match the report on screen exactly, including its footing or equation proof.
- **FR-045**: The system MUST state the exported report's scope — its date range or as-of date — inside the exported file, so a detached file cannot be misread as covering a different period.
- **FR-046**: The system MUST produce an export for an empty report containing its headers and zero data rows.
- **FR-047**: The system MUST NOT record an export in any persistent log, matching every other module's behaviour; no module currently writes `export_logs` and this feature MUST NOT be the first.

**Navigation**

- **FR-048**: The system MUST register the Financial Reports surface exactly once, in the shared `reports` navigation group, and MUST remove the duplicate `accounting`-group registration (defect N-1, decision D3).
- **FR-049**: The system MUST leave the six remaining `accounting`-group items as placeholders rendering the shared placeholder page.
- **FR-050**: The system MUST enforce, by test, that no navigation label is registered in more than one group anywhere in the registry.
- **FR-051**: The system MUST provide English labels for the report surface, every report type, every column, and every proof line. Arabic keys fall back to English per the convention recorded at the top of `lang/ar/admin.php`.

**Read-only guarantees**

- **FR-052**: The system MUST NOT create, update, or delete any row in any table as a result of producing or exporting any report.
- **FR-053**: The system MUST NOT call the journal posting service, or any other write path, from any class in this feature.
- **FR-054**: The system MUST leave the existing Chart of Accounts balances and per-account ledger surface behaviour unchanged, so the all-time figures `018` already tests continue to hold.
- **FR-055**: The system MUST NOT cause any commercial document to write a journal entry, and the existing no-automatic-posting guarantee MUST continue to hold unchanged.

### Key Entities

This feature introduces no new persisted entity. It reads:

- **ChartAccount** — the account whose code, name, hierarchy position, and owning type structure every statement's rows.
- **AccountType** — supplies the normal balance that signs every figure and the element that groups accounts into the profit and loss versus the balance sheet.
- **FiscalPeriod** — offered as a date-range convenience, and reported per entry in the posting register.
- **JournalEntry** — the posted entry the register lists and every figure traces back to; its optional source morph is what makes the register forward-compatible with documents that do not yet exist.
- **JournalEntryLine** — the single source of every number in this feature.

It introduces one presentational concept that is computed and never stored:

- **Computed accumulated earnings** — the net of all income and expense movement through the as-of date, presented as an equity line so the balance sheet balances without a year-end close.

## Success Criteria

### Measurable Outcomes

- **SC-001**: A trial balance over any date range foots — total debits equal total credits — including over a set containing a posted entry and its reversal, and the report displays that proof.
- **SC-002**: A posted entry paired with its reversal contributes zero net movement to every one of the five reports.
- **SC-003**: No draft entry appears in, or affects any total on, any of the five reports.
- **SC-004**: A balance sheet as of any date satisfies assets equals liabilities plus posted equity plus computed accumulated earnings, and displays that proof.
- **SC-005**: A profit and loss for a range ending on a given date and a balance sheet at that date agree: over a ledger whose first entry falls inside the range, the profit-and-loss net figure equals the balance sheet's computed earnings line.
- **SC-006**: Every report is inclusive at both date bounds — an entry dated exactly on the start date and one dated exactly on the end date both appear; one dated the day before the start and one the day after the end both do not.
- **SC-007**: A general ledger's final running balance for an account and range equals that account's closing balance on the trial balance for the same range.
- **SC-008**: A user holding `accounting.report.view` can open every report and complete every export; a user without it can do neither, cannot reach an export by requesting it directly, and does not see the navigation link.
- **SC-009**: Every report renders over an empty ledger with zero rows and zero totals, no error, and a balance-sheet equation that still holds.
- **SC-010**: Producing and exporting every report writes no row to any table, proven by a test that counts rows across the accounting tables before and after.
- **SC-011**: The posting register renders an entry whose source morph points at a non-accounting model, and one whose source no longer resolves, without failing — proving that the documents `019-sales-lifecycle-payments-credits` posts will appear with no change to this feature.
- **SC-012**: Financial Reports appears exactly once in the sidebar, and a test proves no navigation label is registered in more than one group.
- **SC-013**: `composer test` passes with no new PHPStan baseline entries, and an architecture test proves no class in this feature calls the journal posting service.
- **SC-014**: The complete `018` test suite passes unchanged, including the posted-entry immutability and no-automatic-posting tests.

## Assumptions

- **A trial balance's most useful form includes opening and closing balances**, not movement alone. Nothing in the canonical documentation specifies the columns; the four-column form (opening, debits, credits, closing) is chosen because it is conventional and because it is what lets the trial balance reconcile against both the general ledger and the balance sheet. Movement-only would be cheaper and would break SC-007.
- **A single computed earnings line is the only implementable correct answer** for equity, per decision D4. Splitting it into prior-period retained earnings and current-year income is the more familiar presentation, but `fiscal_periods` is a flat list with no fiscal-year parent, so the split point cannot be derived from the schema. Inventing one would be a schema change, which is out of scope.
- **Single currency.** `Docs/PRD.md` §12 still lists "What currencies and tax rates should be seeded first?" as an open question, and `018` added no currency column. All amounts are in one implied currency and no report displays or converts a currency.
- **Reports are for the dashboard, not for an API.** No consumer outside the admin panel is assumed, so no serialisation contract, versioning, or pagination protocol is specified.
- **The seeded chart of accounts is a starting point, not a prescribed structure.** The statements group by account *type*, never by hard-coded account code, so a user who renames or restructures the seeded chart does not break any report. In particular the Retained Earnings account (`3200`) is never referenced by code.
- **CSV is the export format**, matching the streamed-download precedent already established in the purchasing reports surface. No spreadsheet-native or PDF format is assumed, and no export is persisted server-side.
- **The `source_type`/`source_id` morph will carry commercial documents later.** Today only reversals populate it. The register's generic rendering is built now specifically so that `019-sales-lifecycle-payments-credits` needs to change nothing here — this is the whole of this feature's cross-module integration, and it is a forward-compatibility guarantee rather than a present-day capability.

## Dependencies and Integration Points

**Depends on (built).** `018-chart-of-accounts-journals` for the five accounting tables, the `AccountingPermission` catalogue, the `ChiefAccountant`/`Accountant`/`Reviewer` dashboard roles, the `AccountElement` and `NormalBalance` enums and their sign convention, and the existing balance and ledger read services. `003-auth-users-spatie-access` for `User` and `spatie/laravel-permission`. The `AdminModuleRegistry` and admin panel navigation contract. The report-resource conventions established by the inventory, employee, support, and purchasing report surfaces.

**Independent of (unbuilt, and deliberately so).** `019-sales-lifecycle-payments-credits` delivers invoices, payments, credit notes, and the first three posting callers. This feature neither needs it nor is needed by it: because these reports read the *ledger* rather than the documents, every entry `019` posts appears here automatically with no change to either specification. The two may land in either order. Nothing in this feature may reference an invoice, payment, or credit note by type.

**Sequencing note against `019` FR-003.** Spec `019` FR-003 requires `Financial Reports` to remain a placeholder, because that feature does not build it. This feature is what fills it. If `019` lands first there is no conflict — this feature simply supersedes that one line. If this feature lands first, `019` FR-003 must be amended to drop `Financial Reports` from its placeholder list before `019` is implemented, or its navigation test will fail against a slot that is legitimately no longer a placeholder. Whichever order is chosen, the amendment is one list entry.

**Explicitly not integrated.** Inventory, Purchasing, CRM, Employees, Support, and Orders. This feature reads only the five accounting tables; no query in it may join to any other module's table, and it adds no observer, listener, event, or service call touching another module. Its only contact with another module's surface is that it adds one sibling to the shared `reports` navigation group — and it adds a sibling rather than modifying any of the entries already there.

**The accounting navigation slots this feature does not fill.** Six remain placeholders after it ships. None is unscheduled — each now has a named owning specification, so the accounting group has no undrafted slot left:

| Slot | Owning specification | Hard prerequisite |
|---|---|---|
| `accounts_receivable` | `021-accounting-receivables-tax-refunds` | `019` — invoices, payments, allocations |
| `taxes` | `021-accounting-receivables-tax-refunds` | `019` — tax recognition entries |
| `refunds` | `021-accounting-receivables-tax-refunds` | `019` — payments and credit notes |
| `expenses` | `022-accounting-payables-expenses-bills` | `018` only — buildable alongside this feature |
| `bills` | `022-accounting-payables-expenses-bills` | `017` suppliers and purchase orders, `018` ledger |
| `accounts_payable` | `022-accounting-payables-expenses-bills` | `022`'s own bills; also an **ADR 0006 amendment**, since constitution §Specification Governance forbids accounts-payable behaviour reaching the Purchasing module without one |

This table is recorded here so the accounting module's remaining surface can be read off one page, and so the next specification does not have to rediscover why these six could not simply have been built alongside the reports.

This table is recorded here so the next specification does not have to rediscover why these six could not simply have been built alongside the reports.

**New dependencies.** None. No Composer or npm package is added.
