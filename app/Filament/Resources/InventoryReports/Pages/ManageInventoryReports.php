<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Pages;

use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportsTable;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ManageInventoryReports extends ManageRecords
{
    use RequestsInventoryExports;

    protected static string $resource = InventoryReportResource::class;

    #[\Override]
    public function table(Table $table): Table
    {
        return InventoryReportsTable::configure($table, $this->reportType(), $this->canViewPricing());
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        $tabs = [];

        foreach ($this->availableReports() as $type) {
            $tabs[$type->value] = Tab::make($type->label());
        }

        return $tabs;
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model> */
    #[\Override]
    protected function getTableQuery(): Builder
    {
        return app(InventoryReportService::class)->query($this->reportType(), $this->reportFilters());
    }

    #[\Override]
    public function updatedActiveTab(): void
    {
        $this->tableFilters = null;
        $this->resetTable();
    }

    public function isReport(InventoryReportType ...$types): bool
    {
        return in_array($this->reportType(), $types, true);
    }

    public function canViewPricing(): bool
    {
        return auth()->user()?->can(InventoryPermission::PricingView->value) ?? false;
    }

    /** @return list<InventoryReportType> */
    private function availableReports(): array
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(InventoryReportService::class)->availableReports($actor)
            : [];
    }

    private function reportType(): InventoryReportType
    {
        $available = $this->availableReports();
        $requested = is_string($this->activeTab)
            ? InventoryReportType::tryFrom($this->activeTab)
            : null;

        if ($requested instanceof InventoryReportType && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? InventoryReportType::Catalog;
    }

    /** @return array<string, mixed> */
    private function reportFilters(): array
    {
        $filters = [];

        foreach ($this->tableFilters ?? [] as $name => $state) {
            if (! is_array($state)) {
                continue;
            }

            if (array_key_exists('value', $state)) {
                $filters[$name] = $state['value'];

                continue;
            }

            foreach ($state as $key => $value) {
                if (is_string($key)) {
                    $filters[$key] = $value;
                }
            }
        }

        return $filters;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->inventoryExportAction(InventoryExportType::SupplierComparison),
            $this->inventoryExportAction(InventoryExportType::PriceHistory),
        ];
    }
}
