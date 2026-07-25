# Contract: Inventory Alerts

## Types

- low_stock
- out_of_stock
- expiry
- transfer_discrepancy
- import_error
- duplicate_identity
- missing_device_identity

## Lifecycle

- Activation is idempotent by type and subject.
- Re-activation clears `resolved_at` and refreshes message, severity, and context.
- Resolution timestamps the active alert without deleting it.

## Rules

- Available zero: activate out-of-stock, resolve low-stock.
- Available positive: resolve out-of-stock, then evaluate reorder level.
- Import invalid/failed/with-errors: activate import-error.
- Import clean confirmed: resolve import-error.
- Existing duplicate SKU/Serial/IoT: activate duplicate-identity before returning an error.
- Serialized physical stock/device-count mismatch: activate missing-device-identity.

## Resource

- Read-only.
- Requires `AlertView`.
- Supports active/resolved, type, and severity filters.
- Shows context and an authorized link to the subject record when available.
