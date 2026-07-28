# Data Model: Damaged Stock and Missing Alerts

## inventory_stocks

- Add `damaged_quantity decimal(15,3) not null default 0`.
- Preserve unique `(product_variant_id, warehouse_id)`.
- Enforce application invariants before every save.

## inventory_alerts

- Existing: type, polymorphic subject, message, resolved timestamp.
- Add `severity varchar(20) default warning`.
- Add nullable `context json`.
- Cast type and severity to backed enums.
- Reuse unique `(type, subject_type, subject_id)` for idempotent activation/reopening.

## inventory_movements

- Existing schema is sufficient.
- Add enum values: damage, damage_recovery, disposal.
- Damage/recovery represent usable-state deltas; disposal represents physical removal.
- Link a device when a serialized unit is targeted.

## serialized_inventory_units

- Existing enum gains no new values; Phase 009 already includes `Damaged` and `Disposed`.
- Damage: Available -> Damaged.
- Recovery: Damaged -> Available.
- Disposal: Damaged -> Disposed and warehouse becomes null.

## Permissions

- Add `inventory.alert.view`.
- Reuse `inventory.stock.view` for stock visibility.
- Reuse `inventory.adjustment.confirm` for damage mutations.
