# Agent Workflow

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: current repository structure, agent guidance, quality tooling
---

## Purpose

This guide defines how a new coding agent should enter the IERP repository, discover the correct context, make changes safely, and keep documentation synchronized.

It supplements the tool-discoverable root `AGENTS.md` and `CLAUDE.md`. Package-generated Laravel Boost instructions remain in those files; shared project workflow belongs here.

## Before Changing Code

### 1. Read the project entry points

Read:

1. Root `README.md`.
2. `Docs/README.md`.
3. `Docs/DOCUMENTATION_AUTHORITY_MAP.md` while the documentation migration is active.

### 2. Identify the owning domain

Do not start from a controller/resource name alone.

Identify which business domain owns the rule being changed, for example:

- Inventory owns stock truth and stock-changing operations.
- Purchasing owns purchase-order commercial workflow but uses Inventory for receipts/stock effects.
- Accounting owns ledger/posting/reporting rules.
- Payments owns payment/allocation/deposit/refund behavior.
- Support owns ticket, SLA, warranty, maintenance, and service-record workflows.
- Sales owns quotation/order/delivery/invoice commercial lifecycle.
- Logistics owns outbound fulfillment/shipment/package execution.

When ownership is unclear, inspect sibling services and cross-module tests before editing.

### 3. Read domain context

When canonical domain docs exist, read in this order:

1. Domain `README.md`.
2. `BUSINESS_RULES.md`.
3. `WORKFLOWS.md`.
4. Relevant `DATA_MODEL.md`, `UI.md`, `API.md`, or `TESTING.md`.
5. Linked ADRs.

If canonical domain docs have not yet been migrated, use the authority map to locate the temporary source and verify it against code/tests.

### 4. Inspect implementation before proposing changes

At minimum inspect:

- Relevant domain service/action.
- Models and migrations involved.
- Filament resource/page/action when UI is involved.
- Policies/permissions.
- Jobs/events/listeners if side effects are asynchronous.
- Existing tests for the behavior.
- Related cross-module services when money, stock, accounting, support billing, purchasing, returns, or refunds are involved.

Do not create a parallel service or alternate business path without first proving the existing path cannot support the change.

## Documentation Authority

For implemented behavior:

1. Current code and executable tests.
2. Accepted non-superseded ADRs.
3. Canonical domain docs.
4. Canonical product/architecture/reference/operations docs.

For unfinished behavior:

- An explicitly active plan/spec may define the desired future state.
- Never report planned behavior as already implemented.
- Once the implementation lands, merge durable behavior into canonical docs and retire the plan.

Historical `specs/`, old `Docs/plans/`, prompts, drafts, findings, and obsolete API contracts are not current authority.

## Change Workflow

### Discover

- Search for existing services, resources, tests, helpers, permissions, enums, and status transitions.
- Read sibling implementations to follow project conventions.
- For Laravel/Filament/Pest APIs, follow the repository's Laravel Boost/version-specific documentation instructions.

### Design

Before editing, identify:

- Owning domain.
- Business invariant.
- Data changes.
- Status transitions.
- Cross-module side effects.
- Authorization impact.
- User-facing UI impact.
- Tests that prove the change.
- Canonical docs that must change.

### Implement

- Keep controllers/resources/pages thin where domain services already exist.
- Reuse existing domain services rather than duplicating calculations.
- Use transactions for workflows that require atomic financial/inventory state.
- Preserve auditability and non-destructive correction rules.
- Follow existing Laravel/Filament patterns and sibling code.

### Verify

Run the smallest focused test set during iteration.

Before considering a behavior change complete, use the repository's configured quality gates. The root guidance/composer scripts are authoritative for exact commands.

Typical checks include:

- Pest feature/unit tests.
- PHPStan/Larastan.
- Pint.
- Type coverage.
- Full code coverage/CI gate when required by the project.

Never lower a quality threshold or remove a test merely to make the build pass.

## Documentation Update Triggers

Update canonical documentation in the same change when implementation alters:

- A business rule/calculation.
- A lifecycle or status transition.
- A domain boundary.
- A permission.
- A cross-module side effect.
- A public/mobile API or payload.
- Configuration/environment requirements.
- Deployment/monitoring behavior.
- A user workflow that developers or agents must understand.

Small internal refactors that do not change observable/domain behavior usually do not require business-doc updates.

## Adding Documentation

Before creating a new Markdown file, ask:

1. Is this durable current knowledge?
2. Does an existing canonical page own it?
3. Is it a temporary active implementation plan?
4. Is it decision history that belongs in an ADR?

Prefer extending the owning canonical page over creating another standalone file.

Do not create:

- “final-v2” duplicate docs.
- Permanent legacy/archive folders.
- One-off implementation summaries that repeat Git history.
- Separate diagram books when the diagram belongs beside a workflow.
- Manual endpoint lists that duplicate generated API documentation.

## Working With Active Plans

Active plans are temporary.

An active plan must clearly state:

- Status.
- Target domain(s).
- Intended future behavior.
- Dependencies.
- Acceptance criteria.
- What existing behavior it changes.

After implementation:

1. Reconcile the implementation with tests.
2. Update canonical domain docs.
3. Update/append ADRs if an architectural decision changed.
4. Remove the completed plan from the working tree.
5. Let Git history preserve implementation chronology.

## Working With ADRs

Do not rewrite old ADRs to pretend the original decision was different.

If a decision changes:

- Add a new ADR, or
- Explicitly amend/supersede the existing ADR according to project governance.

Canonical docs should state the resulting current behavior without forcing readers to reconstruct a chain of exceptions.

## Mobile Design Work

Current editable design artifacts are:

- `design/customer-app-v1.pen`
- `design/employee-sales-app-v1.pen`

Use canonical app docs for business/navigation/backend behavior and the Pen files for visual implementation details.

Do not infer implemented APIs solely from a screen or design flow.

## Protecting In-Progress Work

Before any cleanup/refactor:

- Check `git status`.
- Do not reset, stash, discard, reformat, or commit unrelated user/agent work.
- Keep documentation-only cleanup changes isolated from active feature/code changes.
- Never delete an active plan/spec just because a newer canonical folder exists; verify completion first.

## Definition of Done for Agent Work

A change is complete when:

- The intended behavior is implemented.
- Relevant tests prove it.
- Quality gates pass at the required scope.
- Permissions/status/side effects are correct.
- Canonical documentation is updated when required.
- No new duplicate documentation source was introduced.
- No historical plan is being presented as runtime truth.
