<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerApprovalStatus;
use App\Enums\CustomerReturnRequestStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Events\CustomerReturnRequestSubmitted;
use App\Events\CustomerReturnRequestUpdated;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\Exceptions\InvalidCustomerReturnRequestTransition;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Owns the customer return-request lifecycle: submit, review, and convert.
 * Conversion delegates entirely to the existing, untouched
 * {@see InventoryReturnService} — this class never moves stock or decides a
 * line's disposition itself. A request line's lot/serial is never asked of
 * the customer; {@see InventoryReturnService::addCustomerLine()} already
 * requires it to match what the original delivery line recorded, so
 * conversion simply reads it from there.
 */
final readonly class CustomerReturnRequestService
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private InventoryReturnService $inventoryReturnService,
    ) {}

    /**
     * @param  list<array{original_inventory_operation_line_id:int, requested_quantity:float|int|string, customer_note?:string|null}>  $lines
     */
    public function submit(
        CustomerProfile $customer,
        InventoryOperation $delivery,
        array $lines,
        ?string $reason = null,
        string $sourceChannel = 'dashboard',
    ): CustomerReturnRequest {
        if ($customer->approval_status !== CustomerApprovalStatus::Approved || ! $customer->is_active) {
            throw new InvalidCustomerReturnRequestTransition('Only an approved, active customer may submit a return request.');
        }

        if (
            $delivery->operation_type !== OperationType::Delivery
            || $delivery->stage !== OperationStage::Done
            || $delivery->customer_id !== $customer->getKey()
        ) {
            throw new InvalidCustomerReturnRequestTransition('A return request requires a completed delivery belonging to this customer.');
        }

        if ($lines === []) {
            throw new InvalidCustomerReturnRequestTransition('A return request requires at least one line.');
        }

        return DB::transaction(function () use ($customer, $delivery, $lines, $reason, $sourceChannel): CustomerReturnRequest {
            $request = CustomerReturnRequest::query()->create([
                'request_number' => $this->numberGenerator->next(CustomerReturnRequest::query(), 'request_number', 'RR-'),
                'customer_id' => $customer->getKey(),
                'original_inventory_operation_id' => $delivery->getKey(),
                'reason' => $reason,
                'status' => CustomerReturnRequestStatus::Submitted,
                'submitted_at' => now(),
                'source_channel' => $sourceChannel,
            ]);

            foreach ($lines as $index => $line) {
                $quantity = (float) $line['requested_quantity'];

                if ($quantity <= 0.0) {
                    throw new InvalidCustomerReturnRequestTransition('Each requested quantity must be positive.');
                }

                $deliveryLine = InventoryOperationLine::query()->findOrFail((int) $line['original_inventory_operation_line_id']);

                if ($deliveryLine->inventory_operation_id !== $delivery->getKey()) {
                    throw new InvalidCustomerReturnRequestTransition('Each return line must belong to the referenced delivery.');
                }

                $request->lines()->create([
                    'original_inventory_operation_line_id' => $deliveryLine->getKey(),
                    'requested_quantity' => $line['requested_quantity'],
                    'customer_note' => $line['customer_note'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $submitted = $request->refresh();

            CustomerReturnRequestSubmitted::dispatch($submitted);

            return $submitted;
        });
    }

    public function startReview(User $actor, CustomerReturnRequest $request): CustomerReturnRequest
    {
        return DB::transaction(function () use ($actor, $request): CustomerReturnRequest {
            $locked = $this->lockInStatus($request, CustomerReturnRequestStatus::Submitted);

            $locked->forceFill([
                'status' => CustomerReturnRequestStatus::UnderReview,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.return_request.under_review');

            $updated = $locked->refresh();

            CustomerReturnRequestUpdated::dispatch($updated);

            return $updated;
        });
    }

    public function approve(User $actor, CustomerReturnRequest $request, ?string $note = null): CustomerReturnRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): CustomerReturnRequest {
            $locked = $this->lockInStatus($request, CustomerReturnRequestStatus::UnderReview);

            $locked->forceFill([
                'status' => CustomerReturnRequestStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.return_request.approved');

            $updated = $locked->refresh();

            CustomerReturnRequestUpdated::dispatch($updated);

            return $updated;
        });
    }

    public function reject(User $actor, CustomerReturnRequest $request, string $reason): CustomerReturnRequest
    {
        return DB::transaction(function () use ($actor, $request, $reason): CustomerReturnRequest {
            $locked = $this->lockOpen($request);

            $locked->forceFill([
                'status' => CustomerReturnRequestStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.return_request.rejected');

            $updated = $locked->refresh();

            CustomerReturnRequestUpdated::dispatch($updated);

            return $updated;
        });
    }

    public function convertToInventoryReturn(
        User $actor,
        CustomerReturnRequest $request,
        Warehouse $warehouse,
        ?string $notes = null,
    ): InventoryReturn {
        return DB::transaction(function () use ($actor, $request, $warehouse, $notes): InventoryReturn {
            $locked = $this->lockInStatus($request, CustomerReturnRequestStatus::Approved);
            $lines = $locked->lines()->with('originalOperationLine')->get();

            if ($lines->isEmpty()) {
                throw new InvalidCustomerReturnRequestTransition('A return request requires at least one line before conversion.');
            }

            $delivery = $locked->originalOperation;

            if (! $delivery instanceof InventoryOperation) {
                throw new InvalidCustomerReturnRequestTransition('The original delivery for this return request no longer exists.');
            }

            $inventoryReturn = $this->inventoryReturnService->createCustomerReturn(
                $actor,
                $delivery,
                $warehouse,
                $locked->reason,
                $notes,
            );

            foreach ($lines as $line) {
                $deliveryLine = $line->originalOperationLine;

                if (! $deliveryLine instanceof InventoryOperationLine) {
                    throw new InvalidCustomerReturnRequestTransition('A return request line no longer references a valid delivery line.');
                }

                $this->inventoryReturnService->addCustomerLine(
                    $inventoryReturn,
                    $deliveryLine,
                    (string) $line->requested_quantity,
                    $deliveryLine->inventory_lot_id,
                    $deliveryLine->serialized_inventory_unit_id,
                );
            }

            $locked->forceFill([
                'status' => CustomerReturnRequestStatus::Converted,
                'resulting_inventory_return_id' => $inventoryReturn->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.return_request.converted');

            CustomerReturnRequestUpdated::dispatch($locked->refresh());

            return $inventoryReturn;
        });
    }

    private function lockInStatus(CustomerReturnRequest $request, CustomerReturnRequestStatus $expected): CustomerReturnRequest
    {
        /** @var CustomerReturnRequest $locked */
        $locked = CustomerReturnRequest::query()->whereKey($request->getKey())->lockForUpdate()->sole();

        if ($locked->status !== $expected) {
            throw new InvalidCustomerReturnRequestTransition(sprintf('This request must be %s, but is %s.', $expected->value, $locked->status->value));
        }

        return $locked;
    }

    private function lockOpen(CustomerReturnRequest $request): CustomerReturnRequest
    {
        /** @var CustomerReturnRequest $locked */
        $locked = CustomerReturnRequest::query()->whereKey($request->getKey())->lockForUpdate()->sole();

        if (! $locked->isOpen()) {
            throw new InvalidCustomerReturnRequestTransition(sprintf('This request is already %s.', $locked->status->value));
        }

        return $locked;
    }

    private function log(CustomerReturnRequest $request, User $actor, string $logName): void
    {
        activity()
            ->performedOn($request)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($logName);
    }
}
