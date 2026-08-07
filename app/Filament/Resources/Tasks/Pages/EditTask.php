<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\PlanTask;
use App\Services\Employees\PlanTaskService;
use DomainException;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PlanTask) {
            throw new LogicException('Expected a PlanTask record.');
        }

        try {
            return app(PlanTaskService::class)->update($record, $data);
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title('Unable to update the task')
                ->body($domainException->getMessage())
                ->send();

            $this->halt();
        }

        return $record;
    }
}
