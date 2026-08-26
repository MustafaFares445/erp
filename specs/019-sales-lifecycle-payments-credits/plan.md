# Implementation Plan: Sales Module Completion — Quotation → Delivery Note → Invoice → Payment, with Credit Notes

**Feature Directory**: `019-sales-lifecycle-payments-credits` | **Date**: 2026-08-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/019-sales-lifecycle-payments-credits/spec.md`

## Summary

Make all six reserved `sales` sidebar items real, and wire the first three callers into the general ledger spec 018 left unwired.

The technical approach is shaped almost entirely by five settled owner decisions, and the plan's job is to honour them rather than re-derive them. D2 and D3 remove the two hardest schema questions by fiat: the built `orders` table is extended in place, and there is no `delivery_notes` table at all — Delivery Notes is a scoped surface over `InventoryOperation` where `operation_type = 'delivery'`, which is already the only thing in the system that moves stock outbound. D6 authorises exactly three posting events and no fourth. D7 replaces a tax-rate catalogue with one field on a settings singleton. D9 defers Stripe, and the shape that makes deferral safe is a single `PaymentPostingService` and a single `TaxRecognitionService` with no channel branch in either.

What is genuinely new is therefore narrower than the surface count suggests: four document families (quotations, invoices, payments, credit notes) with their lines, one settings singleton, two System-group reference resources, and one derived read surface. Everything stock-related reuses `InventoryOperationService`. Everything ledger-related reuses `JournalPostingService`. Everything pricing-related reuses `PriceResolver` and `PricingTierDiscountCalculator`. Everything file-related reuses Spatie Media Library. Three services are called by Filament actions — never by observers — so spec 018's structural no-posting-from-observers test survives untouched and keeps proving something true.

The single largest technical risk is not any one document; it is that `orders` and `order_lines` are live tables read by six built services. Every schema change to them is additive and nullable, and the existing fulfillment tests are treated as load-bearing regression evidence rather than as incidental coverage.

## Technical Context

**Language/Version**: PHP 8.4, `declare(strict_types=1)` throughout, Laravel 13.8

**Primary Dependencies**: Filament 5 (`/admin` panel), Livewire 3, `spatie/laravel-permission` 8 (authorization — Principle IV), `spatie/laravel-medialibrary` 11 (invoice and credit-note PDFs, payment proofs, receipt signatures — Principle IV), `spatie/laravel-activitylog` 5 (audit trail — ADR 0005), `spatie/laravel-data` 4 (service input DTOs). **One new dependency**: `barryvdh/laravel-dompdf`, authorised by owner decision D4 and by no other route.

**Storage**: MySQL via Eloquent. Twelve new tables, two altered in place (`orders`, `order_lines`), one additive seeded chart-of-accounts row.

**Testing**: Pest 4 on PHPUnit 12. Feature tests under `tests/Feature/Sales/`, unit tests under `tests/Unit/`, architecture rules in `tests/Unit/ArchTest.php`. Gate is `composer test` (Pint, PHPStan, type coverage, Pest with its existing minimum coverage threshold).

**Target Platform**: Laravel application served by Laravel Herd at `https://ierp-new.test`; the `/admin` Filament panel is the only interface. No API, no mobile client.

**Project Type**: Server-rendered dashboard module inside an existing modular-monolith Laravel application (constitution Principle II). Not a new project, not a new panel, not a new bounded deployment.

**Performance Goals**: Per `Docs/PRD.md` §Non-Functional, common operations including invoice creation respond in under 5 seconds under normal conditions. PDF generation and email delivery are explicitly excluded from that budget because Principle IV requires them to be queued.

**Constraints**: Every ledger posting, stock change, and payment allocation runs inside a database transaction (Principle III). Posting is atomic with the state change that triggers it — an invoice that fails to post stays a draft. Concurrency on document numbering and on invoice balances is handled by row locks, matching the four existing generators. No service resolves the acting user from the session; the actor is always an explicit argument, as spec 018 established and an architecture test proves.

**Scale/Scope**: 8 new Filament resources plus 1 extended, ~13 new models, ~14 migrations, ~11 services across two namespaces, 3 queued jobs, ~11 policies, 1 permission enum with 3 new dashboard roles, 1 seeder. This is the largest feature in the project's history, which ADR 0008 records as a knowing cost of owner decision D5.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design — see §Post-Design Re-check.*

Evaluated against constitution 1.8.0.

