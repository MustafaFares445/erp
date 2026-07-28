# Quickstart: Global Search, Device Tracking, and Expiry

```powershell
php artisan test --compact tests/Feature/CatalogGlobalSearchTest.php
php artisan test --compact tests/Feature/SerializedInventoryTrackingTest.php
php artisan test --compact tests/Feature/Filament/InventoryLotResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

Acceptance requires valid product/variant search URLs, localized country matching, one linked movement per serialized stock event, synthetic receipt fallback for legacy devices, and nearest-expiry lot ordering/filtering.
