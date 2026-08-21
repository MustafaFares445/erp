<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryExportType: string
{
    case Catalog = 'catalog';
    case StockLevels = 'stock_levels';
    case Movements = 'movements';
    case Devices = 'devices';
    case ExpiryLots = 'expiry_lots';
    case SupplierComparison = 'supplier_comparison';
    case PriceHistory = 'price_history';
    case PricingTiers = 'pricing_tiers';
    case ImportResults = 'import_results';

    public function primaryReport(): InventoryReportType
    {
        return match ($this) {
            self::Catalog => InventoryReportType::Catalog,
            self::StockLevels => InventoryReportType::StockLevels,
            self::Movements => InventoryReportType::Movements,
            self::Devices => InventoryReportType::Devices,
            self::ExpiryLots => InventoryReportType::ExpiryLots,
            self::SupplierComparison => InventoryReportType::SupplierComparison,
            self::PriceHistory => InventoryReportType::PriceHistory,
            self::PricingTiers => InventoryReportType::PricingTiers,
            self::ImportResults => InventoryReportType::ImportResults,
        };
    }

    /** @return list<InventoryReportType> */
    public function reports(): array
    {
        return match ($this) {
            self::PricingTiers => [
                InventoryReportType::PricingTiers,
                InventoryReportType::CustomerAssignments,
                InventoryReportType::FloorOverrides,
            ],
            self::ImportResults => [
                InventoryReportType::ImportRuns,
                InventoryReportType::ImportResults,
            ],
            default => [$this->primaryReport()],
        };
    }

    public function requiresPricing(): bool
    {
        return $this->primaryReport()->requiresPricing();
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->primaryReport()->label();
        }

        return $options;
    }
}
