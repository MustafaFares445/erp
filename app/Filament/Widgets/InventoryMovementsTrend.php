<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Models\InventoryMovement;
use App\Services\Support\ServiceRecordPartService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Inbound vs outbound movement quantity, trailing 30 days inclusive of today.
 *
 * Grouped in PHP with Carbon rather than a SQL date function — SQLite (the
 * test driver) has no `DATE_FORMAT` — mirroring {@see AccountingLedgerTrend}.
 * `InventoryMovement::quantity` is signed (see {@see ServiceRecordPartService}),
 * so direction is read straight off the sign rather than `movement_type`.
 */
final class InventoryMovementsTrend extends ChartWidget
{
    protected ?string $heading = null;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::MovementView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('admin.inventory.dashboard.movements_trend');
    }

    #[\Override]
    protected function getData(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $days = collect(range(29, 0))
            ->map(fn (int $offset): string => now()->subDays($offset)->format('Y-m-d'));

        $inbound = $days->mapWithKeys(fn (string $day): array => [$day => 0.0]);
        $outbound = $days->mapWithKeys(fn (string $day): array => [$day => 0.0]);

        $rows = InventoryMovement::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'quantity']);

        foreach ($rows as $row) {
            $day = Carbon::parse($row->created_at)->format('Y-m-d');
            $quantity = (float) $row->quantity;

            if (! $inbound->has($day)) {
                continue;
            }

            if ($quantity >= 0) {
                $inbound[$day] += $quantity;
            } else {
                $outbound[$day] += abs($quantity);
            }
        }

        return [
            'datasets' => [
                [
                    'label' => __('admin.inventory.dashboard.inbound'),
                    'data' => $inbound->values()->all(),
                ],
                [
                    'label' => __('admin.inventory.dashboard.outbound'),
                    'data' => $outbound->values()->all(),
                ],
            ],
            'labels' => $days->map(fn (string $day): string => Carbon::parse($day)->format('M j'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
