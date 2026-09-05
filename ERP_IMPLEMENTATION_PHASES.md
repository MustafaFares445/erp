# ERP Implementation Phases — IERP

**Document type:** Delivery plan (sequencing, dependencies, effort, exit criteria)
**Perspective:** Principal Software Architect / Laravel ERP Architect
**Baseline:** `feat/cross-module-remediation` @ `b29a49a`
**Created:** 2026-09-03
**Companion:** `ERP_REMEDIATION_PLAN.md` — the design for each of the 39 work packages

---

## 0. How to read this document

`ERP_REMEDIATION_PLAN.md` says **what** to build and why. This document says **in what order, by
whom, and how you know it is done.**

### 0.1 The four phases and what admits work into each

| Phase | Admission test | Packages |
|---|---|---|
| **1 — Critical business correctness** | Money or stock can be wrong today, **or** a documented control is written and unenforced | 10 |
| **2 — Cross-module integration** | Both sides of a seam are built and nothing joins them; or a capability is missing that spans modules | 14 |
| **3 — UX and workflow completion** | The behaviour exists in the domain and no user can reach it comfortably | 7 |
| **4 — Optimization and deferred scope** | 4A: hardening what Phases 1–3 built. 4B: the four ADR-deferred gaps, which are owner decisions | 8 |

**Phase 1 is not "the important gaps" — it is the gaps where waiting costs something irreversible.**
GAP-MW-01 (CRM) is rated Critical and sits in Phase 2, because a missing capability costs
opportunity while a stranded asset or a double-credited return costs money that is hard to recover.
That distinction is the organising principle of the whole sequence, and it is why WP-2.1 runs as a
parallel track rather than waiting.

### 0.2 Effort notation

Effort is in **engineer-days for one competent engineer familiar with this codebase**, including
tests, Pint, PHPStan and code review — not implementation time alone. Ranges reflect genuine
uncertainty, not padding. A package marked **†** has a data-moving migration and carries the
deployment overhead in §5.

### 0.3 Tracks

Three tracks run concurrently. A track is a person or a pair, not a team.

| Track | Owns | Rationale |
|---|---|---|
| **A — Core** | Phase 1, then the Phase 2 accounting and inventory packages | The money-and-stock spine; needs the deepest familiarity with the existing invariants |
| **B — CRM** | WP-2.1, WP-2.2, then WP-3.1 | A new module with no Phase 1 dependency. Starts on day one |
| **C — Platform** | WP-2.10 (notifications), WP-2.8 (sales reporting), WP-4.3 | Infrastructure and reporting; consumed by A and B but blocking neither |

---

## 1. Dependency graph

```
CC-01 ─┐
CC-02 ─┼──► (all of Phase 1)
CC-03 ─┤
CC-04 ─┘

TRACK A ─────────────────────────────────────────────────────────────────────
  WP-1.1 quarantine ──┬──► WP-1.2 multi-condition adjustments ──┐
                      │                                          │
                      └──────────────────────► WP-3.3 condition docs
                                                                 │
  WP-1.4 maker/checker ─────────────────────────────────────────┼──► WP-3.5 count
                                                                 │
  WP-1.3 return↔credit ──────────► WP-2.11 correction types      │
                                                                 │
  WP-1.5 duplicate bill        (independent)                     │
  WP-1.6 reservations ─────────► WP-2.14 quotation expiry ──► WP-3.2 availability
  WP-1.7 reconcile+persist ────► WP-2.4 recon report ──┐
  WP-1.8 status enums ─┬──► WP-1.9 write-off ──► WP-2.6 AR subledger ──┤
                       └──► WP-2.13 consolidated invoicing             ├──► WP-2.5 CLOSE GATE
  WP-1.10 transcription FK ──► WP-2.2 opportunity      WP-2.7 tax register ──┘
                                                       WP-2.12 operation audit (independent)
                                                       WP-2.3 provenance ──► WP-2.8
                                                       WP-2.9 service cost ──► WP-3.6 preventive

TRACK B ─────────────────────────────────────────────────────────────────────
  WP-2.1 CRM foundation ──┬──► WP-2.2 opportunity (joins Track A)
                          └──► WP-3.1 customer 360

TRACK C ─────────────────────────────────────────────────────────────────────
  WP-2.10 notifications ──┬──► WP-2.14, WP-3.6 (reminders)
                          └──► WP-2.1 campaign send (Track B consumes)
  WP-2.8 sales reporting  ◄── needs WP-2.3, WP-2.2, WP-1.3
  WP-3.4, WP-3.7 hygiene  (fillers, any time)
```

