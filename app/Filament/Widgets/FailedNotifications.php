<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\NotificationDeliveryStatus;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class FailedNotifications extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    protected function getStats(): array
    {
        $failed = NotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::Failed->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            Stat::make('Failed notifications (24h)', $failed)
                ->description($failed === 0
                    ? 'No failed business notifications in the last 24 hours.'
                    : 'Open delivery history to inspect or retry failures.')
                ->icon($failed === 0 ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($failed === 0 ? 'success' : 'danger')
                ->url(NotificationDeliveryResource::getUrl()),
        ];
    }
}
