<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryMovement;
use App\Support\QuantityFormatter;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Header card on the Quarantine ageing report tab. The report below is
 * lot-grain; this card ages every warehouse/variant quarantine balance,
 * untracked stock included, against a 30-day threshold.
 */
final class InventoryQuarantineAgeing extends StatsOverviewWidget
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
        $threshold = now()->subDays(30);

        $rows = InventoryConditionBalance::query()
            ->where('stock_condition', StockCondition::Quarantine->value)
            ->where('on_hand_base_quantity', '>', 0)
            ->addSelect([
                'oldest_quarantine_at' => InventoryMovement::query()
                    ->select('created_at')
                    ->whereColumn(
                        'inventory_movements.product_variant_id',
                        'inventory_condition_balances.product_variant_id',
                    )
                    ->whereColumn(
                        'inventory_movements.warehouse_id',
                        'inventory_condition_balances.warehouse_id',
                    )
                    ->where('stock_condition_to', StockCondition::Quarantine->value)
                    ->oldest('created_at')
                    ->limit(1),
            ])
            ->get();

        $aged = $rows->filter(static function (InventoryConditionBalance $balance) use ($threshold): bool {
            $raw = $balance->getAttribute('oldest_quarantine_at');
            $enteredAt = is_string($raw) && $raw !== ''
                ? CarbonImmutable::parse($raw)
                : ($balance->created_at === null ? null : CarbonImmutable::instance($balance->created_at));

            return $enteredAt?->lessThanOrEqualTo($threshold) ?? false;
        });

        $quantity = $aged->sum(
            static fn (InventoryConditionBalance $balance): float => (float) $balance->on_hand_base_quantity,
        );

        return [
            Stat::make(__('admin.inventory.dashboard.quarantine_aged_count'), Number::format($aged->count()))
                ->description(__('admin.inventory.dashboard.quarantine_aged_quantity', [
                    'quantity' => QuantityFormatter::display($quantity),
                ]))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color($aged->isEmpty() ? 'success' : 'warning'),
        ];
    }
}
