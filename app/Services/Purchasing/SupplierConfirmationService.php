<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\SupplierConfirmationStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Services\Purchasing\Exceptions\ConfirmationNotAmendable;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class SupplierConfirmationService
{
    public function __construct(
        private PurchaseOrderSupplierCommitmentService $commitments,
    ) {}

    public function recordPurchaseOrder(
        User $actor,
        PurchaseOrder $order,
        ?string $notes = null,
    ): SupplierConfirmation {
        Gate::forUser($actor)->authorize('request', SupplierConfirmation::class);

        return DB::transaction(function () use ($actor, $order, $notes): SupplierConfirmation {
            /** @var PurchaseOrder $lockedOrder */
            $lockedOrder = PurchaseOrder::query()
                ->with('supplier')
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $confirmation = new SupplierConfirmation([
                'purchase_order_id' => $lockedOrder->getKey(),
                'supplier_id' => $lockedOrder->supplier_id,
                'notes' => $notes,
            ]);
            $confirmation->forceFill([
                'confirmation_status' => SupplierConfirmationStatus::Pending,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $items = [];
            foreach ($lockedOrder->lines()->lockForUpdate()->orderBy('id')->get() as $line) {
                $requestedBase = $this->requestedBaseQuantityForNewEvidence($line);
                if ($requestedBase === '0.000000') {
                    continue;
                }

                $items[] = [
                    'product_variant_id' => $line->product_variant_id,
                    'purchase_order_line_id' => $line->id,
                    'requested_quantity' => $this->requestedTransactionQuantity($line, $requestedBase),
                    'requested_base_quantity' => $requestedBase,
                    'confirmed_base_quantity' => null,
                    'backordered_base_quantity' => null,
                ];
            }

            if ($items === []) {
                $confirmation->delete();

                throw ValidationException::withMessages([
                    'purchase_order_id' => __('admin.purchasing.errors.no_outstanding_confirmation_lines'),
                ]);
            }

            $confirmation->items()->createMany($items);

            return $confirmation->load($this->relations());
        });
    }

    /**
     * @param  list<array{id:int, confirmed_base_quantity:mixed, backordered_base_quantity:mixed}>  $quantities
     */
    public function respond(
        User $actor,
        SupplierConfirmation $confirmation,
        SupplierConfirmationStatus $outcome,
        ?CarbonImmutable $promisedAt,
        string $note,
        array $quantities = [],
    ): SupplierConfirmation {
        Gate::forUser($actor)->authorize('answer', $confirmation);

        return DB::transaction(function () use ($actor, $confirmation, $outcome, $promisedAt, $note, $quantities): SupplierConfirmation {
            /** @var SupplierConfirmation $locked */
            $locked = SupplierConfirmation::query()
                ->with('purchaseOrder')
                ->lockForUpdate()
                ->findOrFail($confirmation->getKey());

            if (! $locked->confirmation_status->canTransitionTo($outcome)) {
                throw ConfirmationNotAmendable::alreadyAnswered($locked);
            }
            if (! $outcome->isAnswered()) {
                throw ValidationException::withMessages(['response' => __('admin.purchasing.errors.invalid_supplier_response')]);
            }

            $note = mb_trim($note);
            if ($note === '') {
                throw ValidationException::withMessages(['notes' => __('admin.purchasing.errors.response_note_required')]);
            }

            if ($outcome !== SupplierConfirmationStatus::Rejected && ! $promisedAt instanceof CarbonImmutable) {
                throw ValidationException::withMessages(['promised_at' => __('admin.purchasing.errors.promise_date_required')]);
            }
            if ($promisedAt instanceof CarbonImmutable) {
                $lockedOrder = $locked->purchaseOrder;
                if (! $lockedOrder instanceof PurchaseOrder) {
                    throw new LogicException('Supplier confirmation has no purchase order.');
                }

                $this->assertPromisedDate($lockedOrder, $promisedAt);
            }

            $items = $locked->items()->lockForUpdate()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.confirmation_items_required')]);
            }

            $provided = collect($quantities)->keyBy('id');
            $hasBackorder = false;

            foreach ($items as $item) {
                if ($outcome === SupplierConfirmationStatus::Rejected) {
                    $this->applyItemResponse($item, SupplierConfirmationStatus::Rejected, '0.000000', '0.000000', null, $actor);

                    continue;
                }

                $input = $provided->get($item->id);
                if (! is_array($input)) {
                    throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.all_confirmation_lines_required')]);
                }

                [$confirmed, $backordered] = $this->validatedCommitmentQuantities($item, $input);
                $hasBackorder = $hasBackorder || bccomp($backordered, '0.000000', 6) === 1;

                if ($outcome === SupplierConfirmationStatus::Confirmed && bccomp($backordered, '0.000000', 6) !== 0) {
                    throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.confirmed_response_cannot_backorder')]);
                }

                $itemStatus = bccomp($backordered, '0.000000', 6) === 1
                    ? SupplierConfirmationStatus::Partial
                    : SupplierConfirmationStatus::Confirmed;

                $this->applyItemResponse($item, $itemStatus, $confirmed, $backordered, $promisedAt, $actor);
            }

            if ($outcome === SupplierConfirmationStatus::Partial && ! $hasBackorder) {
                throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.partial_response_requires_backorder')]);
            }

            $locked->forceFill([
                'confirmation_status' => $outcome,
                'promised_at' => $outcome === SupplierConfirmationStatus::Rejected ? null : $promisedAt->toDateString(),
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'notes' => $note,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['confirmation_status' => $outcome->value]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('purchasing.confirmation.answered');

            return $locked->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:numeric-string,1:numeric-string}
     */
    private function validatedCommitmentQuantities(SupplierConfirmationItem $item, array $input): array
    {
        $requested = $this->normalizeQuantity($item->requested_base_quantity, 'requested_base_quantity');
        $confirmed = $this->normalizeQuantity($input['confirmed_base_quantity'] ?? null, 'confirmed_base_quantity');
        $backordered = $this->normalizeQuantity($input['backordered_base_quantity'] ?? null, 'backordered_base_quantity');
        $sum = bcadd($confirmed, $backordered, 6);

        if (bccomp($confirmed, $requested, 6) === 1 || bccomp($backordered, $requested, 6) === 1) {
            throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.confirmation_quantity_exceeds_requested')]);
        }
        if (bccomp($sum, $requested, 6) !== 0) {
            throw ValidationException::withMessages(['items' => __('admin.purchasing.errors.confirmation_quantity_sum')]);
        }

        return [$confirmed, $backordered];
    }

    private function applyItemResponse(
        SupplierConfirmationItem $item,
        SupplierConfirmationStatus $status,
        string $confirmed,
        string $backordered,
        ?CarbonImmutable $promisedAt,
        User $actor,
    ): void {
        $item->forceFill([
            'confirmation_status' => $status,
            'confirmed_base_quantity' => $confirmed,
            'backordered_base_quantity' => $backordered,
            'promised_at' => $promisedAt?->toDateString(),
            'confirmed_by' => $actor->getKey(),
            'confirmed_at' => now(),
        ])->save();
    }

    /** @return numeric-string */
    private function requestedBaseQuantityForNewEvidence(PurchaseOrderLine $line): string
    {
        if (SupplierConfirmationItem::query()
            ->where('purchase_order_line_id', $line->id)
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->exists()) {
            throw ValidationException::withMessages([
                'items' => __('admin.purchasing.errors.pending_confirmation_exists'),
            ]);
        }

        $quantities = $this->commitments->quantities($line);
        $outstanding = bcsub(
            $quantities['ordered'],
            bcadd($quantities['confirmed'], $quantities['unavailable'], 6),
            6,
        );

        return bccomp($outstanding, '0.000000', 6) === -1 ? '0.000000' : $outstanding;
    }

    /** @return numeric-string */
    private function requestedTransactionQuantity(PurchaseOrderLine $line, string $baseQuantity): string
    {
        $factor = $line->conversion_factor_snapshot;
        if ($factor === null) {
            $line->loadMissing('productVariant:id,unit_id');
            if ($line->productVariant->unit_id === $line->unit_id) {
                $factor = '1.000000';
            }
        }

        if ($factor === null || bccomp($factor, '0.000000', 6) !== 1) {
            throw ValidationException::withMessages([
                'items' => __('admin.purchasing.errors.missing_uom_snapshot'),
            ]);
        }

        return bcdiv($baseQuantity, $factor, 3);
    }

    /** @return numeric-string */
    private function normalizeQuantity(mixed $quantity, string $field): string
    {
        if ((! is_int($quantity) && ! is_float($quantity) && ! is_string($quantity)) || ! is_numeric($quantity)) {
            throw ValidationException::withMessages([$field => __('admin.purchasing.errors.quantity_non_negative')]);
        }

        $normalized = bcadd('0.000000', (string) $quantity, 6);
        if (bccomp($normalized, '0.000000', 6) === -1) {
            throw ValidationException::withMessages([$field => __('admin.purchasing.errors.quantity_non_negative')]);
        }

        return $normalized;
    }

    private function assertPromisedDate(PurchaseOrder $order, CarbonImmutable $promisedAt): void
    {
        $orderedAt = $order->ordered_at;
        if ($promisedAt->startOfDay()->lessThan($orderedAt->copy()->startOfDay())) {
            throw InvalidConfirmationTarget::promisedBeforeOrdered($promisedAt, $orderedAt);
        }
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'purchaseOrder',
            'supplier',
            'items.productVariant.product.brand',
            'items.purchaseOrderLine.supplierProductReference',
            'items.confirmedBy',
        ];
    }
}