| Gate | Status | Evidence and reasoning |
|---|---|---|
| **I. Specification-First Development** | **Blocked → conditional pass** | Three governance artefacts must land before any code. ADR 0008 exists but is **Proposed**; it must be **Accepted**. The constitution is amended to 1.8.0 with the seventh Filament exception. `Docs/database/ERD.md` must carry all ten deviations in spec §ERD Divergence Register, and `Docs/PRD.md` §11 must list the ADR 0008 exception. These are Phase 0 tasks, blocking every later phase. |
| **II. Domain-Driven Modular Monolith** | **Pass** | Two new service namespaces, `App\Services\Sales` and `App\Services\Payments`, alongside the existing eight. No new panel, no new deployment unit, no cross-module reach-in: the sales services depend on `InventoryOperationService`, `PriceResolver` and `JournalPostingService` through their public interfaces, and nothing in Inventory or Accounting is modified to accommodate them. |
| **III. Financial & Inventory Integrity (NON-NEGOTIABLE)** | **Pass, and this feature is its subject** | Lifecycle order enforced by FR-019/024/036/041. Quotations touch no stock (FR-020). Delivery affects stock only through `InventoryOperationService` and recognises no tax (FR-034, FR-035). Invoices are the claim (FR-044). Tax recognised only on collection, proportionally, with the settling allocation absorbing the residue (FR-057, FR-058) — which is why `2350 Deferred Sales Tax` is not optional. One posting service and one recognition service, no channel branch, enforced by architecture test (FR-061). No confirmed document is ever physically deleted (FR-043, FR-060, FR-065); correction is credit note or reversal. Every posting, allocation and stock change is transactional. |
| **IV. Unified Access, Media & Payment Standards** | **Pass with one recorded deviation from local precedent** | Authorization is `spatie/laravel-permission` via a `sales.*` catalogue and policies; no custom scheme. Invoice and credit-note PDFs, payment proofs and receipt signatures all use Media Library, and no `invoice_files` table is created (spec E-4). This departs from `InventoryExport`'s `file_path` column, deliberately — see research.md R-006. Manual payment methods are admin-defined (FR-051). Stripe is deferred by D9, and Principle IV's requirement that both channels share tax logic is met by FR-061's single-service constraint rather than by building Stripe. PDF generation and email are queued jobs (FR-047, FR-048). |
| **V. AI Isolation & Human Oversight (NON-NEGOTIABLE)** | **Pass, not engaged** | No AI in this feature. The one AI-adjacent touchpoint is FR-025: a quotation may be created from an *already approved* sales opportunity, which is the human-review gate Principle V requires, downstream of it rather than around it. |
| **VI. Engineering Discipline for Coding Agents** | **Pass with one explicit, owner-authorised violation** | Explicit types, Pint, PHPStan with no new baseline entries, a test per behaviour change. The violation is size: `.ai/feature-development` §3 requires small reviewable changes, and D5 knowingly bundles three extraction entries. Recorded in §Complexity Tracking with its agreed mitigation. |
| **Product Scope & Boundaries** | **Conditional pass** | Requires ADR 0008 accepted (gate I). Within that exception the scope holds: no API, no customer app, no Stripe, no reports, no AR/AP subledger pages, no COGS, no credit limits. |
| **Specification Governance** | **Pass** | Delivers documented entries 007, 008 (manual channel) and 009. Their shared prerequisite `006` is built as 018; `005` is built. No prerequisite is skipped. The three-entry bundle supersedes ADR 0007's contrary judgement by explicit owner decision, recorded in both ADR 0008 and constitution 1.8.0 rather than assumed. |

**Two prohibitions this plan must actively keep true, not merely avoid breaking:**

1. **No fourth ledger caller.** ADR 0006's Purchasing prohibition and ADR 0004's ticket-payment exclusion both survive. `NoAutomaticPostingTest` is *tightened*, not relaxed: every existing assertion stays, and a new one asserts the ledger's source types are exactly `Invoice`, `Payment` and `CreditNote`.
2. **No second stock-writing path.** D3 removes the temptation structurally — there is no delivery table to write against. The Delivery Notes surface reuses `InventoryOperationService::markReady()`, `dispatch()`, `complete()` and `cancel()` and adds no stock mutation of its own.

## Project Structure

### Documentation (this feature)

