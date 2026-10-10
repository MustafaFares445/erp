<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(TaskResource::getUrl('calendar')),
        ];
    }
}
