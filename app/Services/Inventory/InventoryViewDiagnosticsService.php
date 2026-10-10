<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryDiagnosis;
use App\Services\Inventory\Diagnostics\InventoryAdjustmentDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryAlertDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryConditionChangeDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryCorrectionDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryCountDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryLotDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryOperationDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryRecordDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryReservationDiagnoser;
use App\Services\Inventory\Diagnostics\InventoryReturnDiagnoser;
use App\Services\Inventory\Diagnostics\OutboundFulfillmentDiagnoser;
use App\Services\Inventory\Diagnostics\PackageDiagnoser;
use App\Services\Inventory\Diagnostics\ProductDiagnoser;
use App\Services\Inventory\Diagnostics\PurchaseInboundDiagnoser;
use App\Services\Inventory\Diagnostics\SerializedInventoryUnitDiagnoser;
use App\Services\Inventory\Diagnostics\ShipmentDiagnoser;
use App\Services\Inventory\Diagnostics\StockLevelDiagnoser;
use App\Services\Inventory\Diagnostics\StockMovementDiagnoser;
use App\Services\Inventory\Diagnostics\WarehouseDiagnoser;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only, evidence-based diagnosis for Inventory View pages (Docs/domains/inventory/VIEW_UX_DIAGNOSTICS.md).
 * Dispatches to the diagnoser that owns the record type; it never posts stock or changes workflow state.
 */
final readonly class InventoryViewDiagnosticsService
{
    /** @var list<class-string<InventoryRecordDiagnoser>> */
    private const array Diagnosers = [
        WarehouseDiagnoser::class,
        StockLevelDiagnoser::class,
        InventoryLotDiagnoser::class,
        SerializedInventoryUnitDiagnoser::class,
        ProductDiagnoser::class,
        InventoryOperationDiagnoser::class,
        InventoryCountDiagnoser::class,
        InventoryReservationDiagnoser::class,
        InventoryAdjustmentDiagnoser::class,
        InventoryReturnDiagnoser::class,
        InventoryCorrectionDiagnoser::class,
        InventoryConditionChangeDiagnoser::class,
        InventoryAlertDiagnoser::class,
        ShipmentDiagnoser::class,
        PurchaseInboundDiagnoser::class,
        OutboundFulfillmentDiagnoser::class,
        PackageDiagnoser::class,
        StockMovementDiagnoser::class,
    ];

    public function __construct(private Container $container) {}

    public function diagnose(Model $record): InventoryDiagnosis
    {
        foreach (self::Diagnosers as $class) {
            $diagnoser = $this->container->make($class);

            if ($diagnoser->supports($record)) {
                return $diagnoser->diagnose($record);
            }
        }

        return new InventoryDiagnosis([], limits: [__('inventory_view_ux.limits.no_rules')]);
    }
}
