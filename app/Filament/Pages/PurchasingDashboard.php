<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PurchasePermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Widgets\PurchasingSpendTrend;
use App\Filament\Widgets\PurchasingStatistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Purchasing's module landing page: open-order/approval/confirmation counts
 * plus the trailing six months of PO spend, gated the same as the
 * Purchasing navigation group itself (see
 * {@see AdminModuleRegistry}).
 */
final class PurchasingDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.purchasing_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            PurchasingStatistics::class,
            PurchasingSpendTrend::class,
        ];
    }
}
