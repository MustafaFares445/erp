<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CrmPermission;
use App\Filament\Widgets\CrmCustomerGrowthTrend;
use App\Filament\Widgets\CrmStatistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * CRM's module landing page, surfacing customer and pricing health at a
 * glance via {@see CrmStatistics} and {@see CrmCustomerGrowthTrend}.
 */
final class CrmDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(CrmPermission::CustomerView->value) ?? false;
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.crm_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            CrmStatistics::class,
            CrmCustomerGrowthTrend::class,
        ];
    }
}
