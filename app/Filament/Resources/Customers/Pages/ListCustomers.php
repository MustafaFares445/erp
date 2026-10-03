<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\CustomerApprovalStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListCustomers extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = CustomerResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'crm.customers';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Default'))->icon(Heroicon::OutlinedQueueList),
            'pending_approval' => Tab::make(__('Pending approval'))
                ->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('approval_status', CustomerApprovalStatus::Pending->value)),
            'approved' => Tab::make(__('Approved'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('approval_status', CustomerApprovalStatus::Approved->value)),
            'inactive' => Tab::make(__('Inactive'))
                ->icon(Heroicon::OutlinedPause)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }
}
