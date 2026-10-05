<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\QualityResolutionType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Crm\Exceptions\InvalidCustomerReturnRequestTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records how a product quality complaint was resolved. Support never moves
 * stock or posts accounting: a customer return is submitted through the
 * existing {@see CustomerReturnRequestService}, which still goes through its
 * own review and the Inventory return flow, and refunds and credit notes stay
 * on the Sales paths (optionally linked to that return request).
 */
final readonly class TicketQualityResolutionService
{
    public function __construct(
        private CustomerReturnRequestService $returnRequests,
        private TicketProductContextService $contexts,
    ) {}

    /** @param array{notes?: string|null, customer_return_request_id?: int|null, supplier_id?: int|null} $data */
    public function resolve(Ticket $ticket, QualityResolutionType $type, User $actor, array $data = []): TicketQualityResolution
    {
        Gate::forUser($actor)->authorize('create', TicketQualityResolution::class);

        $notes = is_string($data['notes'] ?? null) ? mb_trim($data['notes']) : '';

        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => 'Resolution notes are required.']);
        }

        return DB::transaction(function () use ($ticket, $type, $actor, $data, $notes): TicketQualityResolution {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->type !== TicketType::ProductQualityIssue) {
                throw ValidationException::withMessages(['type' => 'Only a product quality complaint can be resolved this way.']);
            }

            if ($locked->status === TicketStatus::Cancelled) {
                throw ValidationException::withMessages(['ticket' => 'A cancelled ticket cannot be resolved.']);
            }

            $contexts = $locked->productContexts()->with('inventoryLot')->get();

            if ($contexts->isEmpty()) {
                throw ValidationException::withMessages(['product_contexts' => 'The complaint has no product context to resolve.']);
            }

            if ($locked->qualityResolution()->exists()) {
                throw ValidationException::withMessages(['resolution_type' => 'This complaint already has a resolution.']);
            }

            /** @var list<TicketProductContext> $contextList */
            $contextList = $contexts->values()->all();

            $returnRequest = $this->returnRequestFor($locked, $type, $contextList, $data, $actor);
            $supplier = $type === QualityResolutionType::SupplierClaim ? $this->supplierFor($contextList, $data) : null;

            if ($type === QualityResolutionType::LotInvestigation && $contexts->whereNotNull('inventory_lot_id')->isEmpty()) {
                throw ValidationException::withMessages(['resolution_type' => 'A lot investigation needs at least one product line with a lot.']);
            }

            $resolution = TicketQualityResolution::query()->create([
                'ticket_id' => $locked->getKey(),
                'resolution_type' => $type,
                'notes' => $notes,
                'customer_return_request_id' => $returnRequest?->getKey(),
                'supplier_id' => $supplier?->getKey(),
                'resolved_by' => $actor->getKey(),
                'resolved_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'resolution_type' => $type->value,
                    'customer_return_request_id' => $returnRequest?->getKey(),
                    'supplier_id' => $supplier?->getKey(),
                ])
                ->log('support.quality_complaint.resolved');

            return $resolution;
        });
    }

    /**
     * @param  list<TicketProductContext>  $contexts
     * @param  array<string, mixed>  $data
     */
    private function returnRequestFor(Ticket $ticket, QualityResolutionType $type, array $contexts, array $data, User $actor): ?CustomerReturnRequest
    {
        if (! $type->mayLinkReturnRequest()) {
            return null;
        }

        $existingId = $data['customer_return_request_id'] ?? null;

        if (is_numeric($existingId)) {
            $existing = CustomerReturnRequest::query()->find((int) $existingId);

            if (! $existing instanceof CustomerReturnRequest || (int) $existing->customer_id !== (int) $ticket->customer_id) {
                throw ValidationException::withMessages(['customer_return_request_id' => 'The return request belongs to a different customer.']);
            }

            return $existing;
        }

        if ($type !== QualityResolutionType::CustomerReturn) {
            return null;
        }

        $deliveryIds = collect($contexts)->map(static fn (TicketProductContext $context): ?int => $context->originalOperationLine?->inventory_operation_id)->unique()->values();

        if ($deliveryIds->count() !== 1 || $deliveryIds->first() === null) {
            throw ValidationException::withMessages(['resolution_type' => 'A customer return must cover lines of a single delivery; resolve the other deliveries separately.']);
        }

        $customer = CustomerProfile::query()->findOrFail($ticket->customer_id);
        $delivery = $contexts[0]->originalOperationLine?->operation;

        try {
            return $this->returnRequests->submit(
                $customer,
                $delivery ?? throw ValidationException::withMessages(['resolution_type' => 'The original delivery no longer exists.']),
                array_map(static fn (TicketProductContext $context): array => [
                    'original_inventory_operation_line_id' => (int) $context->original_inventory_operation_line_id,
                    'requested_quantity' => (string) $context->quantity,
                    'customer_note' => $context->notes,
                ], $contexts),
                'Product quality complaint '.$ticket->ticket_number,
            );
        } catch (InvalidCustomerReturnRequestTransition $exception) {
            throw ValidationException::withMessages(['resolution_type' => $exception->getMessage()]);
        }
    }

    /**
     * @param  list<TicketProductContext>  $contexts
     * @param  array<string, mixed>  $data
     */
    private function supplierFor(array $contexts, array $data): Supplier
    {
        $explicit = $data['supplier_id'] ?? null;

        if (is_numeric($explicit)) {
            $supplier = Supplier::query()->whereKey((int) $explicit)->where('is_active', true)->first();

            return $supplier ?? throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
        }

        foreach ($contexts as $context) {
            $supplier = $context->inventoryLot === null ? null : $this->contexts->supplierForLot($context->inventoryLot);

            if ($supplier instanceof Supplier) {
                return $supplier;
            }
        }

        throw ValidationException::withMessages(['supplier_id' => 'No supplier could be identified from the lot history; choose the responsible supplier.']);
    }
}
