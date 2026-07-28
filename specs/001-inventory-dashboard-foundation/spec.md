# Feature Specification: Inventory Dashboard Foundation & Guardrails

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-22

**Status**: Draft

**Input**: User description: "Read the first Phase (FI-0 — Foundation and Guardrails) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the first spec per GitHub Spec Kit best practices."

## Overview

This is the foundation phase of the inventory admin dashboard. It establishes the safety rails — who may enter the dashboard, what each administrator is allowed to see and do, and the guarantee that no dashboard action can ever change stock outside the trusted domain logic — **before any inventory screen is built**. Every later phase (warehouses, stock levels, adjustments, transfers, reservations, reports) inherits these rails, so a later screen cannot accidentally bypass access control, data integrity, or the audit trail.

No inventory management screens are delivered in this phase. The deliverable is the trustworthy boundary those screens will sit on.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Only authorized administrators can enter the inventory dashboard (Priority: P1)

A person attempts to open the inventory dashboard. If they are a System Administrator, they are let in; if they are any other kind of account (customer, employee, unauthenticated), they are turned away. This is the outermost gate that protects all sensitive stock and document data behind it.

**Why this priority**: Without this gate, none of the downstream inventory data (stock balances, movements, financial-adjacent documents) can be exposed safely. It is the single most critical protection and the minimum viable slice of the foundation.

**Independent Test**: Sign in as a non-administrator and confirm the dashboard is inaccessible; sign in as a System Administrator and confirm entry is granted. Delivers value on its own by making the dashboard safe to expose at all.

**Acceptance Scenarios**:

1. **Given** a user whose account type is not System Administrator, **When** they open the inventory dashboard, **Then** access is denied and no inventory data is shown.
2. **Given** a System Administrator, **When** they open the inventory dashboard, **Then** access is granted.
3. **Given** an unauthenticated visitor, **When** they request the dashboard, **Then** they are redirected to authenticate and cannot reach any inventory area.

---

### User Story 2 - Administrators see and reach only what their permissions allow (Priority: P2)

Within the dashboard, each administrator is granted a subset of inventory capabilities (for example: view stock, view movements, create adjustments, confirm transfers, export). Areas they are not permitted to use are hidden from navigation, and attempting to reach one directly by its address is refused. This lets a future warehouse-operator style role be given a narrow slice of the dashboard without redesign.

**Why this priority**: Fine-grained permissions turn a single all-or-nothing admin door into a governable surface. It is essential for least-privilege operation but depends on the P1 gate existing first.

**Independent Test**: Grant an administrator only a limited permission set, then confirm the disallowed areas are absent from navigation and that visiting their addresses directly is refused, while the allowed areas work.

**Acceptance Scenarios**:

1. **Given** an administrator without the "view stock" permission, **When** they open the inventory area, **Then** the stock views are not listed for them.
2. **Given** that same administrator, **When** they navigate directly to a stock view's address, **Then** access is refused rather than the data being shown.
3. **Given** an administrator with only "view" permissions, **When** they look for create/confirm/export controls, **Then** those controls are absent.
4. **Given** an administrator whose granted permissions change, **When** they next act in the dashboard, **Then** their visible areas and available actions reflect the updated permissions.

---

### User Story 3 - Every stock change is forced through trusted logic and is auditable (Priority: P3)

The foundation guarantees that no part of the dashboard can alter a stock balance or the movement ledger on its own. All stock-changing operations are handed to the shared domain logic, which records the change and its audit entry together, atomically. If that logic rejects an operation, the dashboard shows the reason and nothing is partially written.

**Why this priority**: This is the integrity backbone that keeps the dashboard honest as screens are added. It is a guarantee enforced at the foundation so no later screen can violate it; it delivers most of its value once actual write screens exist, hence P3.

**Independent Test**: Confirm via an automated architecture check that no dashboard component writes stock balances or movement records directly, and that a rejected operation produces a clear notification with no data change.

**Acceptance Scenarios**:

