<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\NotificationEventKey;
use App\Enums\ServiceAppointmentStatus;
use App\Events\EquipmentInstallationMilestone;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ServiceAppointmentService
{
    public function __construct(
        private TechnicianAvailabilityService $availability,
        private SlaService $slaService,
    ) {}

    public function createScheduled(
        MaintenanceTask $task,
        EmployeeProfile $employee,
        CarbonInterface $start,
        CarbonInterface $end,
        ?CustomerDeliveryAddress $address,
        User $actor,
        ?string $notes = null,
    ): ServiceAppointment {
        Gate::forUser($actor)->authorize('create', ServiceAppointment::class);

        $this->assertTaskOpen($task);
        $this->assertSchedule($start, $end);
        $this->assertEmployeeActive($employee);

        return DB::transaction(function () use ($task, $employee, $start, $end, $address, $actor, $notes): ServiceAppointment {
            EmployeeProfile::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoConflict($employee, $start, $end);

            $snapshot = $this->addressSnapshot($task, $address);

            $appointment = ServiceAppointment::query()->create([
                'maintenance_task_id' => $task->getKey(),
                'employee_id' => $employee->getKey(),
                'status' => ServiceAppointmentStatus::Planned,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'estimated_duration_minutes' => max(1, (int) $start->diffInMinutes($end)),
                'address_snapshot' => $snapshot['address'],
                'latitude' => $snapshot['latitude'],
                'longitude' => $snapshot['longitude'],
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($appointment)
                ->causedBy($actor)
                ->withChanges(['attributes' => $appointment->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_appointment.scheduled');

            $record = $task->maintenanceRecord;

            if ($record instanceof MaintenanceRecord && $appointment->isInstallation()) {
                DB::afterCommit(static fn () => EquipmentInstallationMilestone::dispatch($record, NotificationEventKey::InstallationScheduled, $appointment));
            }

            return $appointment;
        });
    }

    public function reschedule(
        ServiceAppointment $appointment,
        EmployeeProfile $employee,
        CarbonInterface $start,
        CarbonInterface $end,
        ?CustomerDeliveryAddress $address,
        User $actor,
        ?string $notes = null,
    ): ServiceAppointment {
        Gate::forUser($actor)->authorize('update', $appointment);

        if (in_array($appointment->status, [ServiceAppointmentStatus::Completed, ServiceAppointmentStatus::Cancelled], true)) {
            throw new DomainException('Completed or cancelled appointments cannot be rescheduled.');
        }

        $this->assertTaskOpen($this->taskOf($appointment));
        $this->assertSchedule($start, $end);
        $this->assertEmployeeActive($employee);

        return DB::transaction(function () use ($appointment, $employee, $start, $end, $address, $actor, $notes): ServiceAppointment {
            EmployeeProfile::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $locked = ServiceAppointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoConflict($employee, $start, $end, $locked);

            $snapshot = $this->addressSnapshot($this->taskOf($locked), $address);
            $old = $locked->only(['employee_id', 'scheduled_start_at', 'scheduled_end_at', 'address_snapshot']);

            $locked->update([
                'employee_id' => $employee->getKey(),
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'estimated_duration_minutes' => max(1, (int) $start->diffInMinutes($end)),
                'address_snapshot' => $snapshot['address'],
                'latitude' => $snapshot['latitude'],
                'longitude' => $snapshot['longitude'],
                'notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $old,
                    'attributes' => $locked->only(['employee_id', 'scheduled_start_at', 'scheduled_end_at', 'address_snapshot']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_appointment.rescheduled');

            return $locked->refresh();
        });
    }

    public function dispatch(ServiceAppointment $appointment, User $actor): ServiceAppointment
    {
        return $this->transition($appointment, ServiceAppointmentStatus::Dispatched, $actor, [
            'dispatched_at' => now(),
        ], 'update');
    }

    public function markEnRoute(ServiceAppointment $appointment, User $actor): ServiceAppointment
    {
        return $this->transition($appointment, ServiceAppointmentStatus::EnRoute, $actor, [
            'en_route_at' => now(),
        ]);
    }

    public function checkIn(
        ServiceAppointment $appointment,
        User $actor,
        ?float $latitude = null,
        ?float $longitude = null,
    ): ServiceAppointment {
        $updated = $this->transition($appointment, ServiceAppointmentStatus::OnSite, $actor, [
            'checked_in_at' => now(),
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
        ]);

        $ticket = $updated->serviceRecord?->maintenanceRecord?->ticket;
        if ($ticket !== null) {
            $this->slaService->completeOnsiteArrival($ticket);
        }

        return $updated;
    }

    public function complete(
        ServiceAppointment $appointment,
        User $actor,
        string $customerSignatureName,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $notes = null,
    ): ServiceAppointment {
        if (mb_trim($customerSignatureName) === '') {
            throw ValidationException::withMessages([
                'customer_signature_name' => 'Customer signature name is required before completing an on-site appointment.',
            ]);
        }

        return $this->transition($appointment, ServiceAppointmentStatus::Completed, $actor, [
            'checked_out_at' => now(),
            'check_out_latitude' => $latitude,
            'check_out_longitude' => $longitude,
            'customer_signature_name' => mb_trim($customerSignatureName),
            'notes' => $notes ?? $appointment->notes,
        ]);
    }

    public function cancel(ServiceAppointment $appointment, User $actor): ServiceAppointment
    {
        return $this->transition($appointment, ServiceAppointmentStatus::Cancelled, $actor, [], 'update');
    }

    /** @param array<string, mixed> $attributes */
    private function transition(
        ServiceAppointment $appointment,
        ServiceAppointmentStatus $to,
        User $actor,
        array $attributes = [],
        string $ability = 'execute',
    ): ServiceAppointment {
        // Dispatching and cancelling are dispatcher (manage) decisions; the assigned technician only executes.
        Gate::forUser($actor)->authorize($ability, $appointment);

        return DB::transaction(function () use ($appointment, $to, $actor, $attributes): ServiceAppointment {
            $locked = ServiceAppointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new DomainException(sprintf(
                    'Service appointment cannot move from %s to %s.',
                    $from->label(),
                    $to->label(),
                ));
            }

            $locked->update([
                ...$attributes,
                'status' => $to,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, ...$attributes],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log(match ($to) {
                    ServiceAppointmentStatus::Dispatched => 'support.service_appointment.dispatched',
                    ServiceAppointmentStatus::EnRoute => 'support.service_appointment.en_route',
                    ServiceAppointmentStatus::OnSite => 'support.service_appointment.checked_in',
                    ServiceAppointmentStatus::Completed => 'support.service_appointment.completed',
                    ServiceAppointmentStatus::Cancelled => 'support.service_appointment.cancelled',
                    ServiceAppointmentStatus::Planned => 'support.service_appointment.scheduled',
                });

            return $locked->refresh();
        });
    }

    private function taskOf(ServiceAppointment $appointment): MaintenanceTask
    {
        return $appointment->serviceRecord ?? throw new DomainException('The appointment is not linked to a service record.');
    }

    private function assertTaskOpen(MaintenanceTask $task): void
    {
        if (in_array($task->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)) {
            throw new DomainException('A closed or cancelled service record cannot receive field appointments.');
        }
    }

    private function assertSchedule(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'scheduled_end_at' => 'The appointment end must be after the start.',
            ]);
        }
    }

    private function assertEmployeeActive(EmployeeProfile $employee): void
    {
        if (! $employee->is_active) {
            throw new DomainException('Inactive employees cannot receive service appointments.');
        }
    }

    private function assertNoConflict(
        EmployeeProfile $employee,
        CarbonInterface $start,
        CarbonInterface $end,
        ?ServiceAppointment $ignore = null,
    ): void {
        if ($this->availability->hasConflict($employee, $start, $end, $ignore)) {
            throw ValidationException::withMessages([
                'employee_id' => 'The technician already has an overlapping field-service appointment.',
            ]);
        }
    }

    /**
     * @return array{address: array<string, scalar|null>, latitude: float|null, longitude: float|null}
     */
    private function addressSnapshot(MaintenanceTask $task, ?CustomerDeliveryAddress $address): array
    {
        $customer = $task->maintenanceRecord?->customer;

        if ($customer === null) {
            throw new DomainException('The service record has no customer location context.');
        }

        if ($address instanceof CustomerDeliveryAddress) {
            if ($address->customer_profile_id !== $customer->getKey() || ! $address->is_active) {
                throw new DomainException('The selected service address does not belong to the maintenance customer.');
            }

            return [
                'address' => [
                    'source' => 'delivery_address',
                    'label' => $address->label,
                    'address' => $address->address,
                    'city' => $address->city,
                    'country' => $address->country,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone,
                ],
                'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
                'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            ];
        }

        return [
            'address' => [
                'source' => 'customer_profile',
                'label' => $customer->company_name,
                'address' => $customer->address,
                'city' => $customer->city,
                'country' => $customer->country,
                'contact_name' => $customer->contact_name,
                'contact_phone' => $customer->contact_phone,
            ],
            'latitude' => $customer->latitude !== null ? (float) $customer->latitude : null,
            'longitude' => $customer->longitude !== null ? (float) $customer->longitude : null,
        ];
    }
}
