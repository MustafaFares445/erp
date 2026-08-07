<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeOnboardingService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
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
                ->title('Unable to create the employee')
                ->body($domainException->getMessage())
                ->send();

            $this->halt();
        }

        // @codeCoverageIgnoreStart
        // Unreachable in practice: halt() above always throws
        // Filament\Support\Exceptions\Halt, so control never falls through
        // the catch block. This return exists only to satisfy the method's
        // Model return type after that block.
        return new EmployeeProfile;
        // @codeCoverageIgnoreEnd
    }
}
