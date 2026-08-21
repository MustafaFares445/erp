# Internal Contract: Serialized Device Timeline

## New Events

- Receipt: one movement per serialized device, quantity `+1`.
- Transfer dispatch: one movement, quantity `-1`, status `InTransit`.
- Transfer receipt: one movement, quantity `+1`, status `Available` at the destination.
- Adjustment out: one movement, quantity `-1`, status `AdjustedOut`, no current warehouse.
- Adjustment in: one movement, quantity `+1`, status `Available` at the adjustment warehouse.

Every event stores `serialized_inventory_unit_id`.

## Historical Receipt Fallback

When no receipt movement exists for the device:

1. Read the associated receipt item and receipt.
2. Create an in-memory event using receipt/item time, warehouse, quantity one, and receipt reference.
3. Mark the event synthetic.
4. Do not insert or modify ledger rows.

## Ordering

Sort ascending by occurrence timestamp and movement identifier, placing the synthetic receipt before equal-time persisted events.
