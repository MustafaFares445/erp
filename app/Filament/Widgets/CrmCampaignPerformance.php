<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CampaignSendStatus;
use App\Enums\CrmPermission;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CrmCampaignPerformance extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool { return auth()->user()?->can(CrmPermission::CampaignView->value) ?? false; }
    #[\Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Campaigns', (string) Campaign::query()->count()),
            Stat::make('Recipients sent', (string) CampaignRecipient::query()->where('send_status', CampaignSendStatus::Sent->value)->count()),
            Stat::make('Failed / suppressed', (string) CampaignRecipient::query()->whereIn('send_status', [CampaignSendStatus::Failed->value, CampaignSendStatus::Suppressed->value])->count()),
        ];
    }
}
