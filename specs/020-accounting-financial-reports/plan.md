# Implementation Plan: Accounting Financial Reports

**Branch**: `020-accounting-financial-reports` | **Date**: 2026-08-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/020-accounting-financial-reports/spec.md`

## Summary

Deliver five read-only statements over the posted general ledger — Trial Balance, General Ledger, Profit and Loss, Balance Sheet, and Posting Register — as one Filament page in the shared `reports` navigation group, plus a streamed CSV export per report.

The technical approach has three load-bearing parts. First, a new `App\Services\Accounting\FinancialReportService` owns **date-bounded** aggregation, which the existing `AccountBalanceService` cannot express: it computes all-time figures only. Second, every statement is assembled from one aggregation primitive — net debit and credit per account, filtered by posted status and an entry-date bound — called at most twice per report, so query count is independent of account count (FR-020). Third, the Balance Sheet's computed accumulated-earnings line is derived from income and expense movement, which is what makes the accounting equation hold with no year-end close (D4, FR-034); [research.md](./research.md) §R3 carries the algebraic proof, because SC-004 and SC-005 depend on it being right rather than plausible.

No table, column, index, or migration is added. Nothing in this feature writes.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, `spatie/laravel-permission`. No new Composer or npm package (FR — §Scope). `barryvdh/laravel-dompdf` is **not** installed here.

**Storage**: MySQL. Reads five existing tables — `account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, `journal_entry_lines`. **Zero schema change.**

**Testing**: Pest 4 (`composer test`), PHPStan level per `phpstan.neon` with a baseline that may only shrink, Pint. Conventions follow the 13 existing files in `tests/Feature/Accounting/`.

**Target Platform**: Laravel Herd / Laragon, `/admin` Filament panel, single deployment.

**Project Type**: Modular monolith, existing structure. No new base folder.

**Performance Goals**: Query count per report independent of account count and line count (FR-020). Two aggregate queries plus one account fetch is the ceiling for any statement. No latency target is promised and no caching is permitted (§Scope), so a large ledger is a known, documented future cost.

**Constraints**: Read-only end to end (FR-052, FR-053). Integer minor units for all arithmetic; no float ever participates (FR-012). Sign convention reused from `NormalBalance::sign()`, never reimplemented (FR-013). Deterministic row order (FR-016). Every report must render over an empty ledger without error (FR-017).

**Scale/Scope**: One Filament resource, one page, one Blade view, one service, one enum, one permission, one registry edit. Five report types. 55 functional requirements, 14 success criteria.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design — result at the end of this section.*

| Principle | Applies? | Assessment |
|---|---|---|
| **I. Specification-First** | Yes | Spec approved and validated; all 55 FRs traced. Database design is finalised trivially — there is none. **PASS** |
| **II. Domain-Driven Modular Monolith** | Yes | Aggregation lives in `App\Services\Accounting\FinancialReportService`; the Filament page holds no business rule. No API Resource or Form Request applies — no API surface, and the only input is a date range validated in the page schema. "Unrelated refactors prohibited" is the binding clause here and it decided research §R6 against touching `AccountBalanceService`. **PASS** |
| **III. Financial & Inventory Integrity (NON-NEGOTIABLE)** | Yes | The feature writes nothing, so no stock movement, no tax timing, no document lifecycle, and no transaction requirement is engaged. It *strengthens* this principle: the trial balance is the first surface able to demonstrate debits equal credits across the whole book rather than per entry. The one live risk is the opposite of the usual one — a report that silently corrects what it reports. FR-024, FR-037, and the §Scope emphasis forbid rounding, suppressing, adjusting, or plugging a failed proof. **PASS** |
| **IV. Unified Access, Media & Payment Standards** | Yes | Authorization via `spatie/laravel-permission` through the existing `AccountingPermission` enum and `ChecksAccountingPermissions` concern; no custom authorization. No media, no payment. Queues: CSV exports stream synchronously rather than queueing, following the built `ListPurchasingReports` precedent — see §Complexity Tracking. **PASS with one justified deviation** |
| **V. AI Isolation & Human Oversight (NON-NEGOTIABLE)** | No | No AI in this feature. **N/A** |
| **VI. Engineering Discipline** | Yes | Business rules in a service; transactions not required (no writes); tests for every rule; audit logging not required — reading a report is not a sensitive *action* and ADR 0005's log covers state changes, of which this feature has none; no unrelated refactors (§R6). **PASS** |
| **Product Scope & Boundaries** | Yes | **PASS as of 2026-08-23.** Financial reports of every kind — trial balance, profit and loss, and balance sheet named individually — were in ADR 0007's out-of-scope list, in constitution §Product Scope & Boundaries, and in `Docs/PRD.md` §11. All three now record the ADR 0009 exception. See §Governance Gate below. |
| **Specification Governance** | Yes | This work has **no** entry in the documented extraction order (`001`–`014`); it is an owner-prioritised addition, as `017` was. It skips no prerequisite: its only hard dependency, `006-chart-of-accounts-and-journals`, is built as `018`. **PASS** |

