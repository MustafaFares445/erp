# Contract: InventoryDamageService

## Input

- Stock record.
- Positive quantity.
- Non-empty reason.
- Optional serialized-unit identifier.
- Authenticated actor.

## Damage

- Require enough available stock.
- Increase damaged; leave on-hand unchanged.
- Device target must be Available in the same variant/warehouse and quantity one.
- Transition targeted device to Damaged.
- Create one `damage` movement and audit event.

## Recovery

- Require enough damaged stock.
- Decrease damaged; leave on-hand unchanged.
- Device target must be Damaged in the same variant/warehouse and quantity one.
- Transition targeted device to Available.
- Create one `damage_recovery` movement and audit event.

## Disposal

- Require enough damaged stock.
- Decrease damaged and on-hand equally.
- Device target must be Damaged in the same variant/warehouse and quantity one.
- Transition targeted device to Disposed and clear warehouse.
- Create one `disposal` movement and audit event.

## Atomicity

Balance, device status, movement, audit, and alert synchronization commit together or all roll back.
