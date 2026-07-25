# Data Model: Global Search, Device Tracking, and Expiry

## Serialized Inventory Unit

Existing fields remain unchanged. `status` is cast to:

- `Pending`
- `Available`
- `InTransit`
- `AdjustedOut`
- `Damaged`
- `Disposed`
- `Unknown`

Add relationships to inventory movements and retain the receipt-item relationship.

## Device Timeline Event

Read-only derived shape:

- occurred timestamp
- movement/event type
- warehouse code/name
- signed quantity
- source reference
- notes
- synthetic flag

Events originate from immutable movement rows. A synthetic initial receipt event may originate from the receipt item.

## Inventory Lot

Existing quantities remain unchanged.

- available quantity = on-hand quantity - reserved quantity
- days remaining = signed calendar-day difference from today
- expiry state = expired, expiring, healthy, or no-expiry

No new lot columns are required.

## Compatibility Migration

No schema column is required. An additive data migration normalizes unknown historical serialized status strings to `unknown`; no movement, receipt, lot, or device is deleted.
