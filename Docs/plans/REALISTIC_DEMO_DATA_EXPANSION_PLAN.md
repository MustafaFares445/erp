# IERP — Realistic Connected Demo Data Expansion Plan

Status: Implementation in progress; several seed waves executed in the single local dev database. See Docs/reference/UNIFIED_DEVELOPMENT_DATA.md for verified counts and remaining release blockers.
Basis: direct code review on branch dev, 2026-10-10.
Scope: Laravel seeders, factories, demo verification, UI/QA fixture readiness.

## 1. Objective and boundaries
Build a believable synthetic UAE dental/medical equipment distribution company with 12 months of history, current operational queues, future scheduled work, and full cross-module traceability. Data must support real Filament dashboard UX, customer/employee apps, demos, reporting, and E2E QA. "Realistic" means accurate product terminology, sensible commercial distributions, real service-driven workflows, valid transitions and internally reconciled balances. It does NOT mean using real personal/customer/payment information.

Do NOT execute seeders against production; do not change production records; never use real payment gateways, emails, AI transcription or push providers. Use synthetic .test/.example identities and safe media fixtures.

## 2. Baseline findings before implementation (historical; see unified data report for current state)
- database/seeders/DatabaseSeeder.php invokes defaults PLUS older *DemoSeeder classes. It also creates admin@ierp.com with hard-coded password 'password'. This must be made non-production-safe before expansion.
- database/seeders/Demo/DemoMonthSeeder.php is a separate coherent, staged, service-based demo for 2026-09-04 through 2026-10-03. It is NOT called by DatabaseSeeder.
- DemoFixtures defines 15 primary customers, 7 suppliers, 3 warehouses, 6 categories, 20 products and 42 variants; DemoEmployeeRosterSeeder defines 6 employees, and DemoSupportMonthSeeder adds 2 support workers.
- DemoSalesQuotationScenes defines 28 quotations, 6 converted; DemoEmployeeMonthSeeder documents 47 tasks and 35 visits; DemoSupportMonthSeeder documents 15 tickets and 8 maintenance requests; DemoCrmMonthSeeder defines 15 leads and six campaigns; DemoAccountingMonthSeeder defines 11 expense scenarios.
- Demo catalogue copies English labels to name_ar; specialized DentalCatalogSeeder has richer product taxonomy, branded dental products, attributes and translations. Review and consolidate semantics rather than duplicating catalogs.
- DemoContext fixes scene dates to Sep/Oct 2026; DemoBaselineSeeder creates fixed 2026 fiscal periods; older seeders sometimes use now(). Seasonal/current dashboard data therefore ages.
- DemoVerificationSeeder checks multiple integrity/report metrics, but its PASS/FAIL output is informational, and coverage() lists only statuses PRESENT, not all valid enum cases. Add machine-enforced assertions.
- DemoSeeder blocks production, forces local queue/mail/fake transcription but currently changes runtime config without explicitly restoring every overwritten value. Preserve/restores of side-effectful app state should be tested.
- Legacy demo seeder sets (InventoryDemoSeeder, AccountingDemoSeeder, SalesDemoSeeder, etc.) and DemoMonth use different IDs/customers/catalogs and must not silently merge into a single official reference dataset.
- Existing factories cover many models; use them for unit/feature tests and high-volume safe object construction. The canonical demo must use domain services for transaction state changes.

## 3. Target profiles and command surface
A. reference: production-safe lookups/config/permissions only; NO business/demo accounts.
B. showcase: ~45-60 days, ~30-60 customers, complete curated end-to-end storylines, for presentations/screenshots.
C. realistic (primary): 12 completed months + current month-to-date + 30-60 days of scheduled/pending activities; figures below.
D. stress: 10x-30x more volume (e.g. 20k+ order lines), isolated disposable database, performance tests only.

Provide one guarded CLI interface (proposal): php artisan demo:seed --profile=realistic --anchor=2026-10-10 --seed=445566 --verify
Options should include --dry-run and separate --verify; explicit --reset-demo is local/testing only, must preserve non-demo records. Current staged DemoMonthSeeder can be refactored behind this command. Do not implement reset-by-truncate.

