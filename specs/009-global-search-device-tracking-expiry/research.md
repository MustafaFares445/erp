# Research: Global Search, Device Tracking, and Expiry

## Findings

- Product and variant resources are `ManageRecords` pages only, so their current global-search results have no stable view destination.
- Filament 5 supports relationship dot notation for global-search attributes and selects a view page before an edit page.
- The live installation does not contain `symfony/intl`; PHP `intl` and ICU region bundles are available.
- Transfer movements already store serialized-unit identifiers, while receipt and adjustment movements do not.
- Receipt items and serialized units already retain enough data to derive a missing historical receipt event.
- Lots already retain warehouse, variant, expiry, on-hand, and reserved quantities.

## Decisions

### D1 - Country names

Use `ResourceBundle` with `ICUDATA-region` for English, Arabic, and the current locale. Resolve matching alpha-2 codes, then add a grouped relationship constraint so soft-delete scopes cannot be bypassed.

### D2 - Device movement granularity

Serialized receipts create one quantity-one movement per device. Nonserialized receipts retain one aggregate item movement.

### D3 - Adjustment semantics

A serialized decrease of exactly one moves an available device out of stock and sets `AdjustedOut`; an increase of exactly one restores an `AdjustedOut` device to `Available` in the adjustment warehouse. Other serialized differences are rejected.

### D4 - Historical fallback

The timeline service never inserts historical ledger rows. It prepends one synthetic receipt event when the device has an associated receipt item but no serialized receipt movement.

### D5 - Lot states

Use `InventorySetting.expiry_alert_days`: before today is expired, today through the threshold is expiring, after the threshold is healthy, and null is no-expiry.
