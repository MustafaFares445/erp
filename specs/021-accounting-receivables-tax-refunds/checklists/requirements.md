# Specification Quality Checklist: Accounting Subledgers — Receivables, Tax, Refunds

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
- [x] Requirements are testable and unambiguous — FR-001..FR-052, contiguous
- [x] Success criteria are measurable — SC-001..SC-016
- [x] Success criteria are technology-agnostic — *one exception, SC-016; see Notes 1*
- [x] All acceptance scenarios are defined — 7 user stories, 47 numbered scenarios
- [x] Edge cases are identified — 13, concentrated on rounding, concurrency, and credit-balance boundaries
- [x] Scope is clearly bounded — §Scope, plus an ERD Divergence Register with 5 entries
- [x] Dependencies and assumptions identified — 8 assumptions, §Dependencies separating built from unbuilt prerequisites

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows — permissions, aging, drill-down, tax register, refund lifecycle, refund posting, export
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification — *as qualified in Notes 1*

## Notes

1. **Code anchors and toolchain references are the house style.** Consistent with specs 017, 018, 019, and 020, and required by constitution §VI, which makes architecture enforceable through code, tests, and static analysis rather than documentation. SC-016 names `composer test` and the PHPStan baseline for the same reason spec 018's SC-007 does.

2. **This is the only accounting specification with a hard ordering constraint.** §Governance Gate item 1 blocks it on `019-sales-lifecycle-payments-credits` being *implemented*, not merely specified. Every figure in the feature reads a table `019` creates. `020` and `022` have no such constraint and can proceed in parallel.

3. **The highest-risk requirement is FR-034** — proportional tax un-recognition on integer minor units with no residual. A full refund must un-recognise exactly what its collection recognised. If it leaves one minor unit behind, the tax tie-out in FR-021 correctly reports a failure whose cause is *this feature*, not the postings it is checking. SC-010 and SC-011 are the tests that matter most, and the planning phase should treat them as the feature's primary correctness gate.

4. **The second-highest risk is D2's separate `refunds` table.** The cheaper design — a negative `payments` row — would silently corrupt `019`'s proportional tax recognition, and no test in `019` would catch it. The decision record states this explicitly so a later reviewer does not "simplify" it back.

5. **Three decisions worth the owner's attention.** D3 authorises a *fourth* ledger-posting caller, which ADR 0010 must name explicitly — the count is now tracked deliberately across ADRs 0008, 0010, and 0011 because unbounded posting-caller growth is the main risk the constitution's no-automatic-posting rule was guarding against. D4's separation of duties makes refund approval Chief-Accountant-only. D5 restricts refunds to an available credit balance, without which the surface becomes an unbacked disbursement path.

6. **Validation ran once.** All items passed on the first pass.
