<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CustomerApprovalStatus;
use App\Enums\SupportAutomationEvent;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Fast ticket intake. Commercial, equipment, warranty and service-path
 * decisions are deliberately deferred to TicketTriageService.
 */
final readonly class TicketIntakeService
{
    public function __construct(
        private TicketAttachmentSynchronizer $attachmentSynchronizer,
        private SlaService $slaService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('create', Ticket::class);

        return DB::transaction(function () use ($data, $actor): Ticket {
            $ticket = Ticket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'customer_id' => $data['customer_id'],
                'type' => $data['type'],
                'customer_impact' => $data['customer_impact'] ?? null,
                'priority' => $data['priority'],
                'title' => $data['title'],
                'description' => $data['description'],
                'is_chargeable' => false,
                'status' => TicketStatus::Pending,
                'pending_reason' => null,
                'continued_from_ticket_id' => $data['continued_from_ticket_id'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->slaService->onTicketCreated($ticket);

            if (isset($data['attachments']) && is_array($data['attachments'])) {
                $this->attachmentSynchronizer->sync($ticket, $data['attachments']);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => $ticket->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.created');

            DB::afterCommit(static fn () => app(SupportAutomationEngine::class)->handle(
                SupportAutomationEvent::TicketCreated,
                $ticket->refresh(),
                ['created_by' => $actor->getKey()],
            ));

            return $ticket;
        });
    }

    /** @param array<string, mixed> $data */
    public function createForCustomer(array $data, User $actor): Ticket
    {
        $customer = $actor->customerProfile;

        if (! $customer instanceof CustomerProfile
            || ! $customer->is_active
            || $customer->approval_status !== CustomerApprovalStatus::Approved) {
            throw ValidationException::withMessages([
                'account' => 'An approved active customer account is required to create a support ticket.',
            ]);
        }

        $rawType = $data['type'] ?? null;
        $type = $rawType instanceof TicketType
            ? $rawType
            : TicketType::from(is_scalar($rawType) ? (string) $rawType : '');
        $impact = ($data['customer_impact'] ?? null) instanceof TicketCustomerImpact
            ? $data['customer_impact']
            : (is_string($data['customer_impact'] ?? null)
                ? TicketCustomerImpact::tryFrom($data['customer_impact'])
                : null);

        $equipmentSource = null;
        $unit = null;
        $unitId = $data['serialized_inventory_unit_id'] ?? null;

        if (is_numeric($unitId)) {
            $unit = $customer->ownedEquipment()->whereKey((int) $unitId)->first();

            if (! $unit instanceof SerializedInventoryUnit) {
                throw ValidationException::withMessages([
                    'serialized_inventory_unit_id' => 'The selected equipment is not owned by this customer.',
                ]);
            }

            $equipmentSource = TicketEquipmentSource::SoldByUs;
        } elseif (filled($data['external_equipment_name'] ?? null)
            || filled($data['external_equipment_model'] ?? null)
            || filled($data['external_serial_number'] ?? null)) {
            $equipmentSource = TicketEquipmentSource::External;
        }

        $priority = app(TicketPriorityResolver::class)->resolve($type, $impact);

        return DB::transaction(function () use ($data, $actor, $customer, $type, $impact, $priority, $equipmentSource, $unit): Ticket {
            $ticket = Ticket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'customer_id' => $customer->getKey(),
                'type' => $type,
                'customer_impact' => $impact,
                'priority' => $priority,
                'title' => $data['title'],
                'description' => $data['description'],
                'status' => TicketStatus::Pending,
                'is_chargeable' => false,
                'equipment_source' => $equipmentSource,
                'serialized_inventory_unit_id' => $unit?->getKey(),
                'external_equipment_name' => $equipmentSource === TicketEquipmentSource::External ? ($data['external_equipment_name'] ?? null) : null,
                'external_equipment_model' => $equipmentSource === TicketEquipmentSource::External ? ($data['external_equipment_model'] ?? null) : null,
                'external_serial_number' => $equipmentSource === TicketEquipmentSource::External ? ($data['external_serial_number'] ?? null) : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->slaService->onTicketCreated($ticket);

            if (isset($data['attachments']) && is_array($data['attachments'])) {
                $this->attachmentSynchronizer->addUploadedFiles($ticket, $data['attachments']);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => $ticket->getAttributes()])
                ->withProperties(['source_channel' => 'customer_app', 'ip_address' => request()->ip()])
                ->log('support.ticket.created');

            DB::afterCommit(static fn () => app(SupportAutomationEngine::class)->handle(
                SupportAutomationEvent::TicketCreated,
                $ticket->refresh(),
                ['created_by' => $actor->getKey(), 'source_channel' => 'customer_app'],
            ));

            return $ticket;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Ticket $ticket, array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        return DB::transaction(function () use ($ticket, $data, $actor): Ticket {
            $oldValues = $ticket->only(['type', 'customer_impact', 'priority', 'title', 'description']);
            $oldPriority = $ticket->priority;

            $ticket->fill([
                'type' => $data['type'] ?? $ticket->type,
                'customer_impact' => $data['customer_impact'] ?? $ticket->customer_impact,
                'priority' => $data['priority'] ?? $ticket->priority,
                'title' => $data['title'] ?? $ticket->title,
                'description' => $data['description'] ?? $ticket->description,
                'updated_by' => $actor->getKey(),
            ]);
            $ticket->save();

            if ($ticket->priority !== $oldPriority) {
                $this->slaService->onPriorityChanged($ticket, $ticket->priority, $actor);

                DB::afterCommit(static fn () => app(SupportAutomationEngine::class)->handle(
                    SupportAutomationEvent::TicketPriorityChanged,
                    $ticket->refresh(),
                    [
                        'old_priority' => $oldPriority->value,
                        'new_priority' => $ticket->priority->value,
                    ],
                ));
            }

            if (isset($data['attachments']) && is_array($data['attachments'])) {
                $this->attachmentSynchronizer->sync($ticket, $data['attachments']);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $ticket->only(['type', 'customer_impact', 'priority', 'title', 'description']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.updated');

            return $ticket;
        });
    }

    private function nextTicketNumber(): string
    {
        $highestNumber = Ticket::query()->withTrashed()->whereNotNull('ticket_number')->lockForUpdate()->max('ticket_number');
        $next = is_string($highestNumber) ? ((int) mb_substr($highestNumber, 4)) + 1 : 1;

        return sprintf('TCK-%06d', $next);
    }
}
