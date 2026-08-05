# Governance & Non-Negotiable Principles Checklist: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Purpose**: Validate that the requirements (spec.md, plan.md, contracts/) are complete, clear, and
consistent wherever they touch the constitution's two NON-NEGOTIABLE principles — III (Financial &
Inventory Integrity) and V (AI Isolation & Human Oversight) — and the cross-cutting decisions
(D5, D6, D7, D9) and finding (R-006) most likely to hide a requirements gap. This is a check on the
requirements themselves, not on the eventual implementation.

**Created**: 2026-08-05

**Feature**: [spec.md](../spec.md)

**Depth**: Pre-implementation readiness gate — run before `/speckit-analyze` and `/speckit-implement`.

## Financial Integrity Requirements (Principle III)

- [ ] CHK001 - Is the requirement that every salary/performance mutation runs inside a database
      transaction stated as a testable requirement rather than only as an implementation note?
      [Clarity, Plan §9]
- [ ] CHK002 - Are the requirements for what happens to a salary calculation's prior row on
      recalculation (superseded, not deleted) explicit enough to derive a test from without reading
      the data model? [Completeness, Spec FR-065]
- [ ] CHK003 - Is "never deleted" for salary calculations reconciled with the general soft-delete
      convention used elsewhere in the spec, so a reader cannot conclude one contradicts the other?
      [Consistency, Spec FR-065, Data-model §12]
- [ ] CHK004 - Is the requirement that a null payable base is a validation failure (not a silent
      zero) stated with enough precision to distinguish it from every other validation error in the
      spec? [Clarity, Spec FR-062, Contracts/performance-scoring.md §Salary rules]
- [ ] CHK005 - Are the rounding requirements (compute in `decimal(15,2)`, round once at the end)
      specific enough to be independently verified without reading the service implementation?
      [Measurability, Contracts/performance-scoring.md]
- [ ] CHK006 - Does the spec define what an admin sees or must do when a recalculation is triggered,
      distinct from what happens automatically? [Completeness, Spec FR-065]
- [ ] CHK007 - Is there a requirement covering what happens if salary confirmation fails partway
      (e.g., the confirmation write succeeds but the notification does not), or is this left
      unaddressed? [Gap, Exception Flow]

## AI Isolation & Human Oversight Requirements (Principle V)

- [ ] CHK008 - Is "AI failure MUST NOT block visit completion" translated into a requirement
      specific enough to test (e.g., naming which visit/task/salary states must be provably
      unaffected), rather than only a general isolation statement? [Measurability, Spec FR-054,
      Contracts/voice-note-ai.md]
- [ ] CHK009 - Are the requirements for "no AI output takes effect without a human decision"
      consistent across all three AI-adjacent entities (transcription, keyword-detected
      opportunity drafts, and bonus suggestions), or does one entity's requirement imply a
      different standard of review than another's? [Consistency, Spec FR-054, FR-064]
- [ ] CHK010 - Does the spec state what "recorded decision" must contain (actor, timestamp, at
      minimum) once, so every AI-output-approval requirement can point to the same definition
      instead of restating it inconsistently? [Clarity, Spec FR-054, FR-064]
- [ ] CHK011 - Are the confidence-fallback requirements (D6) unambiguous about which of the three
      states (`ProviderReported`, `DerivedFromLogProb`, `Unavailable`) is the default when the
      provider response is malformed or absent, rather than leaving that case to be inferred?
      [Ambiguity, Spec FR-056, Contracts/voice-note-ai.md]
- [ ] CHK012 - Is "confidence MUST NOT be used to auto-reject or auto-approve anything" testable as
      a standalone requirement, independent of the confidence-display requirements it is adjacent
      to? [Measurability, Contracts/voice-note-ai.md]
- [ ] CHK013 - Does the spec define the maximum retry count and which failure classes are retried,
      with enough precision that "bounded retry" is not left as a vague adjective? [Clarity,
      Research R-003, Contracts/voice-note-ai.md]
- [ ] CHK014 - Are requirements defined for what a reviewer sees when a voice note has failed all
      retries, distinct from what they see while it is still processing? [Coverage, Spec FR-050,
      FR-051]

## Plan Duplication Requirements (D9)

- [ ] CHK015 - Is "one new independent `sales_plans` row" precise enough to rule out every
      alternative reading (e.g., a shared reference, a linked pair) a future implementer might
      otherwise consider reasonable? [Clarity, Spec FR-020, Contracts/plan-lifecycle.md]
- [ ] CHK016 - Are the copied-vs-not-copied field lists (plan-lifecycle.md) consistent with the
      "Not copied" list in spec.md's US3 acceptance scenarios, with no field appearing on both or
      neither? [Consistency, Spec US3, Contracts/plan-lifecycle.md]
- [ ] CHK017 - Is the month-length clamping rule specified with a worked example precise enough to
      be independently reproduced (not just "clamped to the last day"), including which of
      `starts_at`/`due_at` it applies to? [Measurability, Spec US3 Acceptance Scenario 5,
      Research R-004]
