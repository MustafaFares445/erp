# Quickstart: Damaged Stock and Missing Alerts

1. Run migrations.
2. Seed inventory permissions.
3. Grant `StockView`, `AdjustmentConfirm`, and `AlertView` as appropriate.
4. Use stock-level actions to damage, recover, or dispose stock.
5. Run `php artisan inventory:alerts:reconcile`.
6. Verify the command appears in `php artisan schedule:list`.
7. Run focused Pest tests, Pint, and PHPStan.