## 4. Twelve-month target volumes (proposed, not existing counts)
| Area | Proposed records / breadth |
| --- | --- |
| Identity/access | 12-20 dashboard users with permitted roles, 15-25 employees (including sales, technical support), disabled/pending cases |
| Customers and CRM | 120-200 customers, 100-180 leads, 400-800 interactions, 100-200 opportunities, 12-24 campaigns |
| Suppliers/procurement | 20-35 suppliers, 200-350 supplier references, 100-180 RFQs/negotiations, 150-250 POs, 180-350 receipts/inbound events |
| Dental catalogue | 120-180 distinct products, 250-400 variants, 10-15 categories, true AR/EN labels, credible brands/specifications and UOMs |
| Warehousing | 5-7 warehouses, 25-60 bins, 250-500 lots, 60-150 serialized units, 1,200-2,500 inventory movements |
| Sales | 350-550 quotations, 250-400 orders, 200-350 fulfilled deliveries/shipments, 250-400 invoices |
| Payments/returns | 300-600 payments including allocations, 30-80 credit notes, 15-50 refunds/returns/write-offs |
| Accounting | 200-350 supplier bills, 150-300 expenses, 700-1,500 journals including postings/reversals, 12+ fiscal periods |
| Employees | 120-200 monthly plans, 500-900 tasks, 350-650 visits, GPS trails, notes, AI reviews, commissions/salaries |
| Support | 120-220 tickets, 60-100 maintenance cases, 40-100 appointments/service records, selected installations/calibrations |
| System activity | 1,000+ genuine notifications/audit events as consequences of workflows; useful distributions across months |

Targets are volume budgets, not hard-coded success limits. Measure database storage, run time and UI usability. Use fewer expensive media assets than transactional rows.

## 5. Realism rules
- Industry-specific dental distribution: 3D printers, photopolymers, milling supplies, implants/abutments, gloves, sterilization, consumables, spare parts, repairable equipment and services. Product types, tracking flags, warranty triggers, UOM, compatibility, packaging and stock location must be coherent.
- Include AR/EN names, descriptions, brands, actual category hierarchies, product attributes and variants. Avoid reusing English in name_ar. Validate public manufacturer specifications and permission to reuse any logos/product images before importing; do not invent manufacturer part numbers or claim fabricated products are authentic.
- Use plausible synthetic dental clinics, laboratories, distributors, hospitals and contacts around UAE emirates; use reserved demo emails/phone-like non-dialable placeholders; consistent addresses and synthetic GPS locations.
- Model skewed sales demand: top 15-20% of products generate most repeat volume, some slow movers and inactive/discontinued items. Customer purchasing power varies, with several key accounts and many occasional accounts.
- Values must be derived: variant cost < normal sale price unless explicitly justified; defined pricing tiers and valid price floors; purchase-to-sale lead times, part payments and currency conversions consistent with settings. Do not invent tax exemptions or tax rates in conflict with the system's configured UAE policies.
- Multiple suppliers and lead times for key items, supplier delays/backorders, price changes, and alternate sourcing. Keep all procurement confirmations traceable to their own PO.
- Historical activity across weekends/holidays/time zones is plausible; use reproducible weighted generation and anchored calendar dates. Do not write arbitrary state flags to simulate outcomes.
- Small licensed/demo-safe images, branded PDFs, QR/barcode samples, signed delivery evidence, payment proofs and attachments should be valid assets with fictional content, not broken text placeholders. Keep private media private.
- Synthetic data must be visibly identifiable through stable DEMO keys/ownership metadata; avoid real person or payment data.

## 6. Required scenario matrix (each gets at least 2-5 valid examples per reachable outcome)
### 6.1 Catalog, pricing and customer access
Active/inactive products; variants and UOM conversion; barcode/serial/lot; expiry vs non-expiry; multi-warehouse availability; tier pricing, discounts and floor overrides; guest/pending/approved/rejected customer visibility and prices; profile change review; duplicated-looking names with distinct SKUs.

