<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CrmLeadFunnel extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::LeadView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $counts = Lead::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return array_map(
            function (LeadStatus $status) use ($counts): Stat {
                $count = $counts->get($status->value, 0);

                return Stat::make(
                    str($status->value)->headline()->toString(),
                    is_numeric($count) ? (string) (int) $count : '0',
                )->color($status->color());
            },
            LeadStatus::cases(),
        );
    }
}
