# IERP Unified Development Dataset

## One environment
Root: C:\laragon\www\ierp-new. Branch: dev. Local MySQL: ierp on 127.0.0.1. Only one Git worktree remains; unpublished changes from old worktrees were preserved in Git stashes. No other persistent development environment was created.
Pre-seed backup: storage/app/private/backups/ierp-pre-unified-20261010.sql.

## Commands
  php artisan db:seed
  php artisan demo:seed --dry-run --anchor=2026-10-10
  php artisan demo:seed --anchor=2026-10-10
  php artisan demo:seed --verify-only --anchor=2026-10-10
  php artisan test tests/Feature/Demo/DemoMonthSeederTest.php tests/Feature/Demo/UnifiedDevelopmentSeederTest.php

DatabaseSeeder is reference/permissions only. It does not create default fixed-password admin users. Demo seeders work only in local/testing. The CLI refuses nonlocal MySQL hosts. It does not offer a destructive truncate/reset. Synthetic identifiers and test-domain emails must not be mistaken for real customer data.

## Unified seeder stages
1. DemoMonthSeeder: curated complete Sep/Oct 2026 workflows for purchasing, receiving, inventory, sales, accounting, employees, CRM, support, warranty, installation and calibration.
2. UnifiedMasterDataSeeder: 14 bilingual categories, 112 synthetic product families, 336 variants, 124 new UAE clinic/lab customers, 18 suppliers, 4 warehouses.
3. UnifiedPurchasingHistorySeeder: 156 supplier commitments over twelve calendar months, domain-driven approval/PO inbound allocation and real inventory receipts.
4. UnifiedSalesHistorySeeder: 140 CRM leads with interactions plus 330 quotation states and history.
5. UnifiedSalesFulfillmentSeeder: 230 stock-backed order -> reservation/shipment -> delivery -> PDF invoice -> payment/AR split; respects mail suppressions.
6. UnifiedEmployeeHistorySeeder: 72 employee monthly plans, 504 tasks and 360 GPS-proximate visits with service-driven status transitions.
7. UnifiedSupportHistorySeeder: 120 support cases, triage, assignments and service states.
8. UnifiedMaintenanceHistorySeeder: 60 maintenance, calibration and installation REQUESTS across the year (not fabricated completion certificates).

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