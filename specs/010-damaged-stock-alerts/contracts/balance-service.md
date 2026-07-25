# Contract: InventoryBalanceService

## Operations

- Receive/increase physical stock.
- Transfer/decrease usable physical stock.
- Adjust physical stock to an absolute count.
- Reserve and release usable stock.
- Mark usable stock damaged.
- Recover damaged stock.
- Dispose damaged stock.

## Invariants

- Quantities use three-decimal semantics and must be positive operation amounts.
- `on_hand >= 0`.
- `reserved >= 0`.
- `damaged >= 0`.
- `reserved + damaged <= on_hand`.
- `available = on_hand - reserved - damaged`.
- Transfer-out and damage consume available stock only.
- Disposal consumes damaged stock only.
- Absolute adjustment cannot remove reserved or damaged stock.

## Transaction Contract

- Caller owns the business transaction.
- Service locks the target stock row.
- Failure throws `DomainException` before saving.
- Service returns the freshly persisted stock.
