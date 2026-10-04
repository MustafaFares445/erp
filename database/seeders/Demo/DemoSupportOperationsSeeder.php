<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ServiceAppointmentStatus;
use App\Enums\SupportEntitlementStatus;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\User;
use App\Services\Support\ServiceAppointmentService;
use App\Services\Support\TicketSatisfactionService;
use Database\Seeders\SupportServiceLevelSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Adds the operational data needed by the current Support & Maintenance UI
 * without turning the demo into a generic ITSM showcase.
 *
 * The business-facing demo already comes from DemoSupportMonthSeeder:
 * tickets, payments, maintenance requests, service records, warranty, parts,
 * costs and lifecycle history. This stage fills the new operational surfaces
 * that otherwise look empty: service levels/entitlements and field visits,
 * plus a small closed-ticket feedback history for the optional CSAT rollout.
 *
 * Advanced routing, automation and knowledge-base fixtures are deliberately
 * not seeded here. Those capabilities are optional rollout features rather
 * than required IERP business flows.
 */
final class DemoSupportOperationsSeeder extends DemoSeeder
{
    protected function seed(DemoContext $context): void
    {
        $this->call(SupportServiceLevelSeeder::class);

        $manager = User::query()
            ->where('email', DemoContext::Actors['support_manager'])
            ->firstOrFail();

        $fieldTechnician = EmployeeProfile::query()
            ->where('email', 'demo.support.agent1@ierp.test')
            ->firstOrFail();

        $supportTechnician = EmployeeProfile::query()
            ->where('email', 'demo.support.agent2@ierp.test')
            ->firstOrFail();

        $this->seedEquipmentEntitlement($manager);
        $this->seedFieldVisits($manager, $fieldTechnician, $supportTechnician);
        $this->seedCustomerFeedback();

        $this->note('Seeded Support service levels, entitlement, field visits and CSAT history.');
    }

    private function seedEquipmentEntitlement(User $actor): void
    {
        $unit = SerializedInventoryUnit::query()
            ->where('custody_type', 'customer')
            ->where('custody_reference_type', 'customer')
            ->where('serial_number', 'SN019-HANDPIECE-0001')
            ->whereHas(
                'productVariant',
                static fn (Builder $query): Builder => $query->where('sku', 'DEMO-P019-HANDPIECE'),
            )
            ->first();

        if (! $unit instanceof SerializedInventoryUnit || ! is_numeric($unit->custody_reference_id)) {
            return;
        }

        $level = SupportServiceLevel::query()->where('code', 'PRIORITY')->firstOrFail();

        SupportEntitlement::query()->updateOrCreate(
            ['external_reference' => 'DEMO-SUPPORT-2026-001'],
            [
                'customer_id' => (int) $unit->custody_reference_id,
                'support_service_level_id' => $level->getKey(),
                'serialized_inventory_unit_id' => $unit->getKey(),
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-08-31',
                'status' => SupportEntitlementStatus::Active,
                'notes' => 'Priority support for the customer-owned surgical drill handpiece, including remote triage and scheduled field service.',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ],
        );
    }

    private function seedFieldVisits(
        User $actor,
        EmployeeProfile $fieldTechnician,
        EmployeeProfile $supportTechnician,
    ): void {
        // This completed visit predates this demo stage and its service record is already
        // closed, so it is restored as an immutable historical snapshot.
        $this->upsertHistoricalAppointment(
            taskTitle: 'Replace gear assembly and recalibrate',
            technician: $fieldTechnician,
            start: '2026-09-11 09:30:00',
            end: '2026-09-11 11:00:00',
            actor: $actor,
            notes: 'Collected the handpiece, verified the replacement gear assembly and completed an on-site calibration before return.',
            dispatchedAt: '2026-09-11 08:45:00',
            enRouteAt: '2026-09-11 09:00:00',
            checkedInAt: '2026-09-11 09:28:00',
            checkedOutAt: '2026-09-11 10:52:00',
            signatureName: 'Al Noor Medical Center',
        );

        // Open/future work goes through the same domain service as the Filament UI,
        // including authorization, active-technician and overlap validation.
        $this->ensureOperationalAppointment(
            taskTitle: 'Replace door sensor and descale',
            technician: $supportTechnician,
            start: '2026-10-05 09:00:00',
            end: '2026-10-05 11:00:00',
            actor: $actor,
            notes: 'Replace the covered door sensor, descale the heating element and run a full validation cycle.',
        );

        $this->ensureOperationalAppointment(
            taskTitle: 'Initial gasket and door seal inspection',
            technician: $fieldTechnician,
            start: '2026-10-05 13:30:00',
            end: '2026-10-05 15:00:00',
            actor: $actor,
            notes: 'Inspect the sterilizer door gasket and verify chamber pressure after the replacement recommendation.',
            dispatchAt: '2026-10-04 16:00:00',
        );
    }