```text
specs/019-sales-lifecycle-payments-credits/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── permissions.md   # sales.* catalogue, roles, policy matrix
│   ├── posting.md       # The three authorised ledger postings, exactly
│   ├── tax-recognition.md  # Proportional recognition and residue absorption
│   └── lifecycles.md    # State machines for all five documents
├── checklists/
│   └── requirements.md  # Written by /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── SalesPermission.php            # sales.* catalogue (FR-071)
│   ├── QuotationStatus.php            # FR-019
│   ├── QuotationDecision.php          # accepted | rejected (FR-021)
│   ├── OrderPaymentStatus.php         # FR-026
│   ├── InvoiceStatus.php              # FR-041
│   ├── InvoiceConfirmationType.php    # FR-049
│   ├── PaymentStatus.php              # FR-052
│   ├── CreditNoteStatus.php           # FR-063
│   └── DashboardRole.php              # EXTENDED: 3 sales roles (FR-073)
├── Models/
│   ├── SalesSetting.php               # singleton, InventorySetting precedent
│   ├── PaymentTerm.php  PaymentMethod.php
│   ├── Quotation.php  QuotationLine.php
│   ├── Invoice.php  InvoiceLine.php  InvoiceConfirmation.php
│   ├── Payment.php  PaymentAllocation.php  TaxRecognitionEntry.php
│   ├── CreditNote.php  CreditNoteLine.php
│   ├── Order.php                      # EXTENDED: totals, quotation, payment status
│   └── OrderLine.php                  # EXTENDED: unit price, tax, line total
├── Services/
│   ├── Sales/
│   │   ├── SalesAccountResolver.php       # FR-007 guard over sales_settings
│   │   ├── LineTotalCalculator.php        # FR-017, FR-018 — shared by all four documents
│   │   ├── DocumentNumberGenerator.php    # FR-014, FR-039 — one generator, four prefixes
│   │   ├── QuotationService.php           # FR-013..023
│   │   ├── QuotationConversionService.php # FR-024, FR-029
│   │   ├── InvoiceService.php             # FR-038..043, FR-049, FR-050
│   │   ├── InvoicePostingService.php      # FR-044..046 (ledger caller 1)
│   │   ├── CreditNoteService.php          # FR-063..065, FR-067
│   │   ├── CreditNotePostingService.php   # FR-066, FR-068 (ledger caller 3)
│   │   └── Exceptions/
│   └── Payments/
│       ├── PaymentService.php             # FR-052..055, FR-059, FR-060
│       ├── PaymentAllocationService.php   # FR-054
│       ├── PaymentPostingService.php      # FR-056 (ledger caller 2) — no channel branch
│       ├── TaxRecognitionService.php      # FR-057, FR-058 — no channel branch
│       └── Exceptions/
├── Jobs/
│   ├── GenerateInvoiceDocument.php    # FR-047
│   ├── GenerateCreditNoteDocument.php # FR-069
│   └── SendInvoiceEmail.php           # FR-048
├── Policies/                          # one per new model (FR-072 separations)
└── Filament/
    ├── AdminModuleRegistry.php        # EXTENDED: sales_settings item
    └── Resources/
        ├── Quotations/  DeliveryNotes/  Invoices/  Payments/  CreditNotes/
        ├── SalesSettings/  PaymentTerms/  PaymentMethods/
        └── Orders/                    # EXTENDED: View + Edit pages, priced lines

database/
├── migrations/                        # 12 create + 2 alter
└── seeders/
    ├── SalesPermissionSeeder.php
    ├── ChartOfAccountsSeeder.php      # EXTENDED: 2350 Deferred Sales Tax
    └── SalesDemoSeeder.php

lang/en/admin.php                      # EXTENDED: all new labels (FR-078)

resources/views/pdf/                   # invoice + credit note Blade templates

tests/
├── Feature/Sales/                     # per-story feature tests
├── Feature/Accounting/
│   └── NoAutomaticPostingTest.php     # TIGHTENED, not relaxed (FR-080)
└── Unit/                              # calculators, enums, policies, ArchTest
```

**Structure Decision**: The existing `app/` layout is used unchanged — no new base directory, per CLAUDE.md. Two service namespaces rather than one (`Sales`, `Payments`) because Principle IV and D9 treat payment collection as a channel-agnostic concern that a future Stripe feature extends, while the sales documents are not extended by it; collapsing them would put the one class a future feature touches inside a namespace named after the one it does not. `DeliveryNotes/` is a Filament resource directory with **no** model of its own — it reads `InventoryOperation`, which is what D3 means in practice. PDF Blade templates live under `resources/views/pdf/`, a new subdirectory of an existing directory rather than a new root.

## Phasing

The P1 → P3 story ordering in the spec is a binding phase boundary, not a label. Constitution §Specification Governance says so explicitly, and ADR 0008 §Two reversals makes it the agreed mitigation for D5's size. Each phase below ends at a point where the suite is green and the increment is independently reviewable.

