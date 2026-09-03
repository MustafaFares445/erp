<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

final class ReconciliationStatus extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(InventoryPermission::StockView->value) ?? false) {
            return true;
        }
        return (bool) ($user?->can(InventoryPermission::MovementView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $latest = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots->value)
            ->latest('finished_at')
            ->latest('id')
            ->first();

        if (! $latest instanceof ReconciliationRun) {
            return [
                Stat::make('Inventory reconciliation', 'Not run')
                    ->description('No persisted canonical inventory reconciliation is available yet.')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('warning'),
            ];
        }

        $runs = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots->value)
            ->where('finished_at', $latest->finished_at)
            ->orderBy('invariant')
            ->get();

        return $runs->map(static function (ReconciliationRun $run): Stat {
            $finishedAt = $run->finished_at?->format('Y-m-d H:i:s') ?? 'unknown time';

            return Stat::make(
                Str::headline($run->invariant),
                $run->passed ? 'Pass' : 'Fail',
            )
                ->description($run->passed
                    ? 'No divergence detected · '.$finishedAt
                    : sprintf('%d divergence(s) · %s', $run->divergence_count, $finishedAt))
                ->icon($run->passed ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedExclamationTriangle)
                ->color($run->passed ? 'success' : 'danger');
        })->all();
    }
}
