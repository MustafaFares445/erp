<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OperationStage;
use App\Enums\OrderStatus;
use App\Events\SalesOrderReleased;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\QuantityNormalizer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class SalesOrderService
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private QuantityNormalizer $quantityNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(User $actor, array $attributes, array $lines): Order
    {
        Gate::forUser($actor)->authorize('create', Order::class);

        return DB::transaction(function () use ($actor, $attributes, $lines): Order {
            $customer = $this->activeCustomer($attributes['customer_id'] ?? null);
            $this->assertHasLines($lines);

            $order = Order::query()->create([
                'order_number' => $this->numberGenerator->next(Order::query(), 'order_number', 'SO-'),
                'customer_id' => $customer->getKey(),
                'customer_delivery_address_id' => $attributes['customer_delivery_address_id'] ?? null,
                'status' => OrderStatus::Draft,
                'scheduled_at' => $attributes['scheduled_at'] ?? null,
                'delivery_type' => $attributes['delivery_type'] ?? null,
                'responsible_id' => $attributes['responsible_id'] ?? null,
                'destination_address_snapshot' => $attributes['destination_address_snapshot'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'payment_term_id' => $attributes['payment_term_id'] ?? $customer->default_payment_term_id,
                'subtotal' => 0,
                'tax_total' => 0,
                'grand_total' => 0,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->replaceLines($order, $lines);

            activity()->performedOn($order)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.order.created');

            return $order->refresh()->load('lines');
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraft(User $actor, Order $order, array $attributes, array $lines): Order
    {
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $attributes, $lines): Order {
            $locked = $this->lock($order);
            $this->assertStatus($locked, OrderStatus::Draft);
            $this->assertHasLines($lines);

            if (array_key_exists('customer_id', $attributes)) {
                $attributes['customer_id'] = $this->activeCustomer($attributes['customer_id'])->getKey();
            }

            $locked->fill(collect($attributes)->only([
                'customer_id', 'customer_delivery_address_id', 'scheduled_at', 'delivery_type',
                'responsible_id', 'destination_address_snapshot', 'notes', 'payment_term_id',
            ])->all());
            $locked->forceFill(['updated_by' => $actor->getKey()])->save();

            $locked->lines()->delete();
            $locked->forceFill(['subtotal' => 0, 'tax_total' => 0, 'grand_total' => 0])->save();
            $this->replaceLines($locked, $lines);

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.order.updated');

            return $locked->refresh()->load('lines');
        }, attempts: 5);
    }

    public function confirm(User $actor, Order $order): Order
    {
        Gate::forUser($actor)->authorize('confirm', $order);

        return DB::transaction(function () use ($actor, $order): Order {
            $locked = $this->lockWithLines($order);
            $this->assertStatus($locked, OrderStatus::Draft);
            $this->assertCommerciallyComplete($locked);

            $locked->forceFill([
                'status' => OrderStatus::Confirmed,
                'confirmed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.order.confirmed');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function release(User $actor, Order $order): Order
    {
        Gate::forUser($actor)->authorize('release', $order);

        $released = DB::transaction(function () use ($actor, $order): Order {
            $locked = $this->lockWithLines($order);
            $this->assertStatus($locked, OrderStatus::Confirmed);
            $this->assertCommerciallyComplete($locked);

            $locked->forceFill([
                'status' => OrderStatus::Released,
                'released_at' => now(),
                'pending_reason' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.order.released');

            return $locked->refresh();
        }, attempts: 5);

        SalesOrderReleased::dispatch($released);

        return $released;
    }

    /** @param array<int|string, float|int|string> $lineQuantities */
    public function shortClose(User $actor, Order $order, array $lineQuantities, string $reason): Order
    {
        Gate::forUser($actor)->authorize('close', $order);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to short-close sales demand.']);
        }

        return DB::transaction(function () use ($actor, $order, $lineQuantities, $reason): Order {
            $locked = $this->lockWithLines($order);
            $this->assertStatus($locked, OrderStatus::Released);
            $planned = $this->plannedBaseByOrderLine($locked);

            foreach ($lineQuantities as $lineId => $requested) {
                if (! is_numeric($requested) || (float) $requested < 0) {
                    throw ValidationException::withMessages(['short_close' => 'Short-close quantities must be non-negative.']);
                }

                $line = $locked->lines->firstWhere('id', (int) $lineId);
                if (! $line instanceof OrderLine) {
                    throw ValidationException::withMessages(['short_close' => 'A selected order line is invalid.']);
                }

                $ordered = (float) ($line->base_quantity ?? 0);
                $alreadyShort = (float) $line->short_closed_base_quantity;
                $remaining = max(0.0, $ordered - ($planned[$line->id] ?? 0.0) - $alreadyShort);
                $quantity = round((float) $requested, 6);

                if ($quantity > $remaining + 0.000001) {
                    throw ValidationException::withMessages([
                        'short_close' => 'A short-close quantity exceeds the remaining unplanned quantity.',
                    ]);
                }

                $line->forceFill([
                    'short_closed_base_quantity' => number_format($alreadyShort + $quantity, 6, '.', ''),
                ])->save();
            }

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('sales.order.short_closed');

            return $locked->refresh()->load('lines');
        }, attempts: 5);
    }

    public function close(User $actor, Order $order, ?string $reason = null): Order
    {
        Gate::forUser($actor)->authorize('close', $order);

        return DB::transaction(function () use ($actor, $order, $reason): Order {
            $locked = $this->lockWithLines($order);
            $this->assertStatus($locked, OrderStatus::Released);
            $planned = $this->plannedBaseByOrderLine($locked);

            foreach ($locked->lines as $line) {
                $remaining = (float) ($line->base_quantity ?? 0)
                    - (float) $line->short_closed_base_quantity
                    - ($planned[$line->id] ?? 0.0);

                if ($remaining > 0.000001) {
                    throw new DomainException('The sales order still has unplanned fulfillment quantity. Short-close the remainder first.');
                }
            }

            $locked->forceFill([
                'status' => OrderStatus::Closed,
                'closed_at' => now(),
                'pending_reason' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('sales.order.closed');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function cancel(User $actor, Order $order, string $reason): Order
    {
        Gate::forUser($actor)->authorize('cancel', $order);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
        }

        return DB::transaction(function () use ($actor, $order, $reason): Order {
            $locked = $this->lock($order);

            if ($locked->deliveries()->where('stage', '!=', OperationStage::Canceled->value)->exists()) {
                throw new DomainException('Resolve or cancel Logistics execution before cancelling the sales order.');
            }

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'pending_reason' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('sales.order.cancelled');

            return $locked->refresh();
        }, attempts: 5);
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $variant = ProductVariant::query()
                ->whereKey($this->integerInput($line['product_variant_id'] ?? 0))
                ->where('is_active', true)
                ->first();

            if (! $variant instanceof ProductVariant) {
                throw ValidationException::withMessages(['lines' => 'One or more selected product variants are unavailable.']);
            }

            $quantity = $line['quantity'] ?? $line['transaction_quantity'] ?? null;
            if ($this->numericInput($quantity) <= 0) {
                throw ValidationException::withMessages(['lines' => 'Every sales order line requires a positive quantity.']);
            }

            $quantityValue = $this->numericInput($quantity);
            $unitId = $this->integerInput($line['unit_id'] ?? $line['transaction_unit_id'] ?? $variant->unit_id);
            $snapshot = $this->quantityNormalizer->normalize($variant, $unitId, (string) $quantityValue);

            $order->lines()->create([
                'product_variant_id' => $variant->getKey(),
                'quantity' => $snapshot->transactionQuantity,
                'unit_id' => $snapshot->transactionUnitId,
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'unit_price' => $line['unit_price'] ?? null,
                'tax_amount' => $line['tax_amount'] ?? null,
                'price_floor_override_id' => $line['price_floor_override_id'] ?? null,
            ]);
        }
    }

    private function assertCommerciallyComplete(Order $order): void
    {
        if (! $order->customer()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'The sales order requires an active customer.']);
        }

        if ($order->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'The sales order requires at least one line.']);
        }

        foreach ($order->lines as $line) {
            if ((float) ($line->base_quantity ?? 0) <= 0
                || $line->transaction_unit_id === null
                || $line->conversion_factor_snapshot === null
                || $line->unit_price === null
                || $line->tax_amount === null
                || $line->line_total === null) {
                throw ValidationException::withMessages(['lines' => 'Every line must have a frozen UOM, quantity, price and tax snapshot before confirmation.']);
            }
        }
    }

    /** @return array<int, float> */
    private function plannedBaseByOrderLine(Order $order): array
    {
        $planned = [];

        foreach ($order->deliveries()
            ->where('stage', '!=', OperationStage::Canceled->value)
            ->with('lines:id,inventory_operation_id,order_line_id,quantity')
            ->get() as $delivery) {
            foreach ($delivery->lines as $line) {
                if ($line->order_line_id === null) {
                    continue;
                }

                $planned[$line->order_line_id] = round(
                    ($planned[$line->order_line_id] ?? 0.0) + $this->numericInput($line->quantity),
                    6,
                );
            }
        }

        return $planned;
    }

    private function integerInput(mixed $value): int
    {
        if (! is_numeric($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Expected an integer value.');
        }

        return (int) $value;
    }

    private function numericInput(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new DomainException('Expected a numeric value.');
        }

        return (float) $value;
    }

    private function lock(Order $order): Order
    {
        return Order::query()->whereKey($order->getKey())->lockForUpdate()->sole();
    }

    private function lockWithLines(Order $order): Order
    {
        $locked = $this->lock($order);
        $locked->setRelation('lines', $locked->lines()->orderBy('id')->lockForUpdate()->get());

        return $locked;
    }

    private function assertStatus(Order $order, OrderStatus $expected): void
    {
        if ($order->status !== $expected) {
            throw new DomainException("Sales order must be {$expected->label()} for this action.");
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function assertHasLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product to the sales order.']);
        }
    }

    private function activeCustomer(mixed $customerId): CustomerProfile
    {
        if (! is_numeric($customerId)) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
        }

        $customer = CustomerProfile::query()
            ->whereKey((int) $customerId)
            ->where('is_active', true)
            ->first();

        if (! $customer instanceof CustomerProfile) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
        }

        return $customer;
    }
}