| Phase | Story | Delivers | Ends when |
|---|---|---|---|
| **0** | — | Governance gate: ADR 0008 accepted, ERD deviations recorded, PRD §11 updated, `laravel-dompdf` installed | Every Principle I artefact is merged. **Blocks all later phases.** |
| **1** | US1 (P1) | `SalesPermission`, seeder, three `DashboardRole` cases, policies, registry wiring | Permission matrix and role-narrowing tests pass; pages 403 correctly with no documents in existence |
| **2** | US2 (P1) | `sales_settings`, `payment_terms`, `2350 Deferred Sales Tax`, `SalesAccountResolver`, `LineTotalCalculator` | Due-date and overdue derivation, single-default invariant, account guard, and tax arithmetic all tested |
| **3** | US3 (P1) | Quotations end to end, priced from `PriceResolver`, decision recording, opportunity link | A quotation can be quoted, sent and decided; no stock row and no ledger row exists |
| **4** | US4 (P2) | `orders`/`order_lines` extension, `QuotationConversionService`, Orders View/Edit | Conversion is exact and idempotent; **every pre-existing fulfillment test still passes** |
| **5** | US5 (P2) | Delivery Notes surface over `InventoryOperation` | Delivery completes from the new surface with identical movements and zero ledger rows |
| **6** | US6 (P2) | Invoices, PDF job, email job, append-only confirmations | Invoice immutable once issued, undeletable, PDF attached as media |
| **7** | US7 (P2) | `InvoicePostingService` — ledger caller 1 | Issuance atomic with a balanced entry; tax-payable untouched; closed period refuses |
| **8** | US8 (P3) | Payment methods, payments, allocations, posting, proportional recognition | Recognised tax equals invoice tax exactly across any split; single-service architecture test passes |
| **9** | US9 (P3) | Credit notes, reversal posting split by recognition ratio | Invoice corrected without deletion; confirmed note immutable |
| **10** | — | Cross-module isolation, labels, demo seeder, quality gate | `NoAutomaticPostingTest` tightened to exactly three sources; `composer test` green |

Phases 1–3 are shippable as a quoting module with no invoicing. Phases 4–7 add the claim. Phases 8–9 add collection and correction. If review capacity runs out mid-feature, those are the three defensible stopping points.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **Three extraction entries in one feature**, against `.ai/feature-development` §3 and ADR 0007's explicit contrary judgement | Owner decision D5 of 2026-08-23. The tax-recognition rule spans all three entries, so specifying it three times risks three readings of a NON-NEGOTIABLE principle; and an invoice with no payment path cannot demonstrate its own central invariant | Splitting into 019/020/021 was ADR 0007's recommendation and is what this reverses. Rejected by the owner, not by this plan. Mitigation is the binding phase table above; if it degenerates into one undifferentiated batch, the mitigation has failed and the constitution says to treat that as a governance failure |
| **Wiring documents to the ledger**, against ADR 0007's bolded no-automatic-posting rule | Owner decision D6. ADR 0007 said connecting a document belongs to that document's own feature and ADR; ADR 0008 is that ADR | Leaving invoices unposted was considered and rejected: an invoice with no accounting trace makes the receivable unverifiable, and every historical invoice would need backfilling when posting was added |
| **Altering two live tables** (`orders`, `order_lines`) read by six built services | Owner decision D2. A parallel `sales_orders` table would leave two order records able to disagree, and would orphan the `InventoryOperation.source_document` morph that already points at `Order::class` | A new table was the ERD-literal reading and was rejected at 018 |
| **Two service namespaces** instead of one `Sales` | D9 makes payment posting the extension point a future Stripe feature touches; naming it `Sales` would misdirect that feature | One namespace is fewer directories but puts channel-agnostic collection logic under a document-specific name |
| **A `sales_settings` singleton holding posting accounts**, rather than config constants | FR-007 requires the accountant to own which accounts are hit, and requires a runtime guard when a chosen account is later made non-postable | Hardcoded account codes (`1200`, `4100`, `2350`, `2300`) were rejected: the chart of accounts is user-editable, so a code constant is a latent break |

## Post-Design Re-check

Re-evaluated after Phase 1 artefacts (research.md, data-model.md, contracts/, quickstart.md).

- **No new violation appeared.** The design added no table, service or surface beyond those the Constitution Check anticipated, and removed one candidate: an `InvoiceBalanceService` was folded into `PaymentAllocationService` after research R-009 showed balance derivation and allocation validation cannot be separated without reading the same rows twice under the same lock.
- **Principle III strengthened during design.** Two mechanisms were added that the spec implied but did not name: a row lock on the invoice during allocation, so the concurrent-overallocation edge case is refused rather than racing (data-model.md §Invoice); and residue absorption keyed to the *settling* allocation rather than to a running remainder, so FR-058 holds regardless of allocation order (contracts/tax-recognition.md §3).
- **Principle I still blocking.** ADR 0008 remains **Proposed**. Phase 0 is unchanged and no implementation task may begin.
- **Complexity unchanged.** Five entries in §Complexity Tracking, none added by design, none resolved by it.
