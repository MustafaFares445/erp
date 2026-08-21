# Feature Specification: Damaged Stock and Missing Alerts

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-25

**Status**: Approved

**Input**: Centralize inventory balance calculations, expose damaged stock and atomic damage operations, add missing inventory alert types and reconciliation, and retain complete device and audit history.

## User Scenarios & Testing

### User Story 1 - Trust Every Stock Balance (Priority: P1)

As an inventory administrator, I see one balance equation applied consistently after receiving, reservation, transfer, adjustment, damage, recovery, and disposal.

**Independent Test**: Apply every supported stock operation and verify physical, reserved, damaged, and available quantities after each step.

**Acceptance Scenarios**:

1. **Given** physical stock, **When** any operation changes a balance, **Then** `available = on hand - reserved - damaged`.
2. **Given** reserved or damaged stock, **When** an operation would reduce on-hand below their sum, **Then** the complete transaction is rejected.
3. **Given** a direct stock mutation outside the balance service, **When** architecture tests run, **Then** the unsupported write path is detected.

---

### User Story 2 - Quarantine and Resolve Damaged Stock (Priority: P2)

As an authorized inventory administrator, I can mark usable stock damaged, recover damaged stock, or dispose of damaged stock with a reason, preview, movement, and audit history.

**Independent Test**: Damage, recover, and dispose quantities and serialized devices, verifying balances, status transitions, movements, audit logs, authorization, and rollback.

**Acceptance Scenarios**:

1. **Given** unreserved usable stock, **When** it is damaged, **Then** on-hand is unchanged while damaged increases and available decreases.
2. **Given** damaged stock, **When** it is recovered, **Then** damaged decreases and available increases while on-hand is unchanged.
3. **Given** damaged stock, **When** it is disposed, **Then** damaged and on-hand both decrease while available is unchanged.
4. **Given** a targeted device, **When** the operation succeeds, **Then** its status and linked movement match the operation.

---

### User Story 3 - Review Actionable Inventory Alerts (Priority: P3)

As a stock viewer with alert permission, I can review active and resolved alerts for out-of-stock, import errors, duplicate identities, missing device identity, low stock, expiry, and transfer discrepancy.

**Independent Test**: Trigger, update, resolve, and reconcile every alert type and verify the read-only page, severity, context, and record link.

**Acceptance Scenarios**:

1. **Given** available stock reaches zero, **When** stock alerts synchronize, **Then** out-of-stock becomes active and low-stock resolves.
2. **Given** stock later becomes usable, **When** alerts synchronize, **Then** out-of-stock resolves and low-stock is evaluated again.
3. **Given** a failed/partial import, duplicate identity, or serialized-count mismatch, **When** detection runs, **Then** the corresponding contextual alert is active.
4. **Given** the daily reconciliation command, **When** it runs, **Then** stock, expiry, transfer, import, and identity alerts reflect current persisted state.

### Edge Cases

- Damaged quantity cannot be negative or exceed physical stock after reservations.
- Reserved stock cannot be damaged, transferred, adjusted away, or disposed.
- Recovery and disposal cannot exceed the damaged quantity.
- A serialized damage operation always has quantity one and validates variant, warehouse, and current status.
- Duplicate alerts persist even though the attempted write fails; database unique indexes remain the final race-condition safeguard.
- Alert synchronization is idempotent and preserves resolved records by reopening the same subject alert when needed.

## Requirements

### Functional Requirements

- **FR-001**: `inventory_stocks` MUST contain `damaged_quantity DECIMAL(15,3) DEFAULT 0`.
- **FR-002**: `on_hand_quantity` MUST represent all physically present stock, including damaged stock.
- **FR-003**: Every saved balance MUST satisfy `available_quantity = on_hand_quantity - reserved_quantity - damaged_quantity`.
- **FR-004**: On-hand, reserved, damaged, and available quantities MUST be non-negative, and reserved plus damaged MUST NOT exceed on-hand.
- **FR-005**: `InventoryBalanceService` MUST be the only production service that calculates and persists stock balance fields.
- **FR-006**: Receiving, transfers, adjustments, and reservation release MUST delegate balance changes to the balance service.
- **FR-007**: Authorized stock actions MUST support damage, recovery, and disposal with quantity, reason, impact preview, confirmation, movement, and audit records.
- **FR-008**: Damage and recovery MUST leave on-hand unchanged; disposal MUST reduce on-hand and damaged by the same quantity.
- **FR-009**: Movement types MUST include `Damage`, `DamageRecovery`, and `Disposal`.
- **FR-010**: Targeted serialized operations MUST link `serialized_inventory_unit_id` and transition `Available -> Damaged -> Available|Disposed`.
- **FR-011**: Out-of-stock MUST be active exactly when available is zero; low-stock MUST be inactive while out-of-stock is active.
- **FR-012**: Import errors MUST activate for invalid, failed, or confirmed-with-errors imports and resolve for clean confirmed runs.
- **FR-013**: A shared identity guard MUST detect duplicate SKU, Serial, and IoT identifiers, raise an alert, then return a validation/domain error.
- **FR-014**: Missing-device-identity MUST compare physical serialized stock with registered physical devices for each variant and warehouse.
- **FR-015**: Inventory alerts MUST expose type, severity, context, active/resolved state, originating record, and authorized record link.
- **FR-016**: A daily scheduled command MUST reconcile stock, expiry, transfer, import, and legacy identity alerts.
- **FR-017**: Alert viewing MUST require `AlertView`; stock damage actions MUST require adjustment-confirm authorization in addition to stock visibility.
- **FR-018**: Stock reports/widgets MUST expose damaged stock separately and exclude it from usable valuation in Phase 011.

## Success Criteria

- **SC-001**: All balance-operation tests preserve the equation to three decimal places.
- **SC-002**: Invalid operations create no partial balance, movement, device-status, or audit change.
- **SC-003**: Every damage operation creates exactly one movement and one audit event.
- **SC-004**: Every alert type can be activated and resolved idempotently.
- **SC-005**: The scheduler lists one daily reconciliation command.
- **SC-006**: Focused tests, Pint, and PHPStan pass with no baseline changes.

## Out of Scope

- Arabic translations and RTL.
- Public APIs.
- Automated accounting write-offs.
- Exchange-rate conversion.
