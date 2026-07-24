# Data Model: Complete Excel Import Workflow

## Inventory Import Run

Existing table with additive fields:

- `status`: `queued`, `parsing`, `ready`, `ready_with_errors`, `invalid`, `applying`, `confirmed`, `confirmed_with_errors`, `failed`
- `created_rows`: catalog entities first created by applied rows
- `updated_rows`: existing variants updated by applied rows
- `applied_rows`: successfully applied rows
- `rejected_rows`: validation-invalid plus runtime-failed rows
- `failure_message`: terminal job-level failure
- `applying_at`, `confirmed_at`: workflow timestamps
- `result_path`, `summary_path`: private generated reports

## Inventory Import Item

Existing table with additive fields:

- `status`: `valid`, `invalid`, `applying`, `applied`, `rejected`
- `operation`: `catalog_created`, `catalog_updated`, `inventory_received`
- `runtime_error`: normalized runtime failure
- `result`: JSON object containing applicable identifiers:
  - `product_id`
  - `product_variant_id`
  - `inventory_receipt_id`
  - `inventory_receipt_item_id`
  - `serialized_inventory_unit_id`
  - `inventory_lot_id`
- `idempotency_key`: deterministic run/row key, unique
- `applied_at`: terminal successful timestamp

## State Transitions

```text
Queued -> Parsing -> Ready | ReadyWithErrors | Invalid
Ready | ReadyWithErrors -> Applying -> Confirmed | ConfirmedWithErrors | Failed
```

Invalid runs cannot be applied. Terminal runs cannot re-enter applying. A stale retried job for an already terminal run exits without mutation.

## Group Relationships

- Catalog-only item -> product and variant.
- Inventory item -> one shared receipt per warehouse/supplier group -> one receipt item for that row.
- Serialized inventory item -> one serialized unit attached to its receipt item.
- Expiry inventory item -> one lot produced by receipt confirmation.
- Attributes -> existing or new active values assigned idempotently to the variant pivot.

## Migration Compatibility

- All new columns are nullable or have zero defaults.
- Historical `invalid` runs with `valid_rows > 0` become `ready_with_errors`; other historical invalid runs remain `invalid`.
- Historical confirmed rows receive counters derived from current item statuses where possible.
- No historical catalog, receipt, identity, lot, stock, or movement record is deleted.
