<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\CalibrationResult;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\ServiceAppointmentStatus;
use App\Models\EmployeeProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\MaintenanceScheduleService;
use App\Services\Support\ServiceAppointmentService;
use App\Services\Support\ServiceRecordService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Deterministic dental-laboratory calibration scenarios, run through the same
 * domain services as the UI: a passed sintering-furnace calibration with a
 * certificate and a six-monthly calibration schedule, and a failed
 * handpiece calibration that raises a follow-up repair request. Re-running
 * skips whatever already exists.
 */
final class DemoCalibrationSeeder extends DemoSeeder
{
    public const string Marker = '[DEMO-CALIB]';

    public const string PassedCertificate = 'CAL-2026-0001';

    protected function seed(DemoContext $context): void
    {
        $manager = $context->actor('support_manager');
        $technician = EmployeeProfile::query()->where('email', 'demo.support.agent1@ierp.test')->firstOrFail();
        $technicianUser = $technician->user;

        if (! $technicianUser instanceof User) {
            throw new LogicException('The demo field technician has no login.');
        }

        $furnace = SerializedInventoryUnit::query()
            ->where('serial_number', 'like', 'SN021-FURNACE%')
            ->where('custody_type', 'customer')
            ->first();

        if ($furnace instanceof SerializedInventoryUnit) {
            $this->seedPassedFurnaceCalibration($context, $manager, $technician, $technicianUser, $furnace);
        }

        $handpiece = SerializedInventoryUnit::query()
            ->where('serial_number', 'like', 'SN019-HANDPIECE%')
            ->where('custody_type', 'customer')
            ->first();

        if ($handpiece instanceof SerializedInventoryUnit) {
            $this->seedFailedHandpieceCalibration($context, $manager, $technician, $technicianUser, $handpiece);
        }

        $this->note('Seeded the calibration scenarios (passed furnace with certificate and schedule; failed handpiece with follow-up repair).');
    }

    private function seedPassedFurnaceCalibration(DemoContext $context, User $manager, EmployeeProfile $technician, User $technicianUser, SerializedInventoryUnit $unit): void
    {
        $service = app(EquipmentCalibrationService::class);
        $record = $this->calibrationRequest($context, $manager, $technician, $unit, '2026-10-01 09:00:00', 'Annual temperature calibration of the sintering furnace.');
        $calibration = EquipmentCalibration::query()->where('maintenance_record_id', $record->getKey())->first();

        if (! $calibration instanceof EquipmentCalibration || ! $calibration->isFinished()) {
            $appointment = $this->appointment($record);
            $this->dispatchVisit($context, $manager, $technicianUser, $appointment, '2026-10-02 08:30:00');

            if (! $calibration instanceof EquipmentCalibration) {
                $context->at('2026-10-02 09:10:00');
                $calibration = $service->start($record, $manager, [
                    'measurements' => [
                        ['key' => 'furnace_temperature', 'label' => 'Furnace temperature', 'expected_value' => '950', 'minimum_value' => '940', 'maximum_value' => '960', 'unit' => 'C'],
                        ['key' => 'temperature_uniformity', 'label' => 'Temperature uniformity (+/-)', 'expected_value' => '0', 'minimum_value' => '0', 'maximum_value' => '5', 'unit' => 'C'],
                    ],
                    'standard_reference' => 'ISO 17025 traceable thermocouple, type S',
                    'instrument_reference' => 'Reference thermometer RT-204',
                    'notes' => 'Calibrated at the 950 C sintering set point after a 30 minute soak.',
                ]);
            }

            $context->at('2026-10-02 10:15:00');
            $service->recordMeasurement($calibration, 'furnace_temperature', '943', $technicianUser, 'Within tolerance, 7 C below the set point.');
            $service->recordMeasurement($calibration, 'temperature_uniformity', '3.2', $technicianUser, 'Measured across five muffle positions.');

            $context->at('2026-10-02 10:40:00');
            $service->complete($calibration->refresh(), $technicianUser, CalibrationResult::Passed, null, Carbon::parse('2027-04-02', config()->string('app.timezone')));

            $context->at('2026-10-02 10:50:00');
            $service->issueCertificate($calibration->refresh(), $technicianUser, self::PassedCertificate, Carbon::parse('2027-10-02', config()->string('app.timezone')));

            $this->completeVisit($context, $technicianUser, $appointment, '2026-10-02 11:00:00', 'Eng. Khaled Mansour');
        }

        $this->closeRequest($context, $manager, $record, '2026-10-02 14:00:00');
        $this->seedSchedule($context, $manager, $unit);
    }