    private function seedCustomerFeedback(): void
    {
        $closed = Ticket::query()
            ->with('customer.user')
            ->where('status', TicketStatus::Closed->value)
            ->orderBy('id')
            ->limit(2)
            ->get();

        foreach ($closed->values() as $index => $ticket) {
            if (TicketSatisfactionResponse::query()->where('ticket_id', $ticket->getKey())->exists()) {
                continue;
            }

            $customerUser = $ticket->customer?->user;

            if (! $customerUser instanceof User) {
                throw new LogicException("Closed demo ticket [{$ticket->ticket_number}] has no customer login.");
            }

            $submittedAt = ($ticket->closed_at ?? $ticket->updated_at ?? now())
                ->copy()
                ->addMinutes(45 + ($index * 20));
            $originalNow = Carbon::getTestNow();

            try {
                Carbon::setTestNow($submittedAt);

                app(TicketSatisfactionService::class)->submit(
                    ticket: $ticket,
                    actor: $customerUser,
                    rating: $index === 0 ? 5 : 4,
                    comment: $index === 0
                        ? 'The technician explained the repair clearly and the equipment was returned ready for use.'
                        : 'The issue was resolved without another visit and communication was clear.',
                    sourceChannel: 'customer_app',
                );
            } finally {
                Carbon::setTestNow($originalNow);
            }
        }
    }

    private function ensureOperationalAppointment(
        string $taskTitle,
        EmployeeProfile $technician,
        string $start,
        string $end,
        User $actor,
        string $notes,
        ?string $dispatchAt = null,
    ): void {
        $task = MaintenanceTask::query()
            ->where('title', $taskTitle)
            ->first();

        if (! $task instanceof MaintenanceTask) {
            throw new LogicException("Demo service record [{$taskTitle}] was not found.");
        }

        $startAt = Carbon::parse($start, config()->string('app.timezone'));
        $endAt = Carbon::parse($end, config()->string('app.timezone'));

        $appointment = ServiceAppointment::query()
            ->where('maintenance_task_id', $task->getKey())
            ->where('scheduled_start_at', $startAt)
            ->first();

        if (! $appointment instanceof ServiceAppointment) {
            $appointment = app(ServiceAppointmentService::class)->createScheduled(
                task: $task,
                employee: $technician,
                start: $startAt,
                end: $endAt,
                address: null,
                actor: $actor,
                notes: $notes,
            );
        }

        if ($dispatchAt !== null && $appointment->status === ServiceAppointmentStatus::Planned) {
            $originalNow = Carbon::getTestNow();

            try {
                Carbon::setTestNow(Carbon::parse($dispatchAt, config()->string('app.timezone')));
                app(ServiceAppointmentService::class)->dispatch($appointment, $actor);
            } finally {
                Carbon::setTestNow($originalNow);
            }
        }
    }

    private function upsertHistoricalAppointment(
        string $taskTitle,
        EmployeeProfile $technician,
        string $start,
        string $end,
        User $actor,
        string $notes,
        string $dispatchedAt,
        string $enRouteAt,
        string $checkedInAt,
        string $checkedOutAt,
        string $signatureName,
    ): void {
        $task = MaintenanceTask::query()
            ->with('maintenanceRecord.customer')
            ->where('title', $taskTitle)
            ->first();

        if (! $task instanceof MaintenanceTask) {
            throw new LogicException("Demo service record [{$taskTitle}] was not found.");
        }

        $customer = $task->maintenanceRecord?->customer;

        if ($customer === null) {
            throw new LogicException("Demo service record [{$taskTitle}] has no customer.");
        }

        $startAt = Carbon::parse($start, config()->string('app.timezone'));
        $endAt = Carbon::parse($end, config()->string('app.timezone'));

        ServiceAppointment::query()->updateOrCreate(
            [
                'maintenance_task_id' => $task->getKey(),
                'scheduled_start_at' => $startAt,
            ],
            [
                'employee_id' => $technician->getKey(),
                'status' => ServiceAppointmentStatus::Completed,
                'scheduled_end_at' => $endAt,
                'estimated_duration_minutes' => max(1, (int) $startAt->diffInMinutes($endAt)),
                'address_snapshot' => [
                    'source' => 'customer_profile',
                    'label' => $customer->company_name,
                    'address' => $customer->address,
                    'city' => $customer->city,
                    'country' => $customer->country,
                    'contact_name' => $customer->contact_name,
                    'contact_phone' => $customer->contact_phone,
                ],
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'dispatched_at' => Carbon::parse($dispatchedAt, config()->string('app.timezone')),
                'en_route_at' => Carbon::parse($enRouteAt, config()->string('app.timezone')),
                'checked_in_at' => Carbon::parse($checkedInAt, config()->string('app.timezone')),
                'check_in_latitude' => $customer->latitude,
                'check_in_longitude' => $customer->longitude,
                'checked_out_at' => Carbon::parse($checkedOutAt, config()->string('app.timezone')),
                'check_out_latitude' => $customer->latitude,
                'check_out_longitude' => $customer->longitude,
                'customer_signature_name' => $signatureName,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ],
        );
    }
}
