<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAppointments\Tables;

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Enums\ServiceAppointmentStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmployeeProfile;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentCalibrationMeasurement;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Services\Support\CalibrationProgressResolver;
use App\Services\Support\InstallationProgressResolver;
use App\Services\Support\ServiceAppointmentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Fluent;
use Illuminate\Support\HtmlString;
use LogicException;
use Throwable;

final class ServiceAppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_start_at')
            ->columns([
                TextColumn::make('id')->label(__('Visit #'))->formatStateUsing(static fn (int $state): string => '#'.$state),
                TextColumn::make('scheduled_start_at')->label(__('Start'))->dateTime()->sortable(),
                TextColumn::make('scheduled_end_at')->label(__('End'))->dateTime()->sortable(),
                TextColumn::make('employee.user.name')->label(__('Technician'))->placeholder(__('Unassigned'))->searchable(),
                TextColumn::make('serviceRecord.maintenanceRecord.customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('serviceRecord.title')->label(__('Service record'))->limit(32),
                TextColumn::make('purpose')
                    ->label(__('Purpose'))
                    ->badge()
                    ->state(static fn (ServiceAppointment $record): ?string => $record->purpose()?->label())
                    ->color(static fn (ServiceAppointment $record): string => match ($record->purpose()) {
                        MaintenanceKind::Installation => 'info',
                        MaintenanceKind::Calibration => 'warning',
                        default => 'gray',
                    })
                    ->description(static fn (ServiceAppointment $record): ?string => self::installationSummary($record) ?? self::calibrationSummary($record)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (ServiceAppointmentStatus $state): string => $state->label())
                    ->color(static fn (ServiceAppointmentStatus $state): string => $state->color()),
                TextColumn::make('address_snapshot')
                    ->label(__('Location'))
                    ->state(static fn (ServiceAppointment $record): string => self::addressLabel($record))
                    ->wrap(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ServiceAppointmentStatus::cases())
                        ->mapWithKeys(static fn (ServiceAppointmentStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('employee_id')
                    ->label(__('Technician'))
                    ->options(fn (): array => EmployeeProfile::query()
                        ->where('is_active', true)
                        ->with('user:id,name')
                        ->get()
                        ->mapWithKeys(static fn (EmployeeProfile $employee): array => [
                            $employee->id => (string) ($employee->user->name ?? $employee->employee_code),
                        ])
                        ->all()),
            ])
            ->recordActions([
                Action::make('installationContext')
                    ->label(__('Installation'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->modalHeading(__('Installation context'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->visible(static fn (ServiceAppointment $record): bool => $record->isInstallation() && (bool) config('support.equipment_installation_enabled', true))
                    ->authorize(static fn (): bool => self::currentActor()->can('viewAny', EquipmentInstallation::class))
                    ->modalContent(static function (ServiceAppointment $record): Htmlable {
                        $maintenance = $record->serviceRecord->maintenanceRecord ?? throw new LogicException('Installation visits reference maintenance work.');

                        return new HtmlString(view('filament.support.installation-context', [
                            'record' => $maintenance,
                            'progress' => app(InstallationProgressResolver::class)->resolve($maintenance),
                            'url' => MaintenanceRequestResource::getUrl('view', ['record' => $maintenance]),
                        ])->render());
                    }),
                Action::make('calibrationContext')
                    ->label(__('Calibration'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->modalHeading(__('Calibration context'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->visible(static fn (ServiceAppointment $record): bool => $record->isCalibration() && (bool) config('support.calibration_enabled', true))
                    ->authorize(static fn (): bool => self::currentActor()->can('viewAny', EquipmentCalibration::class))
                    ->modalContent(static function (ServiceAppointment $record): Htmlable {
                        $maintenance = $record->serviceRecord->maintenanceRecord ?? throw new LogicException('Calibration visits reference maintenance work.');

                        return new HtmlString(view('filament.support.calibration-context', [
                            'record' => $maintenance,
                            'progress' => app(CalibrationProgressResolver::class)->resolve($maintenance),
                            'url' => MaintenanceRequestResource::getUrl('view', ['record' => $maintenance]),
                        ])->render());
                    }),
                Action::make('reschedule')
                    ->label(__('Reschedule'))
                    ->icon('heroicon-o-calendar-days')
                    ->authorize('update')
                    ->visible(static fn (ServiceAppointment $record): bool => ! in_array($record->status, [
                        ServiceAppointmentStatus::Completed,
                        ServiceAppointmentStatus::Cancelled,
                    ], true))
                    ->fillForm(static fn (ServiceAppointment $record): array => [
                        'employee_id' => $record->employee_id,
                        'scheduled_start_at' => $record->scheduled_start_at,
                        'scheduled_end_at' => $record->scheduled_end_at,
                        'notes' => $record->notes,
                    ])
                    ->schema(self::rescheduleSchema())
                    ->action(static function (ServiceAppointment $record, array $data): void {
                        self::run(function () use ($record, $data): void {
                            $input = new Fluent($data);
                            $employee = EmployeeProfile::query()->findOrFail($input->integer('employee_id'));
                            $addressId = $input->get('customer_delivery_address_id');
                            $address = is_numeric($addressId)
                                ? CustomerDeliveryAddress::query()->findOrFail((int) $addressId)
                                : null;
                            $notes = $input->get('notes');

                            app(ServiceAppointmentService::class)->reschedule(
                                $record,
                                $employee,
                                CarbonImmutable::parse($input->string('scheduled_start_at')->toString()),
                                CarbonImmutable::parse($input->string('scheduled_end_at')->toString()),
                                $address,
                                self::currentActor(),
                                is_string($notes) ? $notes : null,
                            );
                        }, __('Appointment rescheduled'), __('Unable to reschedule appointment'));
                    }),
                ActionGroup::make([
                    Action::make('dispatch')
                        ->label(__('Dispatch'))
                        ->authorize('update')
                        ->visible(static fn (ServiceAppointment $record): bool => $record->status === ServiceAppointmentStatus::Planned)
                        ->action(static fn (ServiceAppointment $record) => self::run(
                            static fn () => app(ServiceAppointmentService::class)->dispatch($record, self::currentActor()),
                            __('Appointment dispatched'),
                            __('Unable to dispatch appointment'),
                        )),
                    Action::make('enRoute')
                        ->label(__('Mark en route'))
                        ->authorize('execute')
                        ->visible(static fn (ServiceAppointment $record): bool => $record->status === ServiceAppointmentStatus::Dispatched)
                        ->action(static fn (ServiceAppointment $record) => self::run(
                            static fn () => app(ServiceAppointmentService::class)->markEnRoute($record, self::currentActor()),
                            __('Technician marked en route'),
                            __('Unable to update appointment'),
                        )),
                    Action::make('checkIn')
                        ->label(__('Check in'))
                        ->authorize('execute')
                        ->visible(static fn (ServiceAppointment $record): bool => in_array($record->status, [
                            ServiceAppointmentStatus::Dispatched,
                            ServiceAppointmentStatus::EnRoute,
                        ], true))
                        ->schema([
                            TextInput::make('latitude')->numeric(),
                            TextInput::make('longitude')->numeric(),
                        ])
                        ->action(static fn (ServiceAppointment $record, array $data) => self::run(
                            static fn () => app(ServiceAppointmentService::class)->checkIn(
                                $record,
                                self::currentActor(),
                                is_numeric($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
                                is_numeric($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
                            ),
                            __('Checked in'),
                            __('Unable to check in'),
                        )),
                    Action::make('complete')
                        ->label(__('Complete visit'))
                        ->authorize('execute')
                        ->visible(static fn (ServiceAppointment $record): bool => $record->status === ServiceAppointmentStatus::OnSite)
                        ->schema([
                            TextInput::make('customer_signature_name')->label(__('Customer signature name'))->required(),
                            TextInput::make('latitude')->numeric(),
                            TextInput::make('longitude')->numeric(),
                            Textarea::make('notes')->rows(3),
                        ])
                        ->action(static fn (ServiceAppointment $record, array $data) => self::run(
                            static fn () => app(ServiceAppointmentService::class)->complete(
                                $record,
                                self::currentActor(),
                                new Fluent($data)->string('customer_signature_name')->toString(),
                                is_numeric($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
                                is_numeric($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
                                is_string($data['notes'] ?? null) ? $data['notes'] : null,
                            ),
                            __('Visit completed'),
                            __('Unable to complete visit'),
                        )),
                    Action::make('cancel')
                        ->label(__('Cancel visit'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('update')
                        ->visible(static fn (ServiceAppointment $record): bool => ! in_array($record->status, [
                            ServiceAppointmentStatus::Completed,
                            ServiceAppointmentStatus::Cancelled,
                        ], true))
                        ->action(static fn (ServiceAppointment $record) => self::run(
                            static fn () => app(ServiceAppointmentService::class)->cancel($record, self::currentActor()),
                            __('Visit cancelled'),
                            __('Unable to cancel visit'),
                        )),
                ]),
            ]);
    }

    /** Compact installation progress for a dispatch row, from eager-loaded checks only. */
    private static function installationSummary(ServiceAppointment $record): ?string
    {
        if (! $record->isInstallation()) {
            return null;
        }

        $installation = $record->serviceRecord?->maintenanceRecord?->installation;

        if (! $installation instanceof EquipmentInstallation) {
            return __('Installation not started');
        }

        $done = $installation->checks->filter(static fn (EquipmentInstallationCheck $check): bool => $check->result->satisfiesCommissioning())->count();

        return __(':done / :total checks · :status', [
            'done' => $done,
            'total' => $installation->checks->count(),
            'status' => $installation->commissioning_status->label(),
        ]);
    }

    private static function calibrationSummary(ServiceAppointment $record): ?string
    {
        if (! $record->isCalibration()) {
            return null;
        }

        $calibration = $record->serviceRecord?->maintenanceRecord?->calibration;

        if (! $calibration instanceof EquipmentCalibration) {
            return __('Calibration not started');
        }

        $measured = $calibration->measurements->filter(static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->isRecorded())->count();

        return __(':done / :total measurements · :status', [
            'done' => $measured,
            'total' => $calibration->measurements->count(),
            'status' => $calibration->result instanceof CalibrationResult ? $calibration->result->label() : __('In progress'),
        ]);
    }

    /** @return list<Component> */
    private static function rescheduleSchema(): array
    {
        return [
            Select::make('employee_id')
                ->label(__('Technician'))
                ->options(fn (): array => EmployeeProfile::query()
                    ->where('is_active', true)
                    ->with('user:id,name')
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
                    ->get()
                    ->mapWithKeys(static fn (CustomerDeliveryAddress $address): array => [
                        $address->id => mb_trim(($address->label ?: __('Address')).' — '.($address->address ?: $address->city)),
                    ])
                    ->all())
                ->searchable(),
            DateTimePicker::make('scheduled_start_at')->label(__('Start'))->seconds(false)->required(),
            DateTimePicker::make('scheduled_end_at')->label(__('End'))->seconds(false)->required(),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ];
    }

    private static function addressLabel(ServiceAppointment $record): string
    {
        $snapshot = $record->address_snapshot;

        return implode(', ', array_filter([
            is_string($snapshot['address'] ?? null) ? $snapshot['address'] : null,
            is_string($snapshot['city'] ?? null) ? $snapshot['city'] : null,
        ])) ?: __('No address');
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }

    private static function run(callable $callback, string $success, string $failure): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (Throwable $throwable) {
            Notification::make()->danger()->title($failure)->body(__($throwable->getMessage()))->send();
        }
    }
}
