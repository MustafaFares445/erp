<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Header card on the Reconciliation report tab: the verdict of the latest
 * inventory-lot reconciliation run. The per-invariant rows are in the table
 * below it.
 */
final class ReconciliationStatus extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $label = __('admin.inventory.reports.reconciliation.latest_run');

        $latest = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots->value)
            ->latest('finished_at')
            ->latest('id')
            ->first();

        if (! $latest instanceof ReconciliationRun) {
            return [
                Stat::make($label, __('admin.inventory.reports.reconciliation.not_run'))
                    ->description(__('admin.inventory.reports.reconciliation.never_run_description'))
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('warning'),
            ];
        }

        $runs = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots->value)
            ->where('finished_at', $latest->finished_at)
            ->get();

        $failed = $runs->reject(static fn (ReconciliationRun $run): bool => $run->passed);
        $finished = $latest->finished_at->diffForHumans();

        if ($failed->isEmpty()) {
            return [
                Stat::make($label, __('admin.inventory.reports.reconciliation.pass'))
                    ->description(__('admin.inventory.reports.reconciliation.all_checks_passed', [
                        'checks' => Number::format($runs->count()),
                        'finished' => $finished,
                    ]))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success'),
            ];
        }

        return [
            Stat::make($label, __('admin.inventory.reports.reconciliation.fail'))
                ->description(__('admin.inventory.reports.reconciliation.checks_failed', [
                    'failed' => Number::format($failed->count()),
                    'checks' => Number::format($runs->count()),
                    'divergences' => Number::format($failed->sum(static fn (ReconciliationRun $run): int => $run->divergence_count)),
                    'finished' => $finished,
                ]))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
        ];
    }
}