**The critical path runs through WP-2.5 (the period-close gate)**, which cannot be built before
WP-2.4, WP-2.6 and WP-2.7 exist, which in turn need WP-1.7, WP-1.8 and WP-1.9. That chain — nine
packages — is the longest in the plan and determines Phase 2's end date. Everything else has slack.

---

## 2. Phase 1 — Critical business correctness

**Goal:** no routine business action can strand an asset, double-credit a return, or bypass a
control that the documentation claims exists.

**Duration:** 6–8 weeks on Track A, with Tracks B and C running WP-2.1 and WP-2.10 in parallel.

### 2.1 Work packages

| WP | Gap(s) | Priority | Effort | Depends on | Notes |
|---|---|---|---|---|---|
| CC-01…CC-04 | — | — | 3–4 d | — | Do first; every package below assumes them |
| **WP-1.1** Quarantine disposition | MW-03 | **Critical** | 8–10 d | CC-02 | New document family (AD-01), narrow |
| **WP-1.2** Multi-condition adjustments | WL-03 | High | 4–5 d | WP-1.1 | Widens an existing service |
| **WP-1.3** Return ↔ credit note | BW-01 | **Critical** | 7–9 d | CC-02 | Line-grain link + cap + concurrency |
| **WP-1.4** Adjustment maker/checker | WL-02 | High | 2–3 d | CC-03 | Smallest high-value control in the plan |
| **WP-1.5 †** Duplicate bill control | BW-05 | High | 4–5 d | CC-02 | Data-dependent migration; see §5 |
| **WP-1.6** Reservation lifecycle | MW-04, MW-05, UI-01 | High ×3 | 6–7 d | — | Activates code that is already written and tested |
| **WP-1.7** Reconcile: schedule + persist | BW-04, MW-16 (part) | High | 4–5 d | AD-04 | Two scheduler lines and a table |
| **WP-1.8 †** Document status enums | WL-01, WL-04 | High + Med | 9–12 d | CC-01 | Large mechanical diff; the invoice backfill is the risk |
| **WP-1.9** Bad-debt write-off | MW-07 | High | 7–8 d | WP-1.8, CC-03 | The one new posting caller |
| **WP-1.10 †** Transcription FK | BW-08 | Medium | 2–3 d | — | One-line constraint change; sequenced here because retention jobs do not wait |

**Track A total: 56–71 engineer-days.**

### 2.2 Recommended order within Track A

The order below is not the dependency order — it is the **risk-retirement** order.

1. **CC-01…CC-04** — nothing else is reviewable without them.
2. **WP-1.10** — two days, removes an irreversible-loss risk that is live every day it waits.
3. **WP-1.4** — two days, closes a control gap with no schema change. An early, visible win that
   proves the maker/checker extraction is safe before WP-1.9 depends on it.
4. **WP-1.7** — the reconciliation must be running and passing *before* Phase 1 starts changing
   inventory, so a divergence introduced by WP-1.1 or WP-1.2 is caught by a signal that was already
   green. **Do not reorder this one.**
5. **WP-1.1** then **WP-1.2** — the Critical quarantine fix, then the condition widening that
   completes it.
6. **WP-1.6** — independent, and the cheapest large win in the phase.
7. **WP-1.3** — the second Critical fix. Sequenced after the inventory work because its integration
   test exercises returns end to end.
8. **WP-1.5** — needs a production data check first (§5), so start the check while WP-1.3 is in
   review.
9. **WP-1.8** — the largest diff. Sequenced late so it is not competing for review attention with
   the Critical fixes, and so the suite it must leave unchanged has already been extended.
10. **WP-1.9** — last, because it depends on WP-1.8's `InvoiceStatus`.

### 2.3 Exit criteria

Phase 1 is complete when **all** of the following hold. These are checks, not opinions.