### Governance Gate — CLEARED 2026-08-23

All three conditions hold. Implementation is unblocked.

1. ✅ **`Docs/adr/0009-accounting-financial-reports.md` is Accepted.**
2. ✅ **Constitution amended to 1.9.0** — eighth narrow Filament dashboard exception recorded in §Product Scope & Boundaries, with a §Specification Governance entry marking this an owner-prioritised addition with no extraction-order entry.
3. ✅ **`Docs/PRD.md` §11 qualified** to record the exception.

**What the approval did not grant, and what `tasks.md` must therefore not contain.** The amendment restates both prohibitions explicitly, and they are the two ways this feature could still breach governance after being authorised:

- **No posting caller.** ADR 0008's three commercial-document events and the accounting foundation's manual path remain the complete list. A reporting surface is the most natural place for a posting path to be added quietly — a "post the year-end close from the Balance Sheet" convenience is one line of plausible code and would be a governance breach. FR-053 and SC-010 are the enforcement.
- **ADR 0006's Purchasing prohibition survives.** Reporting on the ledger grants Purchasing nothing.

The gate no longer blocks T001, but T001 should still *verify* the three conditions rather than assume them, since a task list outlives the session that wrote it.

### Post-Phase-1 re-check

Re-evaluated after the design artifacts below were produced. No new violation. The design added no dependency, no table, no write path, and no queue. The one deviation (§Complexity Tracking) was identified pre-design and is unchanged. **PASS, still blocked only on the governance gate.**

## Project Structure

### Documentation (this feature)

```text
specs/020-accounting-financial-reports/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output — read model, no schema
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── financial-report-service.md
│   ├── permissions.md
│   └── report-columns.md
├── checklists/
│   └── requirements.md  # Spec quality checklist (already complete)
└── tasks.md             # Phase 2 — NOT created by /speckit-plan
```

### Source Code (repository root)

Existing structure; no new base folder. Files this feature adds or edits:

```text
app/
├── Enums/
│   ├── AccountingPermission.php              # EDIT — add ReportView case
│   └── FinancialReportType.php               # NEW — 5 cases + label()
├── Filament/
│   ├── AdminModuleRegistry.php               # EDIT — remove duplicate line 137 (N-1)
│   └── Resources/FinancialReports/
│       ├── FinancialReportResource.php       # NEW
│       └── Pages/
│           └── ViewFinancialReports.php      # NEW — page, filters, 5 export actions
├── Policies/Concerns/
│   └── ChecksAccountingPermissions.php       # EDIT only if a map entry is needed
└── Services/Accounting/
    ├── FinancialReportService.php            # NEW — date-bounded aggregation
    └── Support/
        ├── AccountTree.php                   # NEW — children map, cycle-guarded roll-up, display order
        └── LedgerAggregate.php               # NEW — per-account debit/credit minor-unit totals

database/seeders/
└── AccountingPermissionSeeder.php            # EDIT — grant ReportView to 3 roles

lang/en/admin.php                             # EDIT — report labels, columns, proof lines

resources/views/filament/financial-reports/
└── view-financial-reports.blade.php          # NEW — renders the selected statement

tests/
├── Feature/Accounting/
│   ├── FinancialReportServiceTest.php        # NEW — aggregation maths, date bounds, proofs
│   ├── FinancialReportResourceTest.php       # NEW — page render + permission gating
│   ├── FinancialReportExportTest.php         # NEW — CSV rows, scope line, permission gate
│   ├── FinancialReportReadOnlyTest.php       # NEW — row counts unchanged (SC-010)
│   └── AccountingEnglishLabelsTest.php       # EDIT — extend to new keys
└── Unit/
    └── AdminModuleRegistryTest.php           # EDIT — no label in two groups (FR-050)
```

