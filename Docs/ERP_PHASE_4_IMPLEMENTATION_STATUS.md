# ERP Phase 4 Implementation Status

Date: 2026-09-12
Branch: `feat/cross-module-remediation`

Companion: `PHASE_4_PLAN.md` (package design), `ERP_REMEDIATION_PLAN.md` §Phase 4 (per-package trigger,
scope sketch, tests), `Docs/adr/0012`–`0015` (the four Phase 4B activation decisions this phase closes out).

## Package status

| Package | Implementation | Test coverage this pass added/fixed | Notes |
|---|---|---|---|
| WP-4.1 — Report/reconciliation performance | Complete | Added `CustomerTimelineService` budget; fixed a real N+1 in `StockAvailabilityQueryCountTest` | `PerformanceBenchmarkSeeder` + `ReportBudgetTest.php` cover 12 report/reconciliation surfaces |
| WP-4.2 — Schema deprecation cleanup | Complete | — | Verified, not re-done: `invoices.inventory_operation_id`, `ModulePlaceholder`, and `AdjustmentStatus` dead paths are all already gone with no readers left |
| WP-4.3 — Async exports with retained parameters | Complete | Rewrote 2 stale tests to match the shipped architecture | `DocumentExport`/`GenerateDocumentExport` now serve inventory, sales, and employees uniformly |
| WP-4.4 — Notification volume management | Complete | Added the missing UI surface + its tests | Digest/rate-limit/quiet-hours/`notifications:digest` command pre-dated this pass; the delivery-volume report existed as an uncalled service until this pass wired it into the admin UI |
| WP-4.5 — API and channel foundation | Complete, with a known test-coverage gap | — | See ADR 0012. No `ChannelParityTest.php`; only 4 tests cover ~21 endpoints, not a contract test per endpoint |
| WP-4.6 — Inventory valuation and cost of sales | Complete | Added the first direct test suite (11 tests); fixed a launch-blocking regression | See ADR 0013. Had zero direct test coverage before this pass |
| WP-4.7 — Ticket revenue through the ledger | Complete | Rewrote 2 tests to assert the new (correct) behavior; fixed a fixture cascade | See ADR 0014 |
| WP-4.8 — Supplier debit notes | Complete | Added the first direct test suite (6 tests) | See ADR 0015. Had zero direct test coverage before this pass |
| CC-04 — Shared Pest test helpers | Complete, scoped down from the plan's literal wording | 3 commits, ~33 files migrated | See "CC-04 scope decision" below |

All eight Phase 4 work packages and the one outstanding cross-cutting item this phase carried (CC-04) are
now complete. Four of the eight (WP-4.5, 4.6, 4.7, 4.8) were Phase 4B items the governing ADRs (0003, 0006,
0007, 0008) had deferred pending an owner decision; that decision was made in favor of building all four,
and ADRs 0012–0015 record it.

---

## WP-4.1 — Performance of the report and reconciliation surfaces

**Implementation status:** Complete
**Test status:** `tests/Feature/Performance/ReportBudgetTest.php` — 15 wall-clock/query-count budget tests,
skipped unless `PerformanceBenchmarkSeeder` (50k invoices, 500k movements, 5k customers) has been seeded

`PerformanceBenchmarkSeeder` already existed and its own doc comment named the eight report/reconciliation
services it deliberately covers and the five it deliberately does not (`PurchasingReportService`,
`CrmFunnelReportService`, `EmployeeReportService`, `SupportReportService` — each needs its own unrelated FK
tree and was scoped as a documented follow-up, not an oversight). `ReportBudgetTest.php` covered eleven of
those services but was missing `CustomerTimelineService` — the "paginated timeline union" the plan calls out
by name — despite the seeder's own doc comment promising it. This pass added that budget test.

Separately, `tests/Feature/Inventory/StockAvailabilityQueryCountTest.php` was failing: 12 queries against a
documented budget of ≤10. Root cause was a real inefficiency, not a stale budget —
`InventoryStock::conditionBalance()` re-ran an identical query every time it was called, and
`StockAvailabilityExplainer::explain()` called it three times for the same (Saleable) condition in one
pass. Fixed by memoizing the lookup per condition on the model instance
(`app/Models/InventoryStock.php`) — the same fix also removes redundant queries from the stock-level table
and infolist, which called the same accessor 3–4 times per rendered row.