1. `composer test` passes; no test was skipped, weakened or deleted to achieve it.
2. `vendor/bin/phpstan analyse` passes and `phpstan-baseline.neon` is **smaller** than at `b29a49a`.
3. Coverage thresholds in `Feature/Filament/CoverageSurfaceTest.php` and
   `Unit/Coverage/PrimitiveCoverageTest.php` are equal or higher.
4. `inventory:lots:reconcile` and `inventory:reservations:expire` are scheduled and asserted by
   `tests/Feature/ScheduledCommandsTest.php`, and the reconciliation has run clean for seven
   consecutive days in staging.
5. Quarantined stock can be released, downgraded, disposed and returned to supplier through the UI,
   and `InventoryLotReconciliationService::inspect()` passes after each.
6. A returned line cannot be credited twice, proven by the concurrency test.
7. No user can create and confirm the same stock adjustment.
8. No bill can be recorded without a supplier reference, and no two bills share one per supplier —
   enforced by the database, not only the service.
9. Every status on the six money-carrying document families is a cast enum with an exhaustive
   transition test, and `invoices.status` no longer holds a confirmation type.
10. An uncollectable receivable can be written off, and the trial balance still balances.
11. `Feature/Accounting/NoAutomaticPostingTest.php` names exactly seven posting callers and asserts
    the count.
12. The four data-moving migrations (WP-1.5, 1.8, 1.10 and none other) have run in staging against a
    production-shaped dataset with their reports reviewed.

### 2.4 What Phase 1 explicitly does not deliver

No new reports, no notifications, no CRM, no API. A stakeholder expecting visible new capability
from Phase 1 should be told plainly: **this phase is about the system being right, not about it
doing more.** The visible output is a set of controls that now refuse things they used to allow.

---

## 3. Phase 2 — Cross-module integration

**Goal:** every seam either carries its evidence or is a recorded, deliberate deferral.

**Duration:** 12–16 weeks across three tracks.

### 3.1 Work packages

| WP | Gap(s) | Priority | Effort | Track | Depends on |
|---|---|---|---|---|---|
| **WP-2.1** CRM foundation | MW-01 | **Critical** | 25–32 d | B | — (starts day 1) |
| **WP-2.10** Notification engine | MW-12 | High | 14–18 d | C | — (starts day 1) |
| **WP-2.2** Opportunity first-class | MW-02 | High | 8–10 d | B→A | WP-1.10, WP-2.1 |
| **WP-2.3** Price provenance | BW-03 | High | 6–8 d | A | AD-06 |
| **WP-2.4** Reconciliation report | MW-16 | High | 4–5 d | A | WP-1.7 |
| **WP-2.6** AR subledger | UI-02 | High | 9–11 d | A | WP-1.9 |
| **WP-2.7** Tax register | UI-04 | High | 7–9 d | A | — |
| **WP-2.5** Period-close gate | MW-18 | High | 8–10 d | A | WP-2.4, WP-2.6, WP-2.7 |
| **WP-2.8** Sales reporting + exports | MW-17, UI-05 | High + Med | 12–15 d | C | WP-2.2, WP-2.3, WP-1.3 |
| **WP-2.9** Service cost + billing | MW-09, MW-10 | High ×2 | 14–17 d | A | WP-2.3 |
| **WP-2.11** Correction types | BW-02 | High | 7–9 d | A | WP-1.3 |
| **WP-2.12** Operation audit | BW-06 | Medium | 3–4 d | A | — |
| **WP-2.13 †** Consolidated invoicing | MW-13 | Medium | 6–8 d | A | WP-1.8 |
| **WP-2.14** Quotation expiry sweep | BW-07 | Medium | 4–5 d | A | WP-1.6, WP-2.10 |

**Totals: Track A 68–86 d · Track B 33–42 d · Track C 26–33 d — Phase 2 total 127–161 d.**

### 3.2 Sequencing notes

- **WP-2.1 and WP-2.10 start on day one of Phase 1**, not day one of Phase 2. Both are large,
  neither depends on Phase 1, and both are consumed by later Phase 2 work. Starting them late is
  the single easiest way to make Phase 2 slip (R-05).
- **WP-2.5 is the critical path.** Protect the sequence WP-2.4 → WP-2.6 → WP-2.7 → WP-2.5; if
  Track A must drop something, drop WP-2.12 or WP-2.13, never one of those four.
