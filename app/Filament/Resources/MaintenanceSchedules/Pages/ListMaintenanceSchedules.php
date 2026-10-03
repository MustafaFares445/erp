<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Pages;

use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListMaintenanceSchedules extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = MaintenanceScheduleResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'support.maintenance-schedules';
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(MaintenanceScheduleResource::getUrl('calendar')),
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'due_soon' => Tab::make(__('Due Soon'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereDate('next_due_on', '>=', now()->toDateString())
                    ->whereDate('next_due_on', '<=', now()->addDays(14)->toDateString())),
            'overdue' => Tab::make(__('Overdue'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereDate('next_due_on', '<', now()->toDateString())),
            'inactive' => Tab::make(__('Inactive'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }
}
