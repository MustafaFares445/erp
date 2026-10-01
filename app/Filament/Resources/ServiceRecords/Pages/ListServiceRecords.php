<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Enums\MaintenanceStatus;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListServiceRecords extends ListRecords
{
    protected static string $resource = ServiceRecordResource::class;

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::Open->value)),
            'in_progress' => Tab::make(__('In Progress'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::InProgress->value)),
            'this_month' => Tab::make(__('This Month'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])),
            'completed' => Tab::make(__('Completed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::Closed->value)),
        ];
    }
}
