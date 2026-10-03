<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PurchasePermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Widgets\PurchasingAttentionQueue;
use App\Filament\Widgets\PurchasingOpenStageChart;
use App\Filament\Widgets\PurchasingSpendTrend;
use App\Filament\Widgets\PurchasingStatistics;
use App\Filament\Widgets\PurchasingUpcomingReceipts;
use App\Models\Supplier;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Purchasing's module landing page: spend and backlog KPIs, spend trend
 * beside open orders by stage, then the attention queue beside upcoming
 * deliveries — narrowable to one supplier and gated the same as the
 * Purchasing navigation group itself (see {@see AdminModuleRegistry}).
 */
final class PurchasingDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.purchasing_dashboard');
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('supplierId')
                ->label(__('dashboards.purchasing.filters.supplier'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            PurchasingStatistics::class,
            [PurchasingSpendTrend::class, PurchasingOpenStageChart::class],
            [PurchasingAttentionQueue::class, PurchasingUpcomingReceipts::class],
        ];
    }
}
