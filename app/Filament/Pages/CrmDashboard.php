<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CrmPermission;
use App\Filament\Widgets\CrmCampaignPerformance;
use App\Filament\Widgets\CrmCustomerGrowthTrend;
use App\Filament\Widgets\CrmDormantLeads;
use App\Filament\Widgets\CrmLeadFunnel;
use App\Filament\Widgets\CrmStatistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class CrmDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can(CrmPermission::CustomerView->value) ?? false)
            || ($user?->can(CrmPermission::LeadView->value) ?? false)
            || ($user?->can(CrmPermission::CampaignView->value) ?? false);
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
            CrmLeadFunnel::class,
            CrmDormantLeads::class,
            CrmCampaignPerformance::class,
            CrmCustomerGrowthTrend::class,
        ];
    }
}