### 6.2 Purchasing -> receiving -> inventory -> AP
RFQ -> comparison -> supplier selection -> PO draft/approval/sent/confirmation -> partial/full inbound -> warehouse receipt -> supplier bill -> partial/full supplier payment -> accounting. Include PO above approval threshold, delayed supplier, alternative supplier, shortage/damage on receipt, receipt correction, supplier return, bill discrepancy, overdue AP and cancellation before receipt. No stock receipt without its approved permitted purchase source.

### 6.3 Inventory operations and fulfillment
Opening inventory via valid source; multiple bins/warehouse types; reservation/allocation/backorder; FEFO lot selection; near-expiry/expired/quarantined; serialized custody; transfer draft/ready/transit/partial/shortage/completed/cancelled; count, recount, adjustment, correction, damage/recovery/disposal; kit/package/shipment; partial delivery; return with restock vs scrap; sales-demand procurement for shortages; replenishment alerts and policies.

### 6.4 Sales -> payment -> accounting
Quote draft/sent/viewed/accepted/rejected/expired/revised -> order direct/converted -> release/procurement dependency -> delivery/partial/complete -> invoice draft/issued/sent/receipt-confirmed -> unpaid/partial/paid/overdue -> manual/provider payment, allocation, deposit application, failed/provider-unsettled/reversed transaction -> credit note/refund/write-off. Tax registers and double-entry postings must reconcile using services and configured tax rules. Include mixed terms Net7/15/30/45, large/small orders and recurring accounts.

### 6.5 CRM and employee operations
New/qualified/converted/disqualified leads; stage progression, won/lost/stalled opportunities, reasons and owners; interactions/campaign delivery/response; realistic monthly plans, on-time/late/no-show site visits, GPS trails, task completion, voice transcript success/failure, AI false-positive requiring review, lead/quote generated from an employee visit, variable monthly performance and salary/bonus. Visit AI failure must never block completing the visit.

### 6.6 Support, warranty and service continuity
Ticket severity/SLA calendars/assignment, pending payment/paid, software vs hardware, customer waiting, reopen/escalation and CSAT; warranty eligible/expired/excluded/partial/supplier-backed vs customer chargeable; owned serialized equipment and receipt/custody; installation, commissioning and acceptance; maintenance preventive/corrective; successful/failed calibration with measurements, certificate and follow-up; loaner issue/return, supplier RMA and recovery; service parts consumed and invoice/payment responsibility. Seed optional routing/automation/knowledge features only when flags are explicitly on.

### 6.7 Finance/reporting/system
AR/AP aging spread across current, 1-30, 31-60, 61-90, >90 days; customer and supplier balances; on-time and overdue payments, cleared/unmatched bank items, supplier credit, deposits; fiscal close checks, valid reversals, draft journals, multi-period revenue and cash trends; KPI drill-down dataset, notifications, real audit trail, failed integration records restricted to safe fakes. No negative stocks, imbalanced posted journals or fraudulent final statuses.

## 7. Architecture
1. Split production reference seeders from demo seeders; DatabaseSeeder must not create trivial-password accounts or demo business rows in production. Prefer env-confirmed bootstrap credentials if such bootstrap is needed.
2. Keep current DemoMonthSeeder as the canonical curated case study and add a versioned scenario manifest describing prerequisites, scenario key, table targets, expected final state and required assertions.
3. Add modular generators by domain, with deterministic RNG + weighted fixtures + service-driven transitions. Suggested staging: Reference -> Actors/Parties -> Catalog/Suppliers -> Opening Stock -> Purchasing/Receiving -> Orders/Delivery -> Invoices/Payments/Accounting -> Employees/CRM -> Support/Maintenance -> Notifications -> Verification.
4. Separate fixed showcase anchors from dynamic realistic anchors. Use date offsets for transactions and periods; preserve actual event ordering and fiscal calendars.
5. Use unique natural DEMO IDs and per-scenario idempotency rather than a single flagship-record guard; transactional resume after partial failure should be testable. Save run metadata/manifest if appropriate.
6. Use DB transactions per atomic business event, not one giant multi-domain transaction. Domain services must own ledger, stock, tax, warranties and transitions. Restore test clock, auth user, mail, queue and fake integration bindings after execution.
7. Use factories for negative-input tests and stress loads where full service-based generation is prohibitively expensive; stress rows must still respect schema and business invariants.