- **WP-2.5 ships in report-only mode first.** Deploy the checklist, let it run for one full period,
  work the exceptions, and enable the gate at the start of the following period. Enabling a close
  gate against an unknown exception list is the highest-impact operational risk in this plan
  (R-04), and this is the entire mitigation.
- **WP-2.9 can move to Phase 3** if service volume is low. It is High priority on business impact,
  but it does not block the close gate and nothing depends on it except WP-3.6.
- **WP-2.12 is a good filler** — three or four days, no dependencies, useful the day it lands.

### 3.3 Exit criteria

1. All Phase 1 exit criteria still hold.
2. A lead can be captured with a mandatory source, worked through recorded interactions, converted
   to a customer carrying its history, and its campaign and source traced through to **collected**
   revenue (the CR-07 test).
3. An opportunity can be created without an AI transcript, carries value, stage and expected close,
   and closes won or lost with a controlled reason.
4. Price provenance survives quotation → order → invoice, and a direct order carries it too.
5. A notification's every delivery attempt and outcome is logged; a failure is retried and visible;
   no `App\Services` class references the mailer (asserted by `ArchTest`).
6. Sales has a report surface covering both named leak points, and
   `InvoicedNotCollected` agrees with `AccountsReceivableService::aging()` figure-for-figure.
7. AR ages, exports, produces a per-customer statement, and displays its reconciliation to the
   control account — showing a difference as an error, never plugging it.
8. The tax register proves a period against both tax accounts.
9. **A fiscal period cannot be closed while a mandatory reconciliation fails**, except by a
   separately-permissioned, reasoned, audited override.
10. A maintenance job reports parts, labour and third-party cost; a warranty job shows real cost at
    zero revenue; a chargeable job invoices through the **standard** sales path with the standard
    tax recognition.
11. A wrongly posted delivery or transfer has a linked correction document.
12. Marking ready, dispatching, completing and receiving a transfer each write an audit entry, and
    a rolled-back completion writes none.
13. `CROSS_MODULE_FLOW_MATRIX.md` is regenerated and shows no impaired seam outside the four
    ADR-deferred ones.

---

## 4. Phase 3 — UX and workflow completion

**Goal:** everything the domain can do, a user can reach — and every remaining impaired seam is a
decision, not an accident.

**Duration:** 8–10 weeks, two tracks.

| WP | Gap(s) | Priority | Effort | Depends on |
|---|---|---|---|---|
| **WP-3.1** Customer 360 | UI-03 | High | 9–11 d | WP-2.1, WP-2.6 |
| **WP-3.2** Availability drill-through | WL-05 | Medium | 6–7 d | WP-1.1, WP-1.6, WP-3.3 |
| **WP-3.3** Condition-change documents | UI-06 | Medium | 8–10 d | WP-1.1 |
| **WP-3.4** Directory hygiene | UI-08 | Low | 1 d | — |
| **WP-3.5** Physical count document | MW-06 | Medium | 12–15 d | WP-1.2, WP-1.4 |
| **WP-3.6** Preventive maintenance | MW-08 | Medium | 11–14 d | WP-2.9, WP-2.10 |
| **WP-3.7** Placeholder navigation | UI-07 | Medium | 5–6 d | WP-2.7 |

**Total: 52–64 engineer-days.**

### 4.1 Sequencing notes

- **WP-3.3 before WP-3.2** — the availability explainer needs condition-change documents to link to,
  or its quarantine and damaged causes point at nothing.
- **WP-3.4 is a one-day filler.** Slot it into any gap; its lasting value is the directory
  architecture test, not the deletion.
- **WP-3.5 is the largest package in this phase** and is the one most likely to be deferred by a
  business that is not yet doing controlled cycle counts. If it is deferred, say so explicitly —
  GAP-MW-06 then becomes a fifth recorded deferral, and `BUSINESS_LOGIC_GAPS.md` should say so.

### 4.2 Exit criteria

1. All Phase 1 and 2 exit criteria still hold.
2. A customer's whole relationship — quotations, orders, invoices, payments, tickets, visits,
   maintenance, interactions — is visible on one paginated, permission-filtered timeline.
