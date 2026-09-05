<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryReportType: string
{
    case Catalog = 'catalog';
    case StockLevels = 'stock_levels';
    case Movements = 'movements';
    case Devices = 'devices';
    case ExpiryLots = 'expiry_lots';
    case QuarantineAgeing = 'quarantine_ageing';
    case SupplierComparison = 'supplier_comparison';
    case PriceHistory = 'price_history';
    case PricingTiers = 'pricing_tiers';
    case CustomerAssignments = 'customer_assignments';
    case FloorOverrides = 'floor_overrides';
    case ImportRuns = 'import_runs';
    case ImportResults = 'import_results';
    case Reconciliation = 'reconciliation';

    public function sourcePermission(): InventoryPermission
    {
        return match ($this) {
            self::Catalog, self::SupplierComparison => InventoryPermission::CatalogView,
            self::StockLevels, self::Devices, self::ExpiryLots, self::QuarantineAgeing, self::Reconciliation => InventoryPermission::StockView,
            self::Movements => InventoryPermission::MovementView,
            self::PriceHistory, self::PricingTiers, self::CustomerAssignments, self::FloorOverrides => InventoryPermission::PricingView,
            self::ImportRuns, self::ImportResults => InventoryPermission::ImportManage,
        };
    }

    public function requiresPricing(): bool
    {
        return in_array($this, [
            self::SupplierComparison,
            self::PriceHistory,
            self::PricingTiers,
            self::CustomerAssignments,
            self::FloorOverrides,
        ], true);
    }

    public function label(): string
    {
        if ($this === self::Reconciliation) {
            return 'Reconciliation';
        }

        return __('admin.inventory.reports.types.'.$this->value);
    }
}
