<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseRfqStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqLine;
use App\Models\PurchaseRfqResponseLine;
use App\Models\PurchaseRfqSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class PurchaseRfqService
{
    public function __construct(
        private PurchaseRfqNumberGenerator $numbers,
        private PurchaseOrderService $purchaseOrders,
        private CurrencyCatalogService $currencies,
    ) {}

    /** @param array{currency_code:string,needed_by?:string|null,closes_at?:string|null,notes?:string|null} $attributes
     *  @param list<array{product_variant_id:int,unit_id:int,quantity:float|string,notes?:string|null}> $lines
     *  @param list<int> $supplierIds */
    public function create(User $actor, array $attributes, array $lines, array $supplierIds): PurchaseRfq
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        if ($lines === [] || $supplierIds === []) {
            throw new DomainException('An RFQ requires at least one line and one supplier.');
        }

        return DB::transaction(function () use ($actor, $attributes, $lines, $supplierIds): PurchaseRfq {
            $currency = $this->currencies->normalizeActive($attributes['currency_code'], 'currency_code');
            $rfq = new PurchaseRfq([
                'currency_code' => $currency,
                'needed_by' => $attributes['needed_by'] ?? null,
                'closes_at' => $attributes['closes_at'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);
            $rfq->forceFill([
                'rfq_number' => $this->numbers->next(),
                'status' => PurchaseRfqStatus::Draft,
                'requested_by' => $actor->getKey(),
            ])->save();

            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    throw new DomainException('RFQ line quantity must be positive.');
                }
                $rfq->lines()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'unit_id' => $line['unit_id'],
                    'quantity' => $quantity,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            foreach (array_values(array_unique($supplierIds)) as $supplierId) {
                $supplier = Supplier::query()->findOrFail($supplierId);
                if (! $supplier->is_active) {
                    throw new DomainException("Supplier {$supplier->name} is inactive.");
                }

                $rfq->suppliers()->create(['supplier_id' => $supplierId]);
            }

            return $rfq->refresh()->load(['lines', 'suppliers.supplier']);
        });
    }

    public function send(User $actor, PurchaseRfq $rfq): PurchaseRfq
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        return DB::transaction(function () use ($rfq): PurchaseRfq {
            /** @var PurchaseRfq $locked */
            $locked = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());
            if ($locked->status !== PurchaseRfqStatus::Draft) {
                throw new DomainException('Only a draft RFQ can be sent.');
            }
            if ($locked->closes_at !== null && $locked->closes_at->isPast()) {
                throw new DomainException('An RFQ whose closing time has passed cannot be sent.');
            }

            $now = now();
            $locked->forceFill(['status' => PurchaseRfqStatus::AwaitingResponses, 'sent_at' => $now])->save();
            $locked->suppliers()->update(['status' => 'pending', 'sent_at' => $now]);

            return $locked->refresh();
        });
    }

    /** @param list<array{rfq_line_id:int,unit_price:float|string,offered_quantity:float|string,lead_time_days?:int|null,minimum_order_quantity?:float|string|null,notes?:string|null}> $responses */
    public function recordResponse(User $actor, PurchaseRfqSupplier $rfqSupplier, array $responses): PurchaseRfqSupplier
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        if ($responses === []) {
            throw new DomainException('At least one response line is required.');
        }

        return DB::transaction(function () use ($rfqSupplier, $responses): PurchaseRfqSupplier {
            /** @var PurchaseRfqSupplier $locked */
            $locked = PurchaseRfqSupplier::query()->lockForUpdate()->findOrFail($rfqSupplier->getKey());
            $rfq = $locked->rfq;
            if (! $rfq instanceof PurchaseRfq) {
                throw new DomainException('RFQ supplier is not linked to an RFQ.');
            }

            if (! $rfq->status->acceptsResponses()) {
                throw new DomainException('This RFQ is not open for supplier responses.');
            }
            if ($rfq->closes_at !== null && $rfq->closes_at->isPast()) {
                throw new DomainException('This RFQ is past its closing time.');
            }

            foreach ($responses as $response) {
                /** @var PurchaseRfqLine $line */
                $line = $rfq->lines()->whereKey($response['rfq_line_id'])->firstOrFail();
                $unitPrice = (float) $response['unit_price'];
                $offeredQuantity = (float) $response['offered_quantity'];

                if ($unitPrice < 0 || $offeredQuantity <= 0) {
                    throw new DomainException('Supplier response price and quantity are invalid.');
                }

                $locked->responseLines()->updateOrCreate(
                    ['purchase_rfq_line_id' => $line->getKey()],
                    [
                        'unit_price' => $unitPrice,
                        'offered_quantity' => $offeredQuantity,
                        'lead_time_days' => $response['lead_time_days'] ?? null,
                        'minimum_order_quantity' => $response['minimum_order_quantity'] ?? null,
                        'notes' => $response['notes'] ?? null,
                    ],
                );
            }

            $locked->forceFill(['status' => 'responded', 'responded_at' => now()])->save();
            $respondedCount = $rfq->suppliers()->whereNotNull('responded_at')->count();
            $supplierCount = $rfq->suppliers()->count();
            $rfq->forceFill([
                'status' => $respondedCount >= $supplierCount
                    ? PurchaseRfqStatus::Evaluating
                    : PurchaseRfqStatus::PartiallyResponded,
            ])->save();

            return $locked->refresh()->load('responseLines');
        });
    }

    public function award(User $actor, PurchaseRfqSupplier $rfqSupplier): PurchaseOrder
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqAward->value);

        return DB::transaction(function () use ($actor, $rfqSupplier): PurchaseOrder {
            /** @var PurchaseRfqSupplier $candidate */
            $candidate = PurchaseRfqSupplier::query()->with(['rfq.lines', 'responseLines'])->lockForUpdate()->findOrFail($rfqSupplier->getKey());
            $rfq = $candidate->rfq;
            if (! $rfq instanceof PurchaseRfq) {
                throw new DomainException('RFQ supplier is not linked to an RFQ.');
            }

            if (! $rfq->status->isAwardable()) {
                throw new DomainException('Only an RFQ with supplier responses under evaluation can be awarded.');
            }
            $responses = $candidate->responseLines->keyBy('purchase_rfq_line_id');
            $poLines = [];

            foreach ($rfq->lines as $line) {
                $lineKey = $line->getKey();
                if (! is_int($lineKey) && ! is_string($lineKey)) {
                    throw new DomainException('RFQ line has an invalid identifier.');
                }

                $response = $responses->get($lineKey);
                if (! $response instanceof PurchaseRfqResponseLine) {
                    throw new DomainException('The selected supplier must quote every RFQ line before award.');
                }

                $poLines[] = [
                    'product_variant_id' => $line->product_variant_id,
                    'unit_id' => $line->unit_id,
                    'quantity_ordered' => $line->quantity,
                    'unit_cost' => $response->unit_price,
                ];
            }

            $order = $this->purchaseOrders->createDraftWithLines($actor, [
                'supplier_id' => $candidate->supplier_id,
                'currency_code' => $rfq->currency_code,
                'ordered_at' => now()->toDateString(),
                'expected_at' => $rfq->needed_by?->toDateString(),
                'notes' => "Awarded from {$rfq->rfq_number}. ".($rfq->notes ?? ''),
            ], $poLines);

            $rfq->forceFill([
                'status' => PurchaseRfqStatus::Awarded,
                'awarded_supplier_id' => $candidate->supplier_id,
                'awarded_purchase_order_id' => $order->getKey(),
                'awarded_at' => now(),
            ])->save();

            return $order;
        });
    }

    public function close(User $actor, PurchaseRfq $rfq): PurchaseRfq
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        return DB::transaction(function () use ($rfq): PurchaseRfq {
            /** @var PurchaseRfq $locked */
            $locked = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());
            if (! $locked->status->canClose()) {
                throw new DomainException('Only an awarded RFQ can be closed.');
            }

            $locked->forceFill(['status' => PurchaseRfqStatus::Closed, 'closed_at' => now()])->save();

            return $locked->refresh();
        });
    }

    public function cancel(User $actor, PurchaseRfq $rfq): PurchaseRfq
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        return DB::transaction(function () use ($rfq): PurchaseRfq {
            /** @var PurchaseRfq $locked */
            $locked = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());
            if (! $locked->status->canCancel()) {
                throw new DomainException('This RFQ can no longer be cancelled.');
            }

            $locked->forceFill(['status' => PurchaseRfqStatus::Cancelled, 'cancelled_at' => now()])->save();

            return $locked->refresh();
        });
    }

    public function expire(User $actor, PurchaseRfq $rfq): PurchaseRfq
    {
        Gate::forUser($actor)->authorize(PurchasePermission::RfqManage->value);

        return DB::transaction(function () use ($rfq): PurchaseRfq {
            /** @var PurchaseRfq $locked */
            $locked = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());
            if ($locked->status->isTerminal() || $locked->status === PurchaseRfqStatus::Awarded) {
                throw new DomainException('This RFQ cannot be expired.');
            }
            if ($locked->closes_at === null || ! $locked->closes_at->isPast()) {
                throw new DomainException('Only an RFQ past its closing time can be expired.');
            }

            $locked->forceFill(['status' => PurchaseRfqStatus::Expired, 'expired_at' => now()])->save();

            return $locked->refresh();
        });
    }
}