3. The gap between on-hand and available is **fully attributed with no residual**, each cause naming
   its documents and offering its resolving action.
4. Damage, recovery and disposal are documents; a recovery references the damage it reverses; a
   disposal carries authorisation and evidence.
5. A physical count can be opened for a scope, counted at lot and serial identity across every
   condition, reviewed as a variance worksheet, and confirmed by a different person — with uncounted
   grains visible rather than assumed correct.
6. Preventive schedules raise requests in advance, and **a missed service is a row, not an absence**.
7. No navigation entry resolves to `ModulePlaceholder`, asserted by the inverted registry test.
8. Every directory under `app/Filament/Resources/` contains exactly one resource class.

---

## 5. Deployment runbook for the four data-moving migrations

Four packages move data. Each ships in **its own release**, with no other schema change alongside,
because a failed data migration bundled with behaviour changes is far harder to reason about.

### 5.1 WP-1.5 — supplier reference uniqueness †

**Pre-flight, run against a production replica, days before the release:**

```sql
SELECT supplier_id, supplier_reference, COUNT(*) c, GROUP_CONCAT(bill_number)
FROM bills WHERE supplier_reference IS NOT NULL AND supplier_reference <> ''
GROUP BY supplier_id, supplier_reference HAVING c > 1;

SELECT COUNT(*) FROM bills WHERE supplier_reference IS NULL OR supplier_reference = '';
```

- **Duplicates must be resolved as a business task before the release**, by accounting, not by
  engineering — each pair is either a genuine duplicate bill (which may mean a duplicate payment was
  already made, and that is a finding in itself) or a data-entry error. The migration throws rather
  than choosing.
- Blank references are back-filled to `LEGACY-{bill_number}` and stamped, so they are visibly
  placeholders.
- **Rollback:** drop the unique index. The back-filled values remain and are identifiable by
  `supplier_reference_backfilled_at`.

### 5.2 WP-1.8 — document status enums †

**Pre-flight:**

```sql
SELECT DISTINCT status FROM invoices;   -- repeat for payments, bills, expenses,
                                        -- supplier_payments, refunds
SELECT i.id, i.invoice_number, COUNT(DISTINCT c.confirmation_type) t
FROM invoices i JOIN invoice_confirmations c ON c.invoice_id = i.id
GROUP BY i.id HAVING t > 1;             -- the both-types divergence set
```

- Any status value outside the enum is a **release blocker**, resolved before deploying.
- The both-types set is reviewed by accounting after the migration reports it.
- **Deploy in two steps:** first the additive migration (new invoice receipt columns) plus the
  backfill, verified in production; then, in a following release, the casts. Splitting them means a
  cast problem cannot corrupt the backfill.
- **Rollback:** `down()` is implemented and tested for the receipt-column migration. The cast
  migration is code-only and rolls back with the deploy.

### 5.3 WP-1.10 — transcription foreign key †

- Low risk: nullability and `nullOnDelete`, plus an `origin_summary` backfill.
- The dangling-quotation sweep **reports** and repairs nothing.
- **Rollback:** `down()` restores nullability only and deliberately does **not** reinstate
  `cascadeOnDelete` — restoring a data-destroying constraint on rollback would be a defect.

### 5.4 WP-2.13 — invoice delivery links †

- Backfill asserts, inside the same migration, that the new row count equals the pre-migration count
  of invoices carrying an `inventory_operation_id`. A mismatch aborts the transaction.
- `invoices.inventory_operation_id` is **retained** and kept in sync for single-delivery invoices
  until WP-4.2 removes it, so rollback is a code revert.

### 5.5 Rules that apply to all four

1. Full backup verified restorable before the release, not merely taken.
2. Run in a transaction where the driver supports transactional DDL; where it does not, the
   pre-flight report is the safety net.
3. Deploy outside a fiscal-period close window.
4. The migration's report output is captured and attached to the release record.
5. A named person from accounting is available during the WP-1.5 and WP-1.8 releases.

---

## 6. Effort summary and shape of the delivery

