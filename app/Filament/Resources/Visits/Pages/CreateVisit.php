<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Services\Employees\VisitSchedulingService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateVisit extends CreateRecord
{
    protected static string $resource = VisitResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $overrideConflict = (bool) ($data['override_conflict'] ?? false);
        $overrideReason = is_string($data['override_reason'] ?? null) ? $data['override_reason'] : null;

        unset($data['override_conflict'], $data['override_reason']);

        try {
            return app(VisitSchedulingService::class)->schedule($data, $overrideConflict, $overrideReason);
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title(__('Unable to schedule the visit'))
                ->body($domainException->getMessage())
                ->send();

            throw new Halt($domainException->getMessage(), $domainException->getCode(), $domainException);
        }
    }
}
