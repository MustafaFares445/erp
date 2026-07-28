# Research: Damaged Stock and Missing Alerts

## R1 - Locking Boundary

Laravel 13 documents that `lockForUpdate()` should run inside a transaction. Existing receipt, transfer, adjustment, and reservation services already own transactions, so `InventoryBalanceService` will lock the exact stock row and persist validated quantities inside those transactions.

## R2 - Physical and Usable Quantities

On-hand remains the physical count. Damaged stock is a quarantined subset, so:

`available = on hand - reserved - damaged`

Damage and recovery do not change physical stock. Disposal reduces both physical and damaged quantities.

## R3 - Damage Authorization

Damage is an inventory adjustment operation. The stock resource remains visible through `StockView`; mutation actions require `AdjustmentConfirm`. This avoids adding an unplanned management permission while retaining explicit authorization.

## R4 - Alert Identity

Existing alerts are unique by type and subject. New context and severity columns preserve that idempotent subject alert while allowing details to update and resolved alerts to reopen.

## R5 - Device Reconciliation

For a serialized variant/warehouse, physical devices are those with status `Available` or `Damaged`. `InTransit`, `AdjustedOut`, `Disposed`, `Pending`, and `Unknown` do not count as physical stock in that warehouse.

## R6 - Duplicate Attempts

The shared guard detects an existing SKU/Serial/IoT, records a duplicate alert against the existing record, and then throws. Callers invoke it outside rollback scopes where necessary; unique indexes remain the concurrency safeguard.

## R7 - Scheduler

Laravel 13 supports scheduled commands from `routes/console.php`. The reconciliation command will be registered with `Schedule::command(...)->daily()` and verified with `schedule:list`.
