<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\Employees\EmployeeOnboardingService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EmployeeOnboardingService::class)->onboard($data);
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title(__('Unable to create the employee'))
                ->body($domainException->getMessage())
                ->send();

            throw new Halt($domainException->getMessage(), $domainException->getCode(), $domainException);
        }

    }
}