| Phase | Packages | Engineer-days | Elapsed (3 tracks) |
|---|---|---|---|
| Cross-cutting (CC-01…04) | — | *(included in Phase 1)* | — |
| **Phase 1** | 10 | 56–71 | 6–8 weeks |
| **Phase 2** | 14 | 127–161 | 12–16 weeks |
| **Phase 3** | 7 | 52–64 | 8–10 weeks |
| **Phase 4A** (optimization) | 4 | 30–40 | 4–6 weeks |
| **Subtotal — Phases 1–4A** | **35** | **265–336** | **30–40 weeks** |
| **Phase 4B** (ADR-deferred, if funded) | 4 | 150–210 | not scheduled |

**Read the totals with three caveats.**

1. **Elapsed time assumes three tracks running concurrently.** On a single engineer the elapsed
   time is roughly the engineer-day total, and the parallel-track structure — the main protection
   against WP-2.1 and WP-2.10 blocking Phase 2 — disappears. If only one engineer is available,
   re-sequence so WP-2.1 and WP-2.10 are the *last* Phase 2 packages, not the first.
2. **Phase 4B is a range across four independent decisions**, not one project. WP-4.7 (ticket
   revenue) is roughly 10–15 days because WP-2.9 builds its template. WP-4.6 (COGS) is the bulk of
   the range at 60–90 days and touches the ledger's most load-bearing invariant.
3. **Review and rework are inside the estimates; production incident response is not.**

---

## 7. Decision points for the owner

Four decisions belong to the business, not to engineering. Each is stated with the input that
decides it, so it can be answered rather than discussed.

| # | Decision | Deciding input | Consequence of "no" |
|---|---|---|---|
| **D-1** | Fund **WP-4.6** (inventory valuation and COGS)? | Does the business need gross margin, or is revenue-only reporting acceptable to its owners, lenders and auditors? | The P&L reports revenue with no cost of sales, permanently. No margin figure exists at any grain. The balance sheet omits inventory as an asset |
| **D-2** | Fund **WP-4.5** (customer and employee channels)? | Volume of re-keyed customer and field activity; whether the salary model's work-time adherence factor may keep being fed by office-entered timestamps | All self-service and field capture stays re-keyed. GPS check-in cannot be captured at all |
| **D-3** | Pull **WP-4.7** (ticket revenue) into Phase 2? | **Annual value of ticket settlements.** This is a tax-recognition exposure, not a reporting gap | Tax is charged and collected without being recognised as payable. If the volume is material, "no" is not a reporting decision — it is a filing risk |
| **D-4** | Defer **WP-3.5** (physical count)? | Is the business running controlled cycle counts today, or counting on spreadsheets? | Counts stay a hand-built adjustment list, and uncounted variants stay silently assumed correct |

**D-3 is the one to answer first.** It is the cheapest of the four to build, the template already
exists after WP-2.9, and it is the only one whose "no" carries a compliance consequence rather than
a capability cost.

---

## 8. Governance during delivery

1. **One logical change per pull request** (`.ai/feature-development` rule 3). A mechanical refactor
   never rides along with a behaviour change — WP-1.8's mechanical diff is large enough that it is
   split by document family across six pull requests.
2. **`composer test` is the gate**; no threshold is lowered and no test is skipped to pass it
   (rule 8). The prior session's finding stands: after a passing parallel `composer test`, do not
   re-run the suite single-worker.
3. **The PHPStan baseline may only shrink** (rule 7). Every package removes the entries it
   obsoletes in the files it touches.
4. **Each phase ends by regenerating the three analysis documents** —
   `CURRENT_IMPLEMENTATION_MAP.md`, `BUSINESS_LOGIC_GAPS.md`, `CROSS_MODULE_FLOW_MATRIX.md` — from
   the code, not by hand-editing them. A gap document that is maintained by hand becomes fiction
   within two sprints, and these three are the only evidence that the plan is working.
5. **A new ADR is written for each Phase 4B decision**, whether the answer is yes or no. A recorded
   "no, and here is why" is worth as much as a "yes", and it is what stops the same discussion
   recurring in six months.
6. **Deferred work is deferred visibly.** If a package is dropped, it returns to
   `BUSINESS_LOGIC_GAPS.md` with its business consequence intact. Nothing leaves this plan by
   quietly falling off a board.

---

*Companion document:* `ERP_REMEDIATION_PLAN.md` — per-package database, domain, API, Filament,
mobile and test design.

*End of document.*
