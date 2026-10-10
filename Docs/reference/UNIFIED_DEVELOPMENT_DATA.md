# IERP Unified Development Dataset

## One environment
Root: C:\laragon\www\ierp-new. Branch: dev. Local MySQL: ierp on 127.0.0.1. Only one Git worktree remains; unpublished changes from old worktrees were preserved in Git stashes. No other persistent development environment was created.
Pre-seed backup: storage/app/private/backups/ierp-pre-unified-20261010.sql.

## Standard local setup

    php artisan migrate:fresh --seed

In **APP_ENV=local**, this standard Laravel command migrates and seeds the complete unified demo business through DatabaseSeeder. No custom demo seeding command is needed. It creates the canonical month, bilingual catalog, customers, purchasing/sales history, fulfilled orders, invoices, accounting, employees and support data.

**Warning:** migrate:fresh irreversibly deletes existing tables and data in the selected database. The IERP destructive-operation guard only permits MySQL schemas explicitly listed in IERP_DISPOSABLE_DATABASES (for example, IERP_DISPOSABLE_DATABASES=ierp in your local .env). Verify DB_DATABASE first; never list a production schema. The demo seed stage additionally refuses non-local MySQL hosts. On production and automated testing, DatabaseSeeder adds reference/permission data only, never demo business records.

Local-only demonstration login: demo.admin@ierp.test / password. Synthetic data and credentials are not suitable for production.

## Unified seeder stages
1. DemoMonthSeeder: curated complete Sep/Oct 2026 workflows for purchasing, receiving, inventory, sales, accounting, employees, CRM, support, warranty, installation and calibration.
2. UnifiedMasterDataSeeder: 14 bilingual categories, 112 synthetic product families, 336 variants, 124 new UAE clinic/lab customers, 18 suppliers, 4 warehouses.
3. UnifiedPurchasingHistorySeeder: 156 supplier commitments over twelve calendar months, domain-driven approval/PO inbound allocation and real inventory receipts.
4. UnifiedSalesHistorySeeder: 140 CRM leads with interactions plus 330 quotation states and history.
5. UnifiedSalesFulfillmentSeeder: 230 stock-backed order -> reservation/shipment -> delivery -> PDF invoice -> payment/AR split; respects mail suppressions.
6. UnifiedEmployeeHistorySeeder: 72 employee monthly plans, 504 tasks and 360 GPS-proximate visits with service-driven status transitions.
7. UnifiedSupportHistorySeeder: 120 support cases, triage, assignments and service states.
8. UnifiedMaintenanceHistorySeeder: 64 maintenance, calibration and installation REQUESTS across the year (not fabricated completion certificates).

## Critical data integrity
Product history is immutable: collisions with existing non-demo products use isolated demo identities instead of overwriting tracking behavior.
Stock is posted only via Inventory services with legitimate PO/inbound reference or order/shipment source. No invented quantities are injected.
Invoice PDFs are generated via GenerateInvoiceDocument; external delivery is safe fake local mail. Customers' communication suppression is respected. Domain services post journals and payments, never raw state assignments.
Demo full re-run should be idempotent by natural keys. Anomalies and missing volume must yield failing checks from demo:seed --verify-only.

## Counts before direct-sales extension (2026-10-10)
| Products | Variants | Customers | Suppliers | Warehouses |
| ---: | ---: | ---: | ---: | ---: |
| 141 | 395 | 149 | 30 | 10 |

| Leads | Quotes | Purchase orders | Inventory movements |
| ---: | ---: | ---: | ---: |
| 155 | 366 | 167 | 359 |

| Orders | Invoices | Payments | Bills | Journal entries | Expenses |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 31 | 23 | 26 | 136 | 111 | 12 |

| Employee tasks | Visits | Tickets | Maintenance records |
| ---: | ---: | ---: | ---: |
| 1157 | 848 | 150 | 76 |

These are an earlier snapshot only. The full 230-sale delivery wave changes current totals. Verify live counts with demo:seed --verify-only and SQL queries.

