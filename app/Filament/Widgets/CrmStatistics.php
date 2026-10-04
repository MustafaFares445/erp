<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Enums\LeadStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\CustomerProfile;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRM's headline cards: customer acquisition (new and active customers) and
 * the lead pipeline (new leads and how many of them converted), each scoped
 * to the selected window and, for leads, the selected lead source. A user
 * sees only the cards their CRM permissions cover.
 */
final class CrmStatistics extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    use BuildsTrendStats;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        if (self::canViewCustomers()) {
            return true;
        }

        return self::canViewLeads();
    }

    #[\Override]
    protected function getStats(): array
    {
        return [
            ...(self::canViewCustomers() ? $this->customerStats() : []),
            ...(self::canViewLeads() ? $this->leadStats() : []),
        ];
    }

    /** @return list<Stat> */
    private function customerStats(): array
    {
        $period = $this->dashboardPeriod();
        $current = CustomerProfile::query()->whereBetween('created_at', [$period->from, $period->to])->pluck('created_at');
        $previous = CustomerProfile::query()->whereBetween('created_at', [$period->previousFrom, $period->previousTo])->count();
        $active = CustomerProfile::query()->where('is_active', true)->count();

        return [
            $this->trendStat(
                __('dashboards.crm.kpis.new_customers'),
                (string) $current->count(),
                $current->count(),
                $previous,
                $period->countSeries($current),
                Heroicon::OutlinedUserPlus,
                CustomerResource::getUrl(),
            ),
            Stat::make(__('dashboards.crm.kpis.active_customers'), (string) $active)
                ->description(__('dashboards.crm.kpis.total_customers', ['count' => CustomerProfile::query()->count()]))
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(CustomerResource::getUrl()),
        ];
    }

    /** @return list<Stat> */
    private function leadStats(): array
    {
        $period = $this->dashboardPeriod();
        $current = $this->leadsCreatedBetween($period->from, $period->to)->get(['created_at', 'status']);
        $previous = $this->leadsCreatedBetween($period->previousFrom, $period->previousTo)->get(['status']);

        $conversion = self::conversionPercent($current->pluck('status')->all());
        $previousConversion = self::conversionPercent($previous->pluck('status')->all());

        return [
            $this->trendStat(
                __('dashboards.crm.kpis.new_leads'),
                (string) $current->count(),
                $current->count(),
                $previous->count(),
                $period->countSeries($current->pluck('created_at')),
                Heroicon::OutlinedSparkles,
                LeadResource::getUrl(),
            ),
            $this->trendStat(
                __('dashboards.crm.kpis.lead_conversion'),
                $conversion === null ? '—' : number_format($conversion, 1).'%',
                $conversion ?? 0.0,
                $previousConversion ?? 0.0,
                icon: Heroicon::OutlinedArrowTrendingUp,
                url: LeadResource::getUrl(),
            ),
        ];
    }

    /** @return Builder<Lead> */
    private function leadsCreatedBetween(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($this->dashboardStringFilter('leadSource'), static fn (Builder $query, string $source): Builder => $query->where('source', $source));
    }

    /** @param  array<mixed>  $statuses */
    private static function conversionPercent(array $statuses): ?float
    {
        if ($statuses === []) {
            return null;
        }

        $converted = count(array_filter($statuses, static fn (mixed $status): bool => $status === LeadStatus::Converted));

        return round($converted / count($statuses) * 100, 1);
    }

    private static function canViewCustomers(): bool
    {
        return auth()->user()?->can(CrmPermission::CustomerView->value) ?? false;
    }

    private static function canViewLeads(): bool
    {
        return auth()->user()?->can(CrmPermission::LeadView->value) ?? false;
    }
}
