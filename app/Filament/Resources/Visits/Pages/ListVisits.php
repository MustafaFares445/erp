<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Enums\VisitStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Visits\VisitResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListVisits extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = VisitResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Schedule visit')),
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(VisitResource::getUrl('calendar')),
        ];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'employees.visits';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Default'))->icon(Heroicon::OutlinedQueueList),
            'mine' => Tab::make(__('My visits'))
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereHas(
                    'employee',
                    static fn (Builder $employee): Builder => $employee->where('user_id', auth()->id()),
                )),
            'planned' => Tab::make(__('Planned'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', VisitStatus::Scheduled->value)),
            'in_progress' => Tab::make(__('In progress'))
                ->icon(Heroicon::OutlinedPlayCircle)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', VisitStatus::InProgress->value)),
            'completed' => Tab::make(__('Completed'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', VisitStatus::Completed->value)),
        ];
    }
}