## 8. Verification and quality gates
- Zero invalid foreign keys, duplicate business references, non-demo data modifications, unbalanced posted journals, negative/over-reserved stock, lot/serial custody mismatches, incorrect invoice/payment allocation, and orphan attachments.
- Every inventory-affecting event is traceable to an approved document/movement; PO receipt, delivered quantity, stock, AP and tax reconcile; manual and provider payments use the same posting logic.
- 100% of reachable, relevant enum STATUS cases have at least two meaningful examples OR appear in an explicit justified exclusion manifest (optional features/non-reachable states). Do not merely print existing groups; enumerate enum cases.
- 100% of curated critical cross-domain journeys pass assertions for links, valid event chronology, expected status, amount, actor, ledger balance and customer ownership.
- Present meaningful current-month and trailing-month report data with intentional active queues and at least 12 non-empty monthly points for annual reports. KPI filters, sorting, search, pagination and empty-state workflows must be browser tested.
- Run verification as a failing command (nonzero exit code) on any broken invariant. Keep existing DemoVerificationSeeder output if desired but surface assertions as tests/command.
- Re-run same profile + anchor + seed twice: counts and business totals unchanged. Different seed yields different realistic mix but consistent configured totals/invariants.
- Smoke test demo customer and employee mobile APIs, AR/EN, RTL display, image/media validity, and role permissions. No external deliveries triggered.
- Compare before/after count snapshots, core reporting totals and duplicate business key checks; verify at least one realistic QA journey for each major module.
- Mark which example states are impossible through normal services and create them ONLY as isolated negative E2E fixtures, never by corrupting the canonical demo dataset.

## 9. Implementation waves
W0 — Safety and audit (P0): Inventory all seeder entry points, remove demo writes from production bootstrap, isolate old vs canonical demo catalogs, fix default credentials, capture status enum matrix and current baseline; no mutation of existing business data.
W1 — Catalog and parties (P0): Bilingual industry catalog, multi-UOM/products/brands/media rules, 120-200 customers, suppliers, roles and employees. Product taxonomy and source data review.
W2 — Operational core (P0): Purchases/PO receipt, inventory stock/lot/serial/reservation/adjustment, sales orders/fulfillment, invoicing/payment/accounting. Build 20-30 named golden-path and edge-path storylines using domain services.
W3 — People and after-sales (P1): CRM/employee visits and plans, support/warranty/install/calibration, service continuity; maintain ownership and notification links.
W4 — Historical volume (P1): Add weighted deterministic 12-month dataset behind profiles; ensure seasonality and current dashboards, realistic AR/AP and aging.
W5 — Verification and UI QA (P0 release gate): Strict invariants, resumability/idempotence, browser E2E against seeded DB, screenshot guide, balanced ledgers, support scenarios and performance budgets.
W6 — Optional stress (P2): High-volume reproducible isolated dataset and measured query/index impact. Never seed stress data into shared demo/development DB without explicit target selection.

## 10. Done definition
- Reference, showcase and realistic profiles are independent, documented, safe and rerunnable.
- A reviewer can follow at least 20 named linked business cases across screens and related records.
- All relevant pages have varied, meaningful data and all major filters show useful results.
- Financial, inventory, warranty, salary and status assertions pass automatically.
- Realistic profile covers 12 historical months plus current/future queues and bilingual assets.
- No real integrations, real personal details, privileged shared passwords, or production data modifications.
- README/Docs contain exact commands, test accounts created ONLY in local/demo, profiles, expected durations/size measured by implementation, and reset safety guidance.

## 11. Immediate next engineering action
Before adding volume, implement W0 and convert DemoVerificationSeeder into a reproducible CI-quality baseline. Once safety and cardinality/coverage reports are reliable, add W1 and W2 in separate reviewable PRs. Preserve existing uncommitted changes in branch dev.