1. **Given** any stock-changing action offered anywhere in the dashboard, **When** it runs, **Then** it delegates to the shared domain logic and performs no direct write to stock balances or the movement ledger.
2. **Given** the domain logic rejects an operation (e.g., invalid state), **When** the action runs, **Then** no stock change or movement is recorded and the reason is shown to the administrator as a notification.
3. **Given** a sensitive inventory action succeeds, **When** it completes, **Then** an audit entry is recorded as part of the same operation, attributed to the acting administrator and marked as originating from the dashboard.

---

### Edge Cases

- An account is of type System Administrator but has been granted **zero** inventory permissions: they may enter the dashboard shell but see no inventory areas and can reach none by direct address.
- An administrator's permissions are revoked while they are mid-session: the next action they attempt reflects the revocation rather than the stale state.
- The domain logic fails partway through a multi-step operation: the operation is fully rolled back — no partial stock change, no orphan movement, no partial audit entry.
- A user attempts to permanently delete an inventory ledger record: the dashboard offers no such capability at the foundation level.
- A deep link or bookmarked address to a not-yet-built or unpermitted area is followed: the request is refused or routed to a safe placeholder rather than erroring or leaking data.

## Requirements *(mandatory)*

### Functional Requirements

> **Phasing note**: This is the foundation phase (FI-0). Requirements tagged **[FI-0 complete]** are fully delivered and tested here. Requirements tagged **[foundation pattern → FI-1+]** have their reusable mechanism built and unit-tested here, but full enforcement is realized as inventory resources ship in later phases. Requirements tagged **[deferred → services]** depend on the inventory domain services that do not exist yet (see Dependencies and Open Question #11). See plan.md → "Requirement coverage at FI-0" for the authoritative task mapping.

- **FR-001**: The system MUST deny inventory dashboard access to any account that is not a System Administrator. *[FI-0 complete]*
- **FR-002**: The system MUST grant inventory dashboard access to System Administrator accounts. *[FI-0 complete]*
- **FR-003**: The system MUST define a granular set of inventory permissions covering, at minimum: warehouse view and manage; stock view; movement view; adjustment view, create, and confirm; transfer view, create, and confirm; reservation view and release; and export. *[FI-0 complete]*
- **FR-004**: The system MUST hide from an administrator any inventory area for which they lack the corresponding view permission. *[foundation pattern → FI-1+: the ability→permission policy trait is built here; per-resource navigation hiding is applied as each resource ships]*
- **FR-005**: The system MUST refuse access when an administrator navigates directly to the address of an inventory area they lack permission for, without exposing its data. *[foundation pattern → FI-1+: enforced per resource via the shared policy as resources ship]*
- **FR-006**: The system MUST route every stock-changing operation through shared domain logic; no dashboard component may modify stock balances or movement records directly. *[FI-0 complete: adapter + build-failing architecture guard]*
- **FR-007**: The system MUST surface errors and validation failures from the domain logic as clear administrator-facing notifications, with no partial data written. *[FI-0 complete]*
- **FR-008**: The system MUST authorize each inventory area through a shared authorization policy that is the same authorization used by other access channels — authorization MUST NOT be duplicated or forked for the dashboard. *[FI-0 complete: shared policy pattern established]*
- **FR-009**: The system MUST record sensitive inventory actions to the single audit trail as part of the domain operation, attributed to the acting administrator and marked as originating from the dashboard, with no separate parallel audit trail. Here "sensitive inventory actions" means the stock-changing operations (confirming an adjustment or transfer, releasing a reservation). *[deferred → services: audit is written by the domain services; realized when they exist — Open Question #11]*
- **FR-010**: The inventory permission set MUST be seeded so that roles can be granted any subset of it. *[FI-0 complete]*
- **FR-011**: The system MUST NOT expose any capability to permanently delete inventory ledger records from the dashboard at the foundation level. *[FI-0 complete by construction — no ledger resource with a delete path exists; a delete-absence policy test is added with the first ledger resource in FI-2]*
- **FR-012**: The system MUST provide reusable foundation building blocks (access gate, permission definitions, authorization policies, and a shared action-to-domain-logic adapter) that every later inventory area inherits, so integrity and access rules are defined once rather than per screen. *[FI-0 complete]*

### Key Entities *(include if feature involves data)*

- **Administrator**: An account of type System Administrator that may access the dashboard; carries an assigned role and, through it, a set of inventory permissions.
- **Inventory Permission**: A named capability (e.g., "view stock", "confirm transfer", "export") that governs whether an administrator can see or perform something in the inventory dashboard.
- **Role**: A named grouping of permissions that can be assigned to administrators to grant a subset of dashboard capabilities.
- **Audit Entry**: A record of a sensitive inventory action, capturing who acted, what changed (before/after), the affected record, and that the action originated from the dashboard channel.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of non-administrator attempts to open the inventory dashboard are denied. *[FI-0 complete]*
- **SC-002**: An administrator can see and reach only the inventory areas their permissions allow — 0 unauthorized areas are visible in navigation or reachable by direct address. *[foundation pattern → FI-1+: measured per resource as resources ship]*
- **SC-003**: 100% of successful stock-changing actions initiated from the dashboard produce both a movement record and a matching audit entry. *[deferred → services: measurable once domain services exist — Open Question #11]*
- **SC-004**: 0 dashboard code paths change stock balances or movement records without going through the shared domain logic, verified by an automated architecture check that fails the build on any violation. *[FI-0 complete]*
- **SC-005**: 100% of rejected stock-changing operations result in no data change and a clear reason shown to the administrator. *[FI-0 complete]*
- **SC-006**: A new inventory role can be granted a working subset of dashboard capabilities without any change to authorization code, confirming permissions are the sole access lever. *[FI-0 complete]*

## Assumptions

- **Governance / scope approval (resolved)**: The Filament admin dashboard for the **Inventory module only** has been approved by the project owner and recorded in [ADR 0001](../../Docs/adr/0001-filament-inventory-dashboard-for-inventory.md). PRD §10 and the project constitution (Product Scope & Boundaries, v1.2.0) have been amended to reflect the exception. A Filament dashboard for any other module remains out of scope pending a separate ADR. This resolves Plan §0 and Open Question #1; this spec is no longer a proposal in that respect.
- **Backend prerequisites delivered first**: The inventory data model, the shared domain logic (movement, adjustment, transfer, reservation, and status-transition services), input-validation rules, and authorization policies from the backend Products-and-Inventory phase MUST already exist. This foundation phase cannot begin until they do. (Plan Open Question #11.)
- **Access scope defaults to System Administrator only**: Employees affect inventory through the separate employee-app channel using the same shared domain logic; they do not receive dashboard access in this phase. Whether a warehouse/operator role later gets dashboard access is deferred. (Plan Open Question #7.)
- **Standard authorization and audit mechanisms are reused**: Authorization uses the project's standard role/permission system; auditing uses the project's single existing audit trail — no new bespoke access-control or audit stores are introduced.
- **Session-based dashboard authentication** is already wired and is distinct from the token-based API; no change to the authentication mechanism is required here.
- **Stock balances are never cached**: consistent with existing architecture rules, foundation building blocks must not introduce caching of stock balances.
- **Database engine** is treated as interchangeable at this stage; the engine choice is locked separately before any data migrations run.

## Out of Scope

- Any user-facing inventory management screen (warehouses, stock levels, movements, adjustments, transfers, reservations, returns, widgets, exports) — these are later phases (FI-1 through FI-6).
- Write logic for any other module (catalog, sales, accounting, payments); those remain read-only references reachable only as links.
- Granting dashboard access to employee or warehouse-operator roles.
- Changing the authentication mechanism or the underlying domain logic itself.

## Dependencies

- Backend inventory models, shared domain services, validation rules, and authorization policies (backend Products-and-Inventory phase).
- The project's standard role/permission system, with inventory permissions seeded.
- The project's existing single audit trail.
- Recorded project-owner approval of the Filament dashboard scope exception — satisfied by [ADR 0001](../../Docs/adr/0001-filament-inventory-dashboard-for-inventory.md).