Index review against actual query plans (the plan's third bullet) was not performed as a separate audit
pass in this session; the query-count budgets above are the enforceable proxy for it that already exists.

---

## WP-4.2 — Schema deprecation cleanup

**Implementation status:** Complete (verified, not newly done in this pass)

All three named removals were already complete by the time this session audited the branch:

- `invoices.inventory_operation_id` — removed from its origin migration
  (`2026_08_24_143226_add_sales_lifecycle_columns_to_invoices_table.php`); no model, service, or test
  references it; `Schema::hasColumn('invoices', 'inventory_operation_id')` is `false` against the current
  schema. Superseded by `InvoiceDeliveryLink` (WP-2.13's join table).
- `ModulePlaceholder` — the file and every reference to it in `AdminModuleRegistry` are gone.
- `AdjustmentStatus`-adjacent dead code — the enum has exactly two cases (`Draft`, `Confirmed`), its own
  doc comment already records the earlier removal of a `pending` case, and every one of its six call sites
  (service, model, two Filament table/infolist files, and `InventoryCountService`, which produces a draft
  adjustment as its output) is live and current. No dead branch was found.

---

## WP-4.3 — Asynchronous exports with retained parameters

**Implementation status:** Complete (verified; two stale tests fixed in this pass)

`DocumentExport` + `GenerateDocumentExport` + `DocumentExportService` now serve inventory, sales, and
employee exports uniformly, superseding the two unmerged patterns (`InventoryExport`'s queued-but-no-expiry
design and the sales module's synchronous CSV stream) the plan described as needing unification.

Two tests still described the superseded architecture and were rewritten in this pass:

- `tests/Feature/Filament/SalesDocumentExportTest.php` — fully rewritten from reflection calls into a
  removed synchronous method to `Livewire::test()->callAction()` against the current async action.
- `tests/Feature/Employees/EmployeeReportResourceTest.php` — asserted dispatch of
  `App\Jobs\GenerateEmployeeReportExport`, a class that no longer exists (the string still resolved via
  `::class` without a fatal error, silently making the assertion always fail); fixed to assert
  `GenerateDocumentExport` dispatch against the created `DocumentExport` row.

A related message-drift issue in two more tests is recorded under "Incidental fixes" below.

---

## WP-4.4 — Notification volume management

**Implementation status:** Complete
**Test status:** Added `tests/Feature/Notifications/NotificationVolumeManagementTest.php` coverage for the
report service; added a widget-rendering test to `tests/Feature/Filament/NotificationResourceTest.php`

Per-user digest preferences (immediate/daily/weekly), per-template rate limits, a quiet-hours window, and
the scheduled `notifications:digest` command all pre-dated this session and needed no further work.
`NotificationDeliveryVolumeReportService` also pre-dated this session but was never called from anywhere —
the "delivery-volume report so the noise is measurable" deliverable existed as a class with no consumer and
no test. This pass added `app/Filament/Widgets/NotificationVolumeReport.php`, a stats widget on the
notification-deliveries list page breaking the last 7 days down by outcome, plus tests for both the service
and the widget.

---

## WP-4.5 — API and channel foundation

**Implementation status:** Complete, with a known test-coverage gap — see ADR 0012

`routes/api.php`, Sanctum token auth, `CustomerChannelController`/`CustomerChannelService`,
`EmployeeChannelController`/`EmployeeChannelService`, and `ChannelActorResolver` were all built before this
session (this session added no new production code for WP-4.5, only the ADR). The plan's single most
important constraint — no API controller calls a model directly — is enforced by
`tests/Unit/ApiArchitectureTest.php`, which fails the build on any violation.

The plan's own "important one" test, `tests/Feature/Api/ChannelParityTest.php` (asserting the API and
Filament paths produce byte-identical domain records for the same action), does not exist.
`tests/Feature/Api/ChannelApiTest.php` (4 tests) and `ApiArchitectureTest.php` (1 test) are what exists
today, covering token issuance/revocation and the controller-purity rule, not a contract test per endpoint
across the ~21 routes. This is recorded as open scope in ADR 0012 and the closeout report, not silently
carried forward as if it were done.

This session did fix one test-only defect surfaced while auditing this area:
`tests/Feature/Api/ChannelApiTest.php` asserted a revoked Sanctum token still failed with 401 on a
subsequent call within the same test method; this was a token-guard caching artifact specific to multiple
sequential HTTP calls inside one Pest test (proven via isolated probe tests, not a real vulnerability), fixed
with `app('auth')->forgetGuards()` between calls in the test itself.

---

## WP-4.6 — Inventory valuation and cost of sales

**Implementation status:** Complete — see ADR 0013
**Test status:** `tests/Feature/Inventory/InventoryValuationServiceTest.php` — 11 tests, added in this pass
(zero direct tests existed before it)

`InventoryValuationService` (weighted-average costing; posts inventory/GRNI on receipt, COGS on delivery,
shrinkage on damage/disposal/adjustment; reconciles to the inventory asset control account) was built before
this session. This session found and fixed a launch-blocking regression: `processDelivery()` and
`processTransfer()` unconditionally relieved the valuation balance for every Sale/Transfer movement,
throwing `"Inventory valuation cannot become negative"` the instant a delivery or transfer touched stock
that predates valuation tracking (opening balances, demo data, most of the existing test suite's fixtures)
— this broke roughly 25 core inventory tests outright before the fix. The fix (a no-op guard, consistent
with the "no invented cost" principle already documented for `processStandaloneMovement`) is covered by
regression tests in the new suite. A related fix made `PeriodCloseChecklistService::checkInventoryValuation()`
pass rather than block a close when no inventory asset account is configured at all.

`tests/Feature/Accounting/NoAutomaticPostingTest.php` was rewritten in an earlier part of this pass to name
`InventoryValuationService` (plus `GrniClearingService` and `SupplierDebitNoteService`) among its twelve
permitted `JournalPostingService` callers — up from nine — with an explanatory comment recording this as the
deliberate WP-4.6/4.7/4.8 scope expansion the test itself exists to gate.

---

## WP-4.7 — Ticket revenue through the ledger

**Implementation status:** Complete — see ADR 0014

`TicketPaymentService::settle()` (creates and issues a standard invoice, then creates and posts a standard
payment allocated to it) was built before this session. This session repaired a multi-file fixture cascade
that had been left broken by an incomplete seeder reorder: `DatabaseSeeder` ran `SupportDemoSeeder` before
`AccountingDemoSeeder`, so ticket-settlement tests and demo data lacked the payment method, chart of
accounts, sales-setting account configuration, and fiscal period the settlement path now requires. Fixed by
reordering `DatabaseSeeder` and adding the missing fixtures to
`tests/Feature/Support/{TicketPaymentTest,TicketLifecycleTest,SlaTest,SupportReportTest}.php`'s shared
`beforeEach` blocks (later consolidated into the `seedPostingAccounts()` CC-04 helper — see below).
`TicketPaymentTest.php`'s central test was rewritten from asserting settlement produces **zero** rows in any
accounting-adjacent table to asserting it posts through the standard invoice/payment path and the ledger is
populated — the inverse assertion, matching the architecture decision this ADR records.

---

## WP-4.8 — Supplier debit notes

**Implementation status:** Complete — see ADR 0015
**Test status:** `tests/Feature/Purchasing/SupplierDebitNoteServiceTest.php` — 6 tests, added in this pass
(zero direct tests existed before it)

`SupplierDebitNoteService` (derives a debit note line strictly from the return-line → receipt-line →
PO-line → bill-line chain; posts payable/GRNI/input-tax reversal on confirm; reverses cleanly) was built
before this session, referenced only by architecture tests. This pass added direct coverage: derivation from
the full provenance chain, the no-expected-outcome and over-credit refusals, confirm posting, reversal, and
the awaiting-supplier-credit exception query.

---

## CC-04 — Shared Pest test helpers

**Implementation status:** Complete, deliberately scoped down from the plan's literal wording

The plan named three helpers — `actingAsInventoryManager()`, `actingAsAccountant()`,
`seedPostingAccounts()` — to de-duplicate a `beforeEach` block repeated across roughly 40 test files. A
research pass across the actual call sites found the three patterns were not equally safe to collapse:

- **Accountant pattern** (33 files, 21 with the full seeder+factory+`assignRole` shape): genuinely uniform.
  Extracted as `actingAsAccountant()`/`actingAsChiefAccountant()`; 19 files migrated (two files that
  parameterize role assignment across Reviewer/SystemAdmin/Accountant/ChiefAccountant as the actual behavior
  under test were deliberately left untouched, since folding them into the helper would have changed what
  they test).
- **`seedPostingAccounts` pattern** (14 files, 15 occurrences): same shape, but three different field-count
  variants (4/5/6 posting accounts). Standardized on the six-account superset — setting an account a given
  test doesn't exercise is harmless — and migrated all 14 files.
- **Inventory-manager pattern** (52 files calling `InventoryPermissionSeeder`): genuinely **not** uniform —
  at least 8 distinctly-named local roles and 26 files granting bespoke permission subsets directly, each
  proving a different, deliberately narrow authorization boundary. Forcing a fixed-permission
  `actingAsInventoryManager()` onto these files would have silently broadened or narrowed what each test
  actually authorizes — a correctness risk, not a safe refactor. Only a one-line `seedInventoryPermissions()`
  convenience helper was added; the 52 call sites were deliberately left unmigrated.

Three independently-revertable commits: helpers added to `tests/Pest.php`; the accountant-pattern migration;
the `seedPostingAccounts` migration. Full suite re-run after each: 2562 passed, 25 skipped, 1 failed (the
pre-existing, unrelated `InventoryMonetaryBoundaryTest` — see the closeout report).

---

## Incidental fixes surfaced while closing out this phase

Not scoped to any single WP above, found while auditing and fixing the phase's actual test failures:

- **`InventoryAlertService::syncStock()`** called `syncLowStock()` instead of `resolve(LowStock)` in its
  out-of-stock branch, breaking the documented mutual exclusivity between `OutOfStock` and `LowStock` alerts
  — found via a test fixture change, fixed in application code.
- **Two wrong-namespace imports** (`CatalogImportCatalogService.php`, `InventoryOperationService.php`) —
  bare `ProductPricingService`/`PricingTierService` references resolved against the wrong namespace after an
  earlier phase moved pricing ownership from Inventory to Sales; ~20 tests failed with "class does not
  exist" until the imports were corrected.
- **Export failure-message drift**: `EmployeeReportExportTest.php` and `InventoryExportServiceTest.php` both
  asserted a specific internal exception message (`"Unable to create the private export directory."`) that
  `DocumentExportService::generate()` no longer lets through — it now wraps every generation failure into
  one generic `"Document export generation failed."` message rather than leaking internals. Both tests
  updated to match.
- **`NotificationDigestService`** called the `Mail`/`Notification` facades directly to send a combined
  digest, violating the architecture rule (enforced by `tests/Unit/Notifications/NotificationArchitectureTest.php`)
  that only `NotificationDispatcher` may touch those facades. Fixed by adding a `deliverPrepared()` entry
  point to the dispatcher that reuses its existing send/fail bookkeeping for an already-assembled subject and
  body.
- **`ArchTest.php`** needed new exemption entries for the WP-4.5/4.6/4.8 models (`DocumentExport`,
  `FiscalPeriodCloseCheck`, `InventoryValuationBalance`, `InventoryValuationEntry`, `SupplierDebitNote(Line)`,
  `WarehouseReplenishmentPolicy`, each overriding a protected `casts()`/`booted()`) and controllers
  (`CustomerChannelController`, `EmployeeChannelController`, channel gateways rather than REST resources).
- A circular dependency (`InventoryValuationService → JournalPostingService → FiscalPeriodService →
  PeriodCloseChecklistService → InventoryValuationService`) was exhausting memory on boot; broken by
  resolving `InventoryValuationService` lazily inside `PeriodCloseChecklistService` instead of injecting it.
- Three migration defects that only surface against real MySQL (not the SQLite test database): a dangling
  `reorder_level` reference, a dangling `invoices.inventory_operation_id` reference in a later migration, and
  two generated FK/index names exceeding MySQL's 64-character identifier limit.

## Known open gaps carried forward (not addressed in this pass)

- **WP-4.5's test coverage** is thinner than the plan specified — no contract test per endpoint, no
  `ChannelParityTest.php`. See ADR 0012.
- **The project-wide PHPStan backlog** (536 errors at `level: max`, unbaselined, as of this pass) predates
  this phase and was not remediated here — CLAUDE.md forbids growing the baseline, but a full-codebase
  type-safety pass is out of scope for a Phase 4 closeout. See the cross-module remediation closeout report.
- **`InventoryMonetaryBoundaryTest`** — an architecture-tension test asserting a fixed count of files in
  `app/Services/Inventory` (32) that has since grown to 37 as Inventory services legitimately gained
  dependencies on Sales pricing. This is a genuine, unresolved design question (should those dependencies be
  inverted, or is the boundary test's premise stale?) requiring an owner decision, not a unilateral code
  change — left failing and documented rather than silently adjusted.
