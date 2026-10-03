<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListMaintenanceRequests extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = MaintenanceRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'support.maintenance-requests';
    }

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
            'unbilled' => Tab::make(__('Needs Billing'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', MaintenanceStatus::Closed->value)
                    ->where('billing_type', MaintenanceBillingType::Unbilled->value)),
            'warranty_covered' => Tab::make(__('Warranty Covered'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('billing_type', MaintenanceBillingType::WarrantyCovered->value)),
            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MaintenanceStatus::Closed->value)),
        ];
    }
}
