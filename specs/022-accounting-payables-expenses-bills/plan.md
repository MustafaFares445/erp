# Implementation Plan: Accounting Payables — Expenses, Supplier Bills, and Accounts Payable

**Branch**: `dev` (working tree contains unrelated in-progress changes) | **Date**: 2026-08-26 | **Spec**: [spec.md](spec.md)

## Summary

Complete the Accounting payables slice specified by `022`: expenses, supplier bills, supplier payments, and a computed Accounts Payable read surface. The only cross-module integration is read-only Accounting → Purchasing: a `bill_lines.purchase_order_line_id` reference compares ordered, cumulatively received, and cumulatively billed quantities. Purchase-order creation itself must not create a bill, payable, journal entry, or accounting-side record.

The existing PO → Receipts flow is already implemented and remains the source of truth for receiving. `PurchaseOrder::receipts()` and `PurchaseOrderReceivingService` use the existing `InventoryOperation::source_document` morph, and receipt completion updates PO received quantities inside the inventory transaction. This plan finishes the accounting-side reference and posting workflows without changing Purchasing classes, tables, columns, or surfaces.

Implementation is gated until ADR 0011 is accepted, ADR 0006 is amended narrowly, the constitution amendment is merged, and all ERD divergences are recorded. The current dirty accounting files are treated as partial work to reconcile against this approved contract, not as a completed implementation.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.24, Filament 5.7, Livewire 4.3, Eloquent, Spatie Activitylog, Spatie Media Library, Pest 4, Larastan 3, Pint 1

**Storage**: MySQL; existing `purchase_orders`, `purchase_order_lines`, `inventory_operations`, `chart_accounts`, `journal_entries`, and `payment_methods`, plus the five payables tables defined in [data-model.md](data-model.md)

**Testing**: Pest feature/unit tests; focused `php artisan test --compact`, Pint, PHPStan, architecture tests, and the existing 017/018 regression suites

**Target Platform**: Existing Laravel Filament `/admin` panel; no API or supplier portal

**Project Type**: Laravel web application with an internal Filament administration panel

**Performance Goals**: Payable aging and supplier detail use bounded aggregate queries; query count must not grow with supplier, bill, expense, or payment count (FR-050)

**Constraints**: No Purchasing-side accounting dependency; no inventory valuation or cost-of-goods-sold posting; no reuse of customer `payments`; all approval/payment writes are transactional and lock status rows; posted journal entries remain immutable; no new dependency

**Scale/Scope**: Three Accounting navigation surfaces, five persisted payables entities, four named posting callers, one advisory PO-line match, English labels, streamed CSV exports

## Constitution Check

### Pre-design gate: BLOCKED

The design is technically coherent, but implementation cannot begin until all four gates in `spec.md` §Governance Gate are satisfied:

1. ADR 0011 moves from `Proposed` to `Accepted`.
2. ADR 0006 receives the narrow amendment allowing Accounting documents to reference PO/PO-line data for advisory matching while preserving the Purchasing prohibition.
3. The constitution records the Accounting payables exception and the four named posting callers.
4. Every 022 ERD divergence is recorded in `Docs/database/ERD.md`.

No source code or migration implementation is authorized by this plan before that gate is cleared.

### Post-design gate

The design passes the architectural constraints once the approvals land:

- Accounting owns all payable models, services, policies, resources, and posting callers.
- Purchasing is read by Accounting only; no `PurchaseOrder` or `PurchaseOrderLine` change is planned.
- `InventoryOperationService` remains the sole stock writer, and existing receipt completion behavior is unchanged.
- `JournalPostingService` remains the sole ledger writer; only bill approval, expense approval, expense payment, and supplier payment may call it for this feature.
- Payable aging is computed and read-only; no `accounts_payable` balance table or stored balance column is introduced.

## Project Structure

### Documentation

```text
specs/022-accounting-payables-expenses-bills/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
└── quickstart.md
```

No public API contract is needed: this is an internal Filament feature. A later task-generation phase may add `tasks.md` after the governance gate is approved.

### Source Code

```text
app/
├── Enums/                         # payables statuses and accounting permissions
├── Models/                        # Expense, Bill, BillLine, SupplierPayment, Allocation
├── Policies/                      # recording, approval, payment, and read permissions
├── Services/Accounting/           # document lifecycle, supplier payments, payable aging
├── Filament/Resources/
│   ├── Expenses/
│   ├── Bills/
│   ├── AccountsPayable/
│   └── SupplierPayments/
└── Listeners/                     # no new Purchasing listener; existing receipt listener stays unchanged

database/
├── migrations/                    # five payables tables and additive account seed support
├── factories/
└── seeders/

tests/
├── Feature/Accounting/
├── Feature/Purchasing/            # regression-only assertions; no Purchasing implementation changes
└── Unit/
```

**Structure Decision**: Use the existing Laravel/Filament module layout and extend the existing Accounting service boundary. Do not create repositories, API controllers, or a second inventory/accounting write path.

## Implementation Phases After Approval

1. **Governance and baseline**: verify the four approvals, preserve the dirty worktree, reconcile the partial payables files against `spec.md`, and add architecture assertions for Accounting → Purchasing only.
2. **Schema and domain foundation**: add the five tables, indexes, foreign keys, statuses, permissions, factories, models, immutable-state guards, activity logging, and the `1450 Recoverable Input Tax` seed without changing PO tables.
3. **Expense lifecycle**: draft creation/edit/delete, receipt media, separate approval and recording permissions, closed-period checks, approval posting, payment settlement posting, and model/service immutability.
4. **Bill and PO-line matching**: bill lines, supplier-invoice uniqueness per supplier, payment-term due dates, optional PO/PO-line references, cumulative received aggregation from completed receipt operations, cumulative billed aggregation, advisory quantity/price variance display, and bill approval posting.
5. **Supplier payment lifecycle**: dedicated supplier payment and allocation models, lock-based allocation validation, multi-bill settlement, closed-period checks, payment posting, atomic rollback behavior, and immutable paid payments.
6. **Accounts Payable surface**: computed supplier aging/detail, inclusion of approved unpaid expenses, payable-control-account tie-out, visible mismatch errors, soft-deleted supplier handling, bounded queries, and streamed CSV exports.
7. **Filament integration and verification**: fill exactly the three reserved navigation slots, enforce action visibility and policies, add English labels, run focused tests and architecture tests, then run Pint/PHPStan and the unchanged 017/018 suites.

## Key Acceptance Tests

- Creating a PO still creates only the PO and lines; no bill, payable, payment, or journal entry is created.
- Initiating a receipt from a receivable PO creates an Inventory Operation with `source_document_type = PurchaseOrder::class`; completing it advances PO received quantities and stock atomically.
- Creating a bill against a PO line displays ordered, cumulative received, and cumulative billed quantities and flags variances without blocking approval.
- Approved bill/expense postings and later payment postings are balanced, source-linked, closed-period protected, and atomic.
- Supplier payments cannot over-allocate a payment or bill, including concurrent allocations.
- Accounts Payable equals the payable control account or displays the exact unadjusted difference as an error.
- Architecture tests prove no Accounting reference is added to Purchasing and no Purchasing surface exposes billed/payable data.

## Complexity Tracking

No unjustified complexity is proposed. The five-table model is required by the approved feature contract; the separate supplier-payment table is required because customer `payments` has a non-nullable customer relationship and cannot safely represent outbound money.
