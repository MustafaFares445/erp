# Specification Quality Checklist: Accounting Payables — Expenses, Bills, Accounts Payable

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-23
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — *deviation, deliberate; see Notes 1*
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders — *within the limit in Notes 1*
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — 0 present
- [x] Requirements are testable and unambiguous — FR-001..FR-056, contiguous
- [x] Success criteria are measurable — SC-001..SC-016
- [x] Success criteria are technology-agnostic — *one exception, SC-016; see Notes 1*
- [x] All acceptance scenarios are defined — 7 user stories, 43 numbered scenarios
- [x] Edge cases are identified — 14, concentrated on match variances, duplicate invoices, allocation concurrency, and cross-line rounding
- [x] Scope is clearly bounded — §Scope, plus an ERD Divergence Register with 7 entries
- [x] Dependencies and assumptions identified — 9 assumptions, §Dependencies including the one-way Purchasing integration

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows — permissions, expense lifecycle, bill recording with match, bill approval and posting, supplier payment allocation, payable aging with tie-out, export
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification — *as qualified in Notes 1*

## Notes

1. **Code anchors and toolchain references are the house style.** Consistent with specs 017–021 and required by constitution §VI. SC-016 names `composer test` and the PHPStan baseline for the same reason spec 018's SC-007 does.

2. **This is the only remaining accounting specification implementable today.** Both prerequisites — `017-purchasing-orders-suppliers` and `018-chart-of-accounts-journals` — are built and verified present in the working tree. `020` is also unblocked but needs its ADR; `021` is hard-blocked on `019`. If the sales programme is not ready, this is the natural feature to build next.

3. **The governance risk is the highest in the set, and it is the reason to read ADR 0011 carefully.** This feature needs an **amendment to an already-Accepted ADR** (0006), for a boundary the constitution reinforced twice — at 1.6.0 and again at 1.7.0, the latter with the sentence "A ledger that exists is not permission to post to it." The spec's answer is D1: build payables in Accounting, not Purchasing, and take only a *read* reference to purchase orders. That answer satisfies the prohibition's intent rather than negotiating with its wording, but it is the claim the owner should test hardest when reviewing.

4. **FR-052 with SC-006 are the load-bearing boundary requirements.** They forbid any modification to Purchasing and require an architecture test proving the dependency is one-way. The specification names the exact forbidden next change — showing a purchase order's billed amount on the purchase order — because it is both the obvious improvement and the thing ADR 0006 prohibits.

5. **Two accounting decisions are stated as known limitations rather than hidden.** D3 expenses purchased goods instead of capitalising them, so the ledger shows cost at bill date rather than at sale; this is symmetric with `019`'s revenue-without-matched-cost limitation and resolves with it. D4 recognises input tax at bill approval, which is a guess about a jurisdiction `Docs/PRD.md` §12 has not yet named — deliberately isolated to one seeded account and one posting line so revising it is cheap. **D4 is the requirement most likely to be wrong**, and it is flagged as such in both the spec and ADR 0011.

6. **Highest-value control for its cost**: FR-017's unique supplier invoice number per supplier. It is one index and it is the primary defence against paying the same supplier invoice twice. SC-004 tests both halves — refused for the same supplier, permitted across two suppliers.

7. **Posting-caller count.** D2 adds three, bringing the authorised total across ADRs 0008, 0010, and 0011 to seven from zero. The count is tracked deliberately in each ADR because unbounded growth in what may write to the ledger is the main risk ADR 0007's no-automatic-posting rule was guarding against.

8. **Validation ran once.** All items passed on the first pass.