- [ ] CHK018 - Does the spec define the exact conflict-detection timing relative to the write (i.e.,
      that the check must run and fail before any row exists), or could a reader infer it is
      acceptable to detect the conflict after a partial write and roll back? [Ambiguity, Spec
      FR-023, US3 Acceptance Scenario 4]
- [ ] CHK019 - Is atomicity for the whole plan-copy operation (plan + all tasks, or nothing) stated
      as an explicit requirement with its own acceptance scenario, rather than only implied by the
      general "no partial save" requirement (FR-082)? [Completeness, Spec FR-082,
      Contracts/plan-lifecycle.md]

## Visit Review Audit Trail Requirements (D7)

- [ ] CHK020 - Is "the audit log is the note's revision history" specific enough to derive what
      exactly `old_values`/`new_values` must contain on the first-ever note creation (when there is
      no prior value), or is that edge case left to inference? [Gap, Spec FR-045, Edge Cases]
- [ ] CHK021 - Are the two abilities `employees.visit.field-edit` and `employees.visit.review`
      described with enough contrast that a reader cannot conflate "can edit the record" with "can
      review it," given both apply to the same locked visit? [Clarity, Contracts/permissions.md]
- [ ] CHK022 - Does the spec define what happens if a `visit.review` holder attempts to edit
      non-review-note fields on a locked visit — an explicit rejection requirement, or is this only
      inferable from the field-edit permission's description? [Gap, Spec FR-044]

## Zero-Denominator Scoring Requirements (D5)

- [ ] CHK023 - Is the zero-denominator rule stated once as a requirement that applies to all four
      scoring factors, or is it at risk of being read as applying only to the factor it happens to
      be documented next to? [Consistency, Contracts/performance-scoring.md]
- [ ] CHK024 - Are the requirements for a visit missing `checked_in_at`/`checked_out_at` (counts in
      denominator, not numerator) distinguished clearly enough from a visit with no `plan_task_id`
      (excluded from both), so the two "missing data" cases cannot be confused with each other?
      [Clarity, Contracts/performance-scoring.md]
- [ ] CHK025 - Is "never redistribute a zero-denominator factor's weight to the other factors"
      testable independently of the weight-sum-to-100 requirement (D4), or does verifying one
      require assuming the other? [Measurability, Contracts/performance-scoring.md]
- [ ] CHK026 - Does the spec require that the *effective* `required_visit_minutes` used in a
      historical calculation be retrievable from that calculation alone, distinct from the
      requirement that it is merely "recorded"? [Completeness, Spec FR-061,
      Contracts/performance-scoring.md]

## Cross-Module Permission Boundary Requirements (R-006)

- [ ] CHK027 - Is the requirement that adding `Employee Manager`/`Payroll Officer` must not widen
      CRM or Inventory access captured anywhere in spec.md itself, or does it exist only in
      research.md — and if only there, should spec.md's Assumptions or Success Criteria reference
      it so the requirement is not lost to a reader who skips research.md? [Gap, Spec vs.
      Research R-006]
- [ ] CHK028 - Is "no cross-module leak" phrased as an objectively verifiable acceptance criterion
      (naming the specific roles and the specific modules), rather than a general non-goal?
      [Measurability, Contracts/permissions.md]

## Traceability & Decision Coverage

- [ ] CHK029 - Does every one of the ten settled decisions (D1–D10) have at least one corresponding
      functional requirement ID in spec.md, so a reader can confirm none were dropped between
      plan.md and spec.md? [Traceability, Spec §Functional Requirements, Plan §14]
- [ ] CHK030 - Are FR-008 (transition enforcement) and the eight transition tables in
      plan-lifecycle.md cross-referenced in both directions, so a change to one is unlikely to be
      made without the other being noticed? [Consistency, Spec FR-008, Contracts/plan-lifecycle.md]
- [ ] CHK031 - Is there a single explicit requirement establishing that `EmployeePermission`,
      `AuditLogger`, and Spatie Media Library are the *only* permitted mechanisms for authorization,
      audit, and file storage respectively — or could a reader infer a feature-specific alternative
      is permissible somewhere in the spec? [Ambiguity, Spec Assumptions and Dependencies]
- [ ] CHK032 - Are the "Not authorised" boundaries from ADR 0003 (employee API, mobile app,
      attendance module) repeated consistently in spec.md's Scope section, plan.md §19, and the
      constitution amendment, with no wording drift that could be read as narrowing or widening the
      boundary in any one place? [Consistency, Spec Scope, Plan §19, Constitution Product Scope]

## Notes

- This checklist intentionally does not duplicate `checklists/requirements.md` (the spec-quality
  checklist generated during `/speckit-specify`), which already validates general completeness,
  testability, and scope boundaries. This checklist is scoped to the two non-negotiable
  constitution principles and the decisions judged highest-risk for a silent requirements gap.
- Items resolved by this checklist should be corrected in spec.md/plan.md/contracts directly, not
  answered only in this file.
