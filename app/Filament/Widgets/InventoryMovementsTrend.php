<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Filament\Support\IerpColors;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryMovement;
use App\Services\Support\ServiceRecordPartService;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbound vs outbound movement quantity per bucket of the selected window,
 * optionally for one warehouse.
 *
 * `InventoryMovement::quantity` is signed (see {@see ServiceRecordPartService}),
 * so direction is read straight off the sign rather than `movement_type`.
 */
final class InventoryMovementsTrend extends ChartWidget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::MovementView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.inventory.charts.movements');
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        $rows = InventoryMovement::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('warehouse_id', $warehouseId))
            ->get(['created_at', 'quantity']);

        $inbound = $rows->filter(static fn (InventoryMovement $row): bool => (float) $row->quantity >= 0)
            ->map(static fn (InventoryMovement $row): array => [$row->created_at, (float) $row->quantity]);
        $outbound = $rows->filter(static fn (InventoryMovement $row): bool => (float) $row->quantity < 0)
            ->map(static fn (InventoryMovement $row): array => [$row->created_at, abs((float) $row->quantity)]);

        return [
            'datasets' => [
                [
                    'label' => __('admin.inventory.dashboard.inbound'),
                    'data' => $period->sumSeries($inbound),
                    'borderColor' => IerpColors::CHART_SUCCESS,
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('admin.inventory.dashboard.outbound'),
                    'data' => $period->sumSeries($outbound),
                    'borderColor' => IerpColors::CHART_DANGER,
                    'backgroundColor' => 'transparent',
                ],
            ],
            'labels' => $period->labels(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
