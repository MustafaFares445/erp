<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Pages;

use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditMonthlyPlan extends EditRecord
{
    protected static string $resource = MonthlyPlanResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof SalesPlan) {
            throw new LogicException('Expected a SalesPlan record.');
        }

        try {
            return app(SalesPlanService::class)->update($record, $data);
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Unable to update the plan')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }

        return $record;
    }
}
