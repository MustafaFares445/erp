<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAppointments\Pages;

use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Services\Support\ServiceAppointmentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Fluent;
use LogicException;
use Throwable;

final class ListServiceAppointments extends ListRecords
{
    protected static string $resource = ServiceAppointmentResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return __('Field Service Dispatch');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scheduleAppointment')
                ->label(__('Schedule appointment'))
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->authorize('create', ServiceAppointment::class)
                ->schema([
                    Select::make('maintenance_task_id')
                        ->label(__('Service record'))
                        ->options(fn (): array => MaintenanceTask::query()
                            ->whereNotIn('status', ['closed', 'cancelled'])
                            ->with('maintenanceRecord.customer:id,company_name')
                            ->orderByDesc('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(static fn (MaintenanceTask $task): array => [
                                $task->id => sprintf(
                                    '#%d — %s — %s',
                                    $task->id,
                                    $task->maintenanceRecord?->customer->company_name ?? __('Unknown customer'),
                                    $task->title,
                                ),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('employee_id')
                        ->label(__('Technician'))
                        ->options(fn (): array => EmployeeProfile::query()
                            ->where('is_active', true)
                            ->with('user:id,name')
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(static fn (EmployeeProfile $employee): array => [
                                $employee->id => (string) ($employee->user->name ?? $employee->employee_code),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('customer_delivery_address_id')
                        ->label(__('Service address'))
                        ->helperText(__('Leave empty to use the customer profile address.'))
                        ->options(fn (): array => CustomerDeliveryAddress::query()
                            ->where('is_active', true)
                            ->orderBy('customer_profile_id')
                            ->get()
                            ->mapWithKeys(static fn (CustomerDeliveryAddress $address): array => [
                                $address->id => mb_trim(($address->label ?: __('Address')).' — '.($address->address ?: $address->city)),
                            ])
                            ->all())
                        ->searchable(),
                    DateTimePicker::make('scheduled_start_at')->label(__('Start'))->seconds(false)->required(),
                    DateTimePicker::make('scheduled_end_at')->label(__('End'))->seconds(false)->required(),
                    Textarea::make('notes')->rows(3)->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        $input = new Fluent($data);
                        $task = MaintenanceTask::query()->findOrFail($input->integer('maintenance_task_id'));
                        $employee = EmployeeProfile::query()->findOrFail($input->integer('employee_id'));
                        $addressId = $input->get('customer_delivery_address_id');
                        $address = is_numeric($addressId)
                            ? CustomerDeliveryAddress::query()->findOrFail((int) $addressId)
                            : null;
                        $notes = $input->get('notes');

                        app(ServiceAppointmentService::class)->createScheduled(
                            $task,
                            $employee,
                            CarbonImmutable::parse($input->string('scheduled_start_at')->toString()),
                            CarbonImmutable::parse($input->string('scheduled_end_at')->toString()),
                            $address,
                            $this->currentActor(),
                            is_string($notes) ? $notes : null,
                        );

                        Notification::make()->success()->title(__('Appointment scheduled'))->send();
                    } catch (Throwable $throwable) {
                        Notification::make()->danger()->title(__('Unable to schedule appointment'))->body(__($throwable->getMessage()))->send();
                    }
                }),
        ];
    }

    private function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
