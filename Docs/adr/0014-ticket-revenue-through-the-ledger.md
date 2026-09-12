# ADR 0014: Route Ticket Settlement Through the Standard Invoice and Payment Path (WP-4.7)

**Status**: Accepted

**Date**: 2026-09-12

**Deciders**: Project Owner

**Related**: `PHASE_4_PLAN.md` §1 WP-4.7, `ERP_REMEDIATION_PLAN.md` GAP-MW-11, ADR 0008 (Sales/Payments dashboard), spec 016 D4, `app/Services/Support/TicketPaymentService.php`, `tests/Feature/Support/TicketPaymentTest.php`, `tests/Feature/Accounting/NoAutomaticPostingTest.php`

## Context

`TicketPaymentService`, before this decision, wrote `ticket_payment_links`
and `tickets` and nothing else — its own docblock stated plainly that no
accounting, journal, or tax side effect existed anywhere in the class. Cash
was collected from a customer and the ticket marked settled while the
general ledger, the receivables subledger, and **the tax register** never
saw it.

`PHASE_4_PLAN.md` was explicit that this is not merely a reporting gap:
**"Tax is charged and collected without being recognised as payable — a
filing exposure, not a reporting gap."** The plan's own sequencing note
went further — if ticket settlements happen at any material volume, this
belongs in Phase 2 next to WP-2.9 (maintenance billing), not deferred to
Phase 4, because the deciding input is a compliance exposure the business
owns, not an engineering convenience. This ADR records that the volume
question was resolved in favor of activating the fix now rather than
continuing to carry the exposure.

## Decision

`TicketPaymentService::settle()` now creates a standard `Invoice` through
`InvoiceService`, issues it, and creates and posts a standard `Payment`
allocated to that invoice through `PaymentService` — **exactly the "no
second revenue path" rule WP-2.9 already established for maintenance
billing, applied here to tickets.** `MaintenanceBillingService` was the
template, which is why the plan judged this the cheapest of the four
deferred Phase 4B items to activate.

### In scope, and what was actually built

- **Settlement creates a standalone invoice** (`InvoiceService::createStandalone()`),
  described from the ticket number, with the settlement's gross amount split
  into net/tax components via the sales setting's configured tax percentage
  (`splitInclusiveTax()`), then **issues** it
  (`InvoiceService::issue()`) — the same lifecycle transition every other
  invoice goes through, with no ticket-specific shortcut.
- **Settlement creates and posts a standard payment**
  (`PaymentService::createDraft()` then `PaymentService::post()`), allocated
  in full to the invoice just created, using an internal, proof-free payment
  method resolved either explicitly or — when exactly one qualifying method
  exists — automatically; ambiguity between multiple candidate methods is
  never resolved silently (`resolvePaymentMethod()` throws rather than
  guessing).
- **Revenue, receivables, cash, and proportional tax now all flow through
  the same services every other customer transaction uses.** There is no
  ticket-specific ledger, no ticket-specific tax entry, and no second
  revenue-recognition path for support work to diverge from sales revenue.
- **The whole settlement is one transaction** (`DB::transaction`), row-locked
  on both the payment link and the ticket, so two concurrent settlement
  attempts cannot both succeed.
- **Audit logging** on both success (`support.payment_link.settled`) and the
  rejected-transition path (`support.payment_link.settlement_rejected`),
  preserving the actor, the old/new status pair, and the resulting invoice
  and payment IDs.

### Out of scope

This decision does **not** authorize:

- Any new accounting concept — no ticket-specific chart account, no
  ticket-specific tax rule. Every figure a settled ticket produces is
  indistinguishable, once posted, from an ordinary invoice and payment.
- Partial settlement or installment collection against a chargeable ticket
  — `settle()` remains a single, full-amount transition from `Pending` to
  `Settled`.
- Retroactively re-posting historical `ticket_payment_links` rows created
  before this package shipped. This ADR governs settlement behavior from
  activation forward; a backfill of pre-existing settled links that never
  posted is a separate, explicit decision this ADR does not make.

## Consequences

**Positive.** AC-06 and the AC-10 close now see ticket settlement cash. The
tax register now recognizes tax collected through ticket settlement as
payable at the moment of collection, closing the filing exposure the plan
identified. `tests/Feature/Support/TicketPaymentTest.php` was rewritten in
this remediation pass from asserting the settlement produced **zero** rows
in any accounting-adjacent table to asserting it posts through the standard
invoice and payment path and the ledger is populated — the inverse
assertion, which is the clearest signal that this was a deliberate
architecture change rather than a regression.

**Negative.** A settled ticket now depends on `SalesSetting`, a configured
tax percentage, and at least one active, proof-free, chart-account-linked
payment method existing before settlement can succeed — a support team in
an environment that never configured sales accounting cannot settle a
chargeable ticket until it does. This is the correct failure mode (no
invented posting target) but is a new operational precondition worth
naming.

**Neutral.** `NoAutomaticPostingTest` now names `TicketPaymentService` (via
`InvoiceService`/`PaymentService`, the standard posting path) among its
permitted callers rather than asserting tickets never touch the ledger —
the test file itself documents this as the WP-4.7 posting-through-the-
standard-path decision, not an exemption granted to a special case.

**Enforcement.** The invariant this ADR relies on — that ticket settlement
has no posting path of its own — is enforced by
`tests/Feature/Accounting/NoAutomaticPostingTest.php`'s named-caller list. A
future change that gives `TicketPaymentService` (or any other class) a
second, direct route to `JournalPostingService` fails that test. Per
`.ai/feature-development` rule 8, the named-caller list may be renamed or
shrunk; it may not be silently widened to admit a second ticket-revenue
path.
