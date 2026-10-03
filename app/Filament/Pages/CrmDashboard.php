<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CrmPermission;
use App\Enums\LeadSource;
use App\Filament\Widgets\CrmCampaignPerformance;
use App\Filament\Widgets\CrmCustomerGrowthTrend;
use App\Filament\Widgets\CrmDormantLeads;
use App\Filament\Widgets\CrmLeadFunnel;
use App\Filament\Widgets\CrmStatistics;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * CRM's module landing page: customer and lead KPIs, customer growth beside
 * where new leads stand, then the dormant-lead queue beside campaign
 * results.
 */
final class CrmDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user?->can(CrmPermission::CustomerView->value) ?? false) {
            return true;
        }
        if ($user?->can(CrmPermission::LeadView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(CrmPermission::CampaignView->value) ?? false);
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.crm_dashboard');
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('leadSource')
                ->label(__('dashboards.crm.filters.lead_source'))
                ->options(collect(LeadSource::cases())->mapWithKeys(static fn (LeadSource $source): array => [$source->value => $source->label()])->all())
                ->native(false),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            CrmStatistics::class,
            [CrmCustomerGrowthTrend::class, CrmLeadFunnel::class],
            [CrmDormantLeads::class, CrmCampaignPerformance::class],
        ];
    }
}
