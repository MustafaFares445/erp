# Feature Specification: Pricing Controls and Customer Tiers

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-25

**Status**: Approved

**Input**: Close the product and inventory SRS pricing gaps by making base prices derived, routing all pricing changes through an audited service, assigning one active general tier per customer, limiting customer-specific tiers, and recording immutable below-floor approvals.

## User Scenarios & Testing

### User Story 1 - Maintain Audited Variant Prices (Priority: P1)

As a pricing administrator, I can edit a variant's cost, markup, and minimum price while the system derives the base price and records each effective change.

**Why this priority**: Price correctness and traceability are prerequisites for every other pricing workflow.

**Independent Test**: Change a variant's cost or markup and verify the derived base price, price history, and audit event are created atomically; save unchanged values and verify no history is added.

**Acceptance Scenarios**:

1. **Given** a variant with a cost and markup, **When** either value changes, **Then** the base price is recalculated as `cost × (1 + markup / 100)`.
2. **Given** a variant with existing prices, **When** the same values are saved, **Then** no price history or pricing audit event is created.
3. **Given** a user without pricing-management permission, **When** the user attempts to mutate pricing, **Then** the mutation is denied without changing the variant.

---

### User Story 2 - Assign Customer Pricing Tiers (Priority: P2)

As a pricing administrator, I can assign a general pricing tier to a customer and maintain an optional customer-specific tier without ambiguous active assignments.

**Why this priority**: Deterministic tier assignment is required for reliable customer price resolution.

**Independent Test**: Assign two general tiers in sequence and verify only the newest remains active; activate two specific tiers and verify only the newest remains active while resolution remains specific, general, then base.

**Acceptance Scenarios**:

1. **Given** a customer with an active general tier, **When** another general tier is assigned, **Then** the previous assignment is deactivated and the new assignment becomes active.
2. **Given** a customer-specific active tier, **When** another customer-specific tier is activated, **Then** the previous specific tier is deactivated.
3. **Given** a non-customer user, **When** an assignment is attempted, **Then** the assignment is rejected.

---

### User Story 3 - Approve and Review Below-Floor Prices (Priority: P3)

As a pricing administrator, I can approve a documented below-floor price and later review immutable override and price histories.

**Why this priority**: Exceptions must be deliberate, authorized, and auditable without weakening the normal price-floor rule.

**Independent Test**: Approve a below-floor price with a reason, verify the immutable approval and audit record, and confirm pricing viewers can inspect histories while users without pricing permission cannot.

**Acceptance Scenarios**:

1. **Given** an attempted price below the variant floor, **When** an authorized administrator supplies a reason, **Then** an immutable approval records the variant, attempted price, floor, approver, time, reason, and optional customer.
2. **Given** a price at or above the floor, **When** an override is requested, **Then** the request is rejected because no override is required.
3. **Given** a pricing viewer, **When** histories are opened, **Then** records are read-only and display their related variant and actor details.

### Edge Cases

- A null variant markup uses the configured default markup before deriving the base price.
- A customer-specific tier cannot be assigned to an administrator or employee.
- Soft-deleted or inactive general tiers cannot become active assignments.
- A failed audit or history write rolls back the associated pricing change.
- Concurrent assignments for the same customer serialize so only one general assignment remains active.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST derive base price from cost and markup and MUST NOT accept direct base-price edits.
- **FR-002**: Every effective cost, markup, minimum-price, or derived-base-price change MUST create one price-history record and one audit record in the same transaction.
- **FR-003**: A no-op price save MUST NOT create price history or audit noise.
- **FR-004**: All price, tier-assignment, specific-tier, and floor-override mutations MUST pass through one pricing service.
- **FR-005**: Price resolution MUST remain read-only and use the order customer-specific tier, assigned general tier, then base price.
- **FR-006**: A customer MUST have at most one active general-tier assignment.
- **FR-007**: A customer MUST have at most one active customer-specific tier.
- **FR-008**: Tier assignment MUST be limited to users whose account type is Customer and to active general tiers.
- **FR-009**: Below-floor approval MUST require a variant, attempted price, non-empty reason, authorized approver, and optional customer.
- **FR-010**: Floor override and price-history records MUST be immutable through the administration interface.
- **FR-011**: Viewing sensitive pricing MUST require pricing-view permission; assignments, tier changes, and overrides MUST require pricing-management permission.
- **FR-012**: The feature MUST remain internal to the System Administrator panel and MUST NOT change public routes or client contracts.

### Key Entities

- **Product Variant Pricing**: Cost, markup, derived base price, and minimum permitted price for a sellable variant.
- **Price History**: Immutable snapshot of variant pricing after an effective change and the user who made it.
- **Pricing Tier**: An active or inactive percentage discount that is either general or specific to one customer.
- **Customer Tier Assignment**: The active relationship between one customer and one general pricing tier.
- **Price Floor Override**: Immutable approval of a specific attempted below-floor price.

## Success Criteria

### Measurable Outcomes

- **SC-001**: 100% of effective pricing changes create matching history and audit records, and 0% of no-op saves create either record.
- **SC-002**: Every customer price resolves deterministically to exactly one source: specific tier, assigned general tier, or base price.
- **SC-003**: At all times, each customer has no more than one active general assignment and one active customer-specific tier.
- **SC-004**: 100% of below-floor approvals retain the required reason, approver, price, floor, timestamp, variant, and optional customer.
- **SC-005**: Unauthorized users cannot view sensitive pricing or execute any pricing mutation through the administration panel.

## Assumptions

- Base price is always calculated and cannot be entered directly.
- Existing inventory permissions, audit records, authentication, and administrative panel are reused.
- Tier discounts remain percentage-based and do not introduce currency conversion.
- Arabic localization, translations, and RTL support are out of scope.
- No public API or dependency change is required.
