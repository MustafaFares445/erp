<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CrmDormantLeads extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool { return auth()->user()?->can(CrmPermission::LeadView->value) ?? false; }
    #[\Override]
    protected function getStats(): array
    {
        $count = Lead::query()->dormant()->count();
        return [Stat::make('Dormant leads (14+ days)', (string) $count)->description('Open leads with no recent interaction.')->color($count > 0 ? 'warning' : 'success')->url(LeadResource::getUrl())];
    }
}