**Structure Decision**: The built layout is kept unchanged. The one new directory, `app/Services/Accounting/Support/`, holds two internal collaborators of `FinancialReportService`; it sits inside the existing `Services/Accounting` domain folder required by Principle II rather than introducing a parallel namespace. `app/Filament/Resources/FinancialReports/` follows the sibling report resources exactly (`InventoryReports`, `PurchasingReports`, `EmployeeReports`, `SupportReports`).

The page is a plain custom `Page` with a Blade view, following `PurchasingReports` and `SupportReports` — **not** the `ManageRecords`-with-tabs shape of `InventoryReports`. Research §R2 records why: three of the five reports are grouped statements with subtotals and a footing proof, which a Filament table cannot express without fighting it.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Principle IV: exports stream synchronously rather than through a queued job | Principle IV requires queues for "long-running operations (… exports)". These exports are bounded by the same date range as the on-screen report the user is already looking at, so the work is the work that just rendered. The built precedent is `ListPurchasingReports`, which streams. | Queueing would require a job, a storage location, a notification, and a download surface — none of which any module has — and would turn a synchronous read the user is waiting on into an asynchronous one for no correctness gain. If a range large enough to time out becomes real, the fix is a queued job then, and the streaming contract in `contracts/report-columns.md` is what makes that swap local. |
| Deliberate duplication of the cycle-guarded hierarchy roll-up between `AccountBalanceService` (private) and the new `AccountTree` | `AccountTree` needs the same parent→children walk plus new depth-first display ordering. | Extracting the walk *out of* `AccountBalanceService` would be the DRY answer and is rejected by Principle II ("unrelated refactors are prohibited when delivering a feature") and Principle VI.7, and it would put a tested, baselined class at risk for a requirement this feature does not have. Recorded in research §R6 as a candidate follow-up to be done as its own change with its own review — not smuggled in here. |

## Phase 0 — Research

Complete. See [research.md](./research.md). Eight questions resolved, zero `NEEDS CLARIFICATION` remaining:

| # | Question | Resolution |
|---|---|---|
| R1 | Query shape for date-bounded aggregates | One primitive, called at most twice per report; closing = opening + period, never a third query |
| R2 | Filament page shape | Plain `Page` + Blade, not `ManageRecords` + tabs; three of five reports are grouped statements |
| R3 | Balance-sheet accumulated earnings | Algebraic proof that `assets = liabilities + equity + (income − expense)` holds identically |
| R4 | Sign convention per column | Balances signed by normal balance; **trial-balance debit/credit movement columns raw and unsigned** — signing them would break the footing proof |
| R5 | Row ordering | Depth-first tree order (parent above its children), computed in memory; not a SQL `ORDER BY code` |
| R6 | Sharing the roll-up with `AccountBalanceService` | Do not refactor it; accept documented duplication per Principle II/VI.7 |
| R7 | Per-report permission granularity | One permission for all five (FR-001); no `sourcePermission()` analogue needed |
| R8 | General Ledger and Posting Register volume | Paginate inside the Blade view via `LengthAwarePaginator` from the service; statements are not paginated |

## Phase 1 — Design & Contracts

Complete. Artifacts:

- **[data-model.md](./data-model.md)** — the read model. No entity is created; five are read. Documents the two internal value shapes (`LedgerAggregate`, `AccountTree`), the five report row shapes, and the derived `accumulated earnings` concept that exists only in presentation.
- **[contracts/financial-report-service.md](./contracts/financial-report-service.md)** — the public method contract of `FinancialReportService`: signatures, return shapes, date-bound semantics, proof-failure behaviour, and the invariants each method guarantees.
- **[contracts/permissions.md](./contracts/permissions.md)** — the one new permission, its non-implication rules, the role matrix delta, and the export-gating rule.
- **[contracts/report-columns.md](./contracts/report-columns.md)** — per report, the on-screen columns, the CSV header row, the scope line, and which columns are signed versus raw. This is the contract the export test asserts against.
- **[quickstart.md](./quickstart.md)** — runnable validation: seed, post a known entry set, open each report, verify each proof, exercise the permission gate, and confirm zero rows were written.

### Next step

`/speckit-tasks` to generate `tasks.md`. T001 must be the governance gate from §Constitution Check.
