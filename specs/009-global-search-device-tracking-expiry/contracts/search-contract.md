# Internal Contract: Catalog and Device Search

## Product and Variant Search

Searchable data:

- English and Arabic product/variant names
- SKU and barcode
- English and Arabic brand/category names
- supplier name and stored supplier-name snapshot
- supplier item number
- manufacturer
- country ISO alpha-2 code
- localized English and Arabic country name

Results require CatalogView and link to read-only product or variant pages.

## Device Search

Searchable data:

- Serial number
- IoT number
- SKU
- variant name
- product name

Results require StockView and link to the device detail page.

## Country Expansion

The search term is matched case-insensitively against ICU country names and alpha-2 codes. Country constraints are grouped with standard attributes inside existing model scopes.
