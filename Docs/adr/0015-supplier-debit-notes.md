# ADR 0015: Activate Supplier Debit Notes for the Commercial Leg of a Supplier Return (WP-4.8)

**Status**: Accepted

**Date**: 2026-09-12

**Deciders**: Project Owner

**Related**: `PHASE_4_PLAN.md` §1 WP-4.8, `ERP_REMEDIATION_PLAN.md` GAP-MW-14, ADR 0006 (Purchasing dashboard, §11), WP-1.3 (customer credit notes, the direct template), `app/Services/Purchasing/SupplierDebitNoteService.php`, `app/Models/SupplierDebitNote.php`, `tests/Feature/Purchasing/SupplierDebitNoteServiceTest.php`

## Context

ADR 0006 deferred the commercial leg of a supplier return: the physical leg
was built and well guarded —
`InventoryReturnService::createSupplierReturn()` already capped a return
against its referenced receipt line — but nothing existed to record that
the supplier owed the business anything for it. `PHASE_4_PLAN.md` recorded
this as GAP-MW-14: stock leaves the building and the payable stays at its
full value; the supplier's bill is paid in full for goods that were
returned, or the recovery is tracked in email outside the system entirely.
F-08 completed its inventory half and stopped at the Purchasing → Accounting
seam.

WP-1.3 (customer credit notes) had already solved the identical problem in
the opposite direction — the same shape, the other direction — which is why
the plan judged this the second-cheapest deferred item to activate once its
template shipped.

## Decision

Activate `App\Services\Purchasing\SupplierDebitNoteService`, mirroring
`CreditNote` in shape and lifecycle, with one additional constraint
`CreditNote` doesn't need: **a debit note line may not be keyed
independently of its provenance.** Every line is derived from the exact
return-line → receipt-line → purchase-order-line → bill-line chain; there
is no manual amount entry anywhere in the creation path.

### In scope, and what was actually built

- **`SupplierReturnExpectedOutcome`** (`Replacement | Credit | Refund`) on
  the supplier return, settable only while the return is still draft
  (`setExpectedOutcome()`) — a return expecting `Replacement` never becomes
  eligible for a debit note; only `Credit` and `Refund` do
  (`requiresFinancialCredit()`).
- **`SupplierDebitNote` and `SupplierDebitNoteLine`**, created from a posted
  supplier return and the specific supplier bill it credits
  (`createForReturn()`). Creation refuses unless: the return is a posted
  supplier return with a credit-requiring expected outcome; the return and
  bill share the same supplier; the return's originating purchase order (if
  any) matches the bill's; and the bill is approved, partially paid, or
  paid — never a draft. Each return line's quantity is traced back through
  `originalOperationLine` to its purchase-order line, matched against the
  corresponding bill line, and capped so a debit note can never claim more
  than the bill actually charged for that line.
- **One debit note per return** — a second `createForReturn()` call against
  an already-credited return is refused, not silently duplicated.
- **Confirmation posts the commercial reversal** (`confirm()`): `Dr Accounts
  Payable / Cr GRNI` for the subtotal, plus `Cr Recoverable Input Tax` for
  the tax portion when present, capped so the bill's cumulative supplier
  credit can never exceed its original grand total. A `TaxRecognitionEntry`
  reverses the input tax proportionally, mirroring how output tax is
  recognized on the sales side.
- **Reversal** (`reverse()`): only a confirmed note can be reversed; it
  reverses the original journal entry through the standard
  `JournalPostingService::reverse()` path, records the tax reversal's own
  reversal, and restores the bill's `supplier_credit_total`.
- **An exception report** (`awaitingSupplierCreditQuery()`): posted supplier
  returns expecting `Credit` or `Refund` with no confirmed debit note yet —
  the "recovery tracked in email" failure mode the plan named, now a
  queryable, systemic view instead of a manual tracking spreadsheet.
- **Authorization** via `SupplierDebitNotePolicy`, gating view, create,
  update (draft only), confirm (draft only), and reverse (confirmed only)
  behind their own `AccountingPermission::SupplierDebitNote*` grants, plus
  the existing `SupplierDebitNoteResource` Filament surface
  (list/view pages).

### Out of scope

This decision does **not** authorize:

- **A debit note for a `Replacement`-outcome return.** Replacement stays a
  purely physical transaction with no ledger consequence, by design.
- **Manually keying a debit note amount.** There is no code path in
  `SupplierDebitNoteService` that accepts a caller-supplied subtotal, tax,
  or total — every figure is derived from `deriveLine()`'s traversal of the
  existing bill line. A future feature that needs a debit note independent
  of a specific bill (e.g., a supplier goodwill credit with no return) is
  explicitly out of scope and would need its own decision.
- **Partial reversal.** `reverse()` reverses the whole confirmed note; there
  is no partial-reversal path.

## Consequences

**Positive.** F-08 now completes across the Purchasing → Accounting seam.
A supplier return's financial consequence is systemic and auditable rather
than tracked in email, and the exception report makes a return awaiting a
credit that never arrived a queryable fact instead of tribal knowledge.

**Negative.** Like WP-4.6, `SupplierDebitNoteService` — code posting real
payable and input-tax reversals — had **zero direct test coverage** before
this remediation pass; only architecture tests referenced it. This ADR is
accompanied by `tests/Feature/Purchasing/SupplierDebitNoteServiceTest.php`,
added in this same pass, covering derivation from the full provenance
chain, the no-expected-outcome and over-credit refusals, confirm posting,
reversal, and the awaiting-credit query — but the gap existing at all until
now is worth recording rather than smoothing over.

**Neutral.** A postable GRNI account (`PurchaseSetting`) and postable
Accounts Payable (`2100`) and Recoverable Input Tax (`1450`) chart accounts
must exist before a debit note can be confirmed; an environment that has
never configured purchasing accounting can still create a draft debit note
(the derivation itself needs no chart-account configuration) but cannot
confirm it until those accounts exist.

**Enforcement.** `tests/Feature/Accounting/NoAutomaticPostingTest.php` names
`SupplierDebitNoteService` among its permitted `JournalPostingService`
callers. Per `.ai/feature-development` rule 8, that list may be renamed or
shrunk to reflect an equally-authorized caller; it may not be silently
widened to admit an unaudited posting path.