## Remaining release blockers and scope limits
- Pre-existing March 2026 fiscal close FAILS the mandatory Receivables agree to control account reconciliation; March remains OPEN. We neither force-close nor invent an unsupported adjusting journal.
- Original planning targets for standalone expenses, yearly bank/AR/AP reconciliation, 100+ supplier RFQs, complete salary and maintenance evidence are not yet met merely by passing summary counts.
- Product images and manufacturer reference authenticity need separate licensed asset review; no real manufacturer SKU claims are made.
- Browser E2E bilingual screenshots and mobile QA not yet comprehensively executed; the seed test suite is NOT a substitute for them.
- The optional 10x-30x W6 stress profile must use disposable test-run state, not an additional persistent development environment. Use EXPLAIN/query timing on the real single dev data in the meantime.

## QA gate
Strict CLI validates cardinalities, multi-month history, enum coverage of tickets/purchasing/tasks, stock non-negativity, posted journal balance, and fulfilled PO provenance. The older DemoVerificationSeeder is an informational report only (printed FAIL does not exit nonzero). Run the strict CLI for CI.
## Final strict-verified results — 2026-10-10

The complete unified seed executed to exit 0, including the 230 direct-sale journeys. Running exactly the same command again also exited 0. Fourteen critical table counts (users, customers, suppliers, products, variants, sales orders, invoices, payments, journal headers, journal lines, stock movements, visits, tickets and maintenance records) matched exactly before and after rerun. Files: storage/logs/unified-baseline-before-rerun.txt and unified-baseline-after-rerun.txt.

| Customers | Products | Variants | Suppliers | Warehouses | Leads | Quotations |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 149 | 141 | 395 | 30 | 10 | 155 | 366 |

| POs | Orders | Invoices | Payments | Journals (posted) | Stock movements |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 167 | 261 | 253 | 196 | 686 | 819 |

| Tasks | Visits | Support tickets | Maintenance requests |
| ---: | ---: | ---: | ---: |
| 1157 | 848 | 150 | 76 |

Strict validation additionally passed 12-month PO, quotation and support history; status outcomes; all 230 sales orders having completed delivery, issued invoice and genuine PDF; zero negative or over-reserved stock; zero unbalanced posted journals; and zero fulfilled procurements without a linked PO. The March fiscal period exception remains a separate unresolved legacy accounting discrepancy.

## Wave execution summary

W0: one worktree, safe reference-only bootstrap, local DB backup and production guard COMPLETE.
W1: bilingual catalog, representative suppliers and customers COMPLETE (real catalog media coverage remains outside seed scope).
W2: canonical cross-module workflows plus annual PO/inbound and 230 order-shipment-invoice-payment journeys COMPLETE for implemented scenarios.
W3: 12-month lead, employee task/visit, support, maintenance request workflows COMPLETE; not a substitute for all optional warranty/service certificates.
W4: history 12+ months for purchasing, CRM, employee plans and support COMPLETE; the detailed invoices/payments remain concentrated in September/October due to fiscal period constraints.
W5: strict CLI, idempotency, local tests and source-link checks COMPLETE for server-side integrity; browser screenshot and mobile E2E evidence still outstanding.
W6: separate long-running 10x–30x load not executed, deliberately avoiding a second permanent development environment; profiling can use disposable test datasets or read-only queries.

Priorities for full plan closure: resolve March 2026 AR control mismatch, add annual finance/expense/bank statement coverage where period posting is permitted, improve RFQ/maintenance service volumes, and execute EN/AR browser and mobile E2E against the seeded local UI.
## W5 Finance reconciliation phase (2026-10-10)

Run the additional, deliberately **failing** financial release gate after `demo:seed --verify-only`:

```powershell
php artisan demo:finance-audit --anchor=2026-10-10 --months=12
```

This read-only command uses the Accounting AR and AP services to compare dated subledger balances to their posted control accounts for each month. It also reports issued invoices without source-linked posted journals, posted payments without matching journals, and unreversed manual AR control entries. It never changes financial records or overrides fiscal close checks. It returns nonzero on any discrepancy; `demo:seed --verify-only` remains the operational/fulfilment gate, not a substitute for finance-audit.

