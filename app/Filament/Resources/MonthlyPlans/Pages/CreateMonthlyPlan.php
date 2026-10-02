<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Pages;

use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Services\Employees\SalesPlanService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateMonthlyPlan extends CreateRecord
{
    protected static string $resource = MonthlyPlanResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SalesPlanService::class)->create($data);
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title(__('Unable to create the plan'))
                ->body($domainException->getMessage())
                ->send();

            throw new Halt;
        }

    }
}
