<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\NotificationDeliveryStatus;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Services\Notifications\NotificationDeliveryVolumeReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Makes notification noise measurable (WP-4.4, XC-03): a 7-day breakdown by
 * outcome, so a growing suppressed/deferred count is visible rather than
 * anecdotal.
 */
final class NotificationVolumeReport extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    protected function getStats(): array
    {
        $totals = app(NotificationDeliveryVolumeReportService::class)
            ->summarize(now()->subDays(7), now())
            ->groupBy('status')
            ->map(fn (Collection $rows): int => $rows->sum(fn (object $row): int => $row->total));

        return array_map(
            fn (NotificationDeliveryStatus $status): Stat => Stat::make(
                $status->label().' (7d)',
                $totals->get($status->value, 0),
            )->url(NotificationDeliveryResource::getUrl()),
            NotificationDeliveryStatus::cases(),
        );
    }
}
