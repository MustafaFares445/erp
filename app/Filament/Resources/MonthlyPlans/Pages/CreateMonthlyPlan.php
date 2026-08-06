<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Pages;

use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
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
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Unable to create the plan')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }

        return new SalesPlan;
    }
}
