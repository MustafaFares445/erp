<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\User;
use App\Services\Accounting\TaxRegisterService;
use Filament\Widgets\ChartWidget;

/**
 * The selected window's deferred-versus-payable tax position as one
 * breakdown chart (WP-2.7, AC-06), so an accountant sees the figures
 * {@see ViewTaxRegister} proves without opening the full report.
 */
final class TaxPositionThisPeriod extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::TaxView->value);
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.accounting.charts.tax_position');
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();
        $figures = app(TaxRegisterService::class)->period($period->from, $period->to);

        return [
            'datasets' => [[
                'label' => __('dashboards.accounting.charts.tax_amount'),
                'data' => [
                    (float) $figures['output_tax_charged_deferred'],
                    (float) $figures['output_tax_recognised_payable'],
                    (float) $figures['output_tax_reversed'],
                    (float) $figures['input_tax_recognised'],
                    (float) $figures['net_position'],
                ],
                'backgroundColor' => ['#94a3b8', '#f59e0b', '#ef4444', '#3b82f6', '#22c55e'],
            ]],
            'labels' => [
                __('dashboards.accounting.tax.deferred'),
                __('dashboards.accounting.tax.payable'),
                __('dashboards.accounting.tax.reversed'),
                __('dashboards.accounting.tax.input'),
                __('dashboards.accounting.tax.net'),
            ],
        ];
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
