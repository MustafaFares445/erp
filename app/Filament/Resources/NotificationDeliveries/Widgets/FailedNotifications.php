<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationDeliveries\Widgets;

use App\Enums\NotificationDeliveryStatus;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Header card on the delivery history: business notifications that failed in
 * the last 24 hours, linking to the history filtered to failures for retry.
 */
final class FailedNotifications extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    protected function getStats(): array
    {
        $failed = NotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::Failed->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            Stat::make(__('admin.notification_deliveries.failed_last_day'), Number::format($failed))
                ->description($failed === 0
                    ? __('admin.notification_deliveries.failed_none')
                    : __('admin.notification_deliveries.failed_review'))
                ->icon($failed === 0 ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($failed === 0 ? 'success' : 'danger')
                ->url(NotificationDeliveryResource::getUrl('index', [
                    'filters' => ['status' => ['value' => NotificationDeliveryStatus::Failed->value]],
                ])),
        ];
    }
}
