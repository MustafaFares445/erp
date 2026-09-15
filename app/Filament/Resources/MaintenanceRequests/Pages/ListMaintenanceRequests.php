<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListMaintenanceRequests extends ListRecords
{
    protected static string $resource = MaintenanceRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::Open->value)),
            'in_progress' => Tab::make('In Progress')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::InProgress->value)),
            'unbilled' => Tab::make('Needs Billing')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', MaintenanceStatus::Closed->value)
                    ->where('billing_type', MaintenanceBillingType::Unbilled->value)),
            'warranty_covered' => Tab::make('Warranty Covered')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('billing_type', MaintenanceBillingType::WarrantyCovered->value)),
            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::Closed->value)),
        ];
    }
}
