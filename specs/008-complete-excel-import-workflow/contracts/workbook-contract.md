# Internal Contract: Inventory Import Workbook

## Required Base Headers

- `sku`
- `product_name`
- `variant_name`

## Inventory Headers

- `warehouse_code`
- `quantity`
- `serial_number`
- `iot_number`
- `lot_number`
- `expires_at`

`warehouse_code` and `quantity` are optional only when both are absent and no identity, lot, or expiry value is supplied.

## Dynamic Attribute Headers

Each active `ProductAttribute.code` appears as `attribute_{code}`.

- `select`: submitted value must match an active configured value case-insensitively.
- `text`: submitted value is reused case-insensitively or created during apply.
- Unknown or inactive attribute columns are validation errors.

## Serialized Rows

- One device per row.
- Quantity is exactly `1`.
- Serial is required when the variant tracks serials.
- IoT is optional but globally unique when supplied.

## Lot Rows

- Quantity is any positive value allowed by the selected unit.
- Expiry is required when the variant tracks expiry.
- Lot number is optional unless otherwise required by existing receiving rules.

## Private Results

The detailed CSV contains row number, validation status, apply status, errors, runtime error, operation, and result identifiers. The summary CSV contains run status and all counters. Both remain on the private disk.
