# Quickstart: Complete Excel Import Workflow

## Focused Verification

```powershell
php artisan test --compact tests/Feature/CatalogImportServiceTest.php
php artisan test --compact tests/Feature/Filament/InventoryImportRunResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Acceptance Workbook

Build one XLSX containing:

1. A valid catalog-only row with text and select attributes.
2. A valid serialized row with warehouse, quantity one, Serial, and IoT.
3. A valid expiry-tracked lot row with quantity greater than one.
4. An invalid serialized row with quantity two.
5. An invalid select attribute value.
6. A valid row in a second warehouse/supplier group.

Parse it and assert `ReadyWithErrors`. Queue confirmation, run the apply job, then verify valid rows are applied, invalid rows retain reasons, stock effects have confirmed receipts/movements, and a retry creates no duplicates.