    private function seedSchedule(DemoContext $context, User $manager, SerializedInventoryUnit $unit): void
    {
        $exists = MaintenanceSchedule::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->exists();

        if ($exists) {
            return;
        }

        $context->at('2026-10-02 14:30:00');
        app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
            serializedInventoryUnitId: $unit->getKey(),
            customerId: (int) $unit->custody_reference_id,
            name: 'Six-monthly furnace temperature calibration',
            intervalType: MaintenanceIntervalType::Months,
            intervalValue: 6,
            leadTimeDays: 14,
            firstDueOn: '2027-04-02',
            billingType: MaintenanceBillingType::Unbilled,
            maintenanceKind: MaintenanceKind::Calibration,
        ), $manager);
    }

    private function seedFailedHandpieceCalibration(DemoContext $context, User $manager, EmployeeProfile $technician, User $technicianUser, SerializedInventoryUnit $unit): void
    {
        $service = app(EquipmentCalibrationService::class);
        $record = $this->calibrationRequest($context, $manager, $technician, $unit, '2026-10-02 15:00:00', 'Speed accuracy validation of the surgical handpiece.');

        $calibration = EquipmentCalibration::query()->where('maintenance_record_id', $record->getKey())->first();

        if (! $calibration instanceof EquipmentCalibration || ! $calibration->isFinished()) {
            $appointment = $this->appointment($record);
            $this->dispatchVisit($context, $manager, $technicianUser, $appointment, '2026-10-03 08:30:00');

            if (! $calibration instanceof EquipmentCalibration) {
                $context->at('2026-10-03 09:10:00');
                $calibration = $service->start($record, $manager, [
                    'measurements' => [
                        ['key' => 'rotation_speed', 'label' => 'Rotation speed at 40,000 rpm', 'expected_value' => '40000', 'minimum_value' => '39000', 'maximum_value' => '41000', 'unit' => 'rpm'],
                    ],
                    'standard_reference' => 'Manufacturer service specification SS-HP-12',
                    'instrument_reference' => 'Optical tachometer OT-88',
                ]);
            }

            $context->at('2026-10-03 09:45:00');
            $service->recordMeasurement($calibration, 'rotation_speed', '36200', $technicianUser, 'Speed drops under load; bearing wear suspected.');

            // The support manager raises the follow-up repair, so the failure and its repair request share one actor.
            $context->at('2026-10-03 10:00:00');
            $service->fail($calibration->refresh(), $manager, 'Rotation speed is 9.5% below the specified minimum; the handpiece needs a bearing replacement.', true);

            $this->completeVisit($context, $technicianUser, $appointment, '2026-10-03 10:10:00', 'Dr. Lina Haddad');
        }

        $this->closeRequest($context, $manager, $record, '2026-10-03 11:00:00');
    }

    private function calibrationRequest(DemoContext $context, User $manager, EmployeeProfile $technician, SerializedInventoryUnit $unit, string $at, string $description): MaintenanceRecord
    {
        $existing = MaintenanceRecord::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->where('description', 'like', self::Marker.'%')
            ->first();

        if ($existing instanceof MaintenanceRecord) {
            return $existing;
        }

        $context->at($at);
        $context->as('support_manager');

        $record = app(MaintenanceRecordService::class)->createStandalone([
            'customer_id' => $unit->custody_reference_id,
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => MaintenanceKind::Calibration,
            'description' => self::Marker.' '.$description,
        ], $manager);

        $task = app(ServiceRecordService::class)->create($record, [
            'title' => 'Calibration visit',
            'description' => $description,
            'employee_id' => $technician->getKey(),
        ], $manager);

        $start = Carbon::parse(Carbon::parse($at)->addDay()->format('Y-m-d').' 09:00:00', config()->string('app.timezone'));

        app(ServiceAppointmentService::class)->createScheduled(
            $task,
            $technician,
            $start,
            $start->copy()->addHours(3),
            null,
            $manager,
            'Calibration visit: bring the reference instruments.',
        );

        return $record;
    }

    private function appointment(MaintenanceRecord $record): ServiceAppointment
    {
        return ServiceAppointment::query()
            ->whereHas('serviceRecord', static fn ($query) => $query->where('maintenance_record_id', $record->getKey()))
            ->firstOrFail();
    }

    private function dispatchVisit(DemoContext $context, User $manager, User $technicianUser, ServiceAppointment $appointment, string $dispatchedAt): void
    {
        $appointments = app(ServiceAppointmentService::class);

        if ($appointment->status === ServiceAppointmentStatus::Planned) {
            $context->at(Carbon::parse($dispatchedAt)->subDay()->format('Y-m-d').' 16:00:00');
            $appointments->dispatch($appointment, $manager);
        }

        if ($appointment->refresh()->status === ServiceAppointmentStatus::Dispatched) {
            $context->at($dispatchedAt);
            $appointments->markEnRoute($appointment, $technicianUser);
        }

        if ($appointment->refresh()->status === ServiceAppointmentStatus::EnRoute) {
            $context->at(Carbon::parse($dispatchedAt)->addMinutes(35)->toDateTimeString());
            $appointments->checkIn($appointment, $technicianUser);
        }
    }

    private function completeVisit(DemoContext $context, User $technicianUser, ServiceAppointment $appointment, string $at, string $signatory): void
    {
        $appointment->refresh();

        if ($appointment->status === ServiceAppointmentStatus::Completed) {
            return;
        }

        $context->at($at);
        app(ServiceAppointmentService::class)->complete($appointment, $technicianUser, $signatory, null, null, 'Calibration visit completed on site.');
    }

    private function closeRequest(DemoContext $context, User $manager, MaintenanceRecord $record, string $at): void
    {
        $context->at($at);
        $records = app(MaintenanceRecordService::class);
        $task = MaintenanceTask::query()->where('maintenance_record_id', $record->getKey())->firstOrFail();
        $tasks = app(ServiceRecordService::class);
        $path = [MaintenanceStatus::InProgress, MaintenanceStatus::QualityAssurance, MaintenanceStatus::Closed];

        foreach ($path as $status) {
            if ($task->refresh()->status->canTransitionTo($status)) {
                $tasks->transition($task, $status, $manager);
            }
        }

        foreach ($path as $status) {
            if ($record->refresh()->status->canTransitionTo($status)) {
                $records->transition($record, $status, $manager);
            }
        }
    }
}