**Verified findings:** 11 of 12 dated snapshots reconcile both AR and AP. The March 2026 AR ledger is short **AED 436.04**, because the legacy `CrmDemoSeeder` inserted `INV-CRMDEMO-000001` (issued March 9) and `PAY-CRMDEMO-000001` (posted April 9) as completed documents without posting either corresponding journal. The April 2026 fiscal period is already closed; do not fabricate an adjusting entry, reopen a closed period, or force-close March without deliberate accounting approval.

The October 2026 discrepancy was fully corrected, with an immutable accounting trail:
- Legacy `AccountingDemoSeeder` had posted `JE-000002` on October 5, debiting AR 1200 by AED 18,400 even though `INV-DEMO-2026-001` already posted its full AR/revenue entry. A Chief Accountant posted its domain-service reversal `JE-000690` on October 10; the original entry remains audit-visible. The legacy seeder was changed not to make that duplicate entry on future runs.
- `CN-DEMO-2026-001`, AED 450, was confirmed on October 9 but has issue and posted-journal date October 15. The AR service now recognises confirmed credit notes **no earlier than issue_date**, for linked and standalone credits, and dates customer statements on the effective date. This fixes the previously premature AED 450 customer credit, without changing the credit note or backdating any posting.

**October 10 tie-out after corrections:** AR subledger and control both AED 105,503.30; AP subledger and control both AED 4,200.00. No remaining unreversed manual AR control lines. Finance audit still appropriately exits 1 because of March's missing legacy invoice/payment journals; March remains OPEN.

Logs (not committed): `storage/logs/demo-finance-audit-corrected.log`, plus targeted accounting diagnostic logs in `storage/logs/`. Read-only diagnostic scripts and one-time guarded reversal script are under `storage/app/private/` and are excluded from the Git commit. The verification command and regression tests are versioned.


## Pristine database validation

A real `php artisan migrate:fresh --seed` run against an isolated local MySQL schema with isolated storage completed all migrations and unified seeder stages. This revealed two coverage gaps now resolved in the source: maintenance history creates 64 requests (76 total maintenance records on a pristine company); historical purchasing includes two short-closed orders and two authentic rejection decisions. PurchaseOrderApprovalService::reject() returns an order to draft with its rejection_reason; the strict verifier checks this evidence rather than falsely requiring the transient rejected enum as a persisted status. The isolated fresh database passes all strict integrity checks (230 order-to-invoice journeys, valid invoice media rows, no negative stocks, no unbalanced journals). The temporary schema and storage are disposable and do not replace the existing local ierp data.

## Live local UI smoke audit (2026-10-10)

Using the existing seeded local `ierp` database (without `migrate:fresh` or mutating business records), a temporary, loopback-only Laravel server and real headless Chrome session verified these operator screens. All seven authenticated desktop routes returned HTTP 200 and rendered a visible Filament `main`: `/admin/products`, `/admin/orders`, `/admin/invoices`, `/admin/purchase-orders`, `/admin/warehouses`, `/admin/tickets`, and `/admin/accounting-dashboard`. Three authenticated pages were repeated at a 390px mobile viewport (`orders`, `products`, `accounting-dashboard`), and the guest Arabic login rendered with `lang=ar`, `dir=rtl` and no horizontal document overflow at 390px. Result: **11/11 routes/viewports passed, zero observed JS page errors or HTTP 5xx responses.** This is a navigation/render smoke test, not an exhaustive EN/AR workflow, screenshot, accessibility, or Livewire interaction audit. The QA runner is stored only in ignored `storage/app/private/qa_seeded_ui_smoke.mjs`, and its output is in ignored `storage/logs/next-phase-ui-smoke.log`.

The read-only twelve-month finance audit on the **older, preexisting** `ierp` database still fails for March 2026 because obsolete `CrmDemoSeeder` had manually inserted `INV-CRMDEMO-000001` and `PAY-CRMDEMO-000001` without source-linked posted accounting journals (AED 436.04). It is not called by the new `DatabaseSeeder` during a clean `migrate:fresh --seed` run. This historical discrepancy must remain explicitly flagged until reconciled by authorized accounting procedures; it must not be silently edited or papered over with fake postings. Audit output is in ignored `storage/logs/next-phase-finance-baseline.log`.
