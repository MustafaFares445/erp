<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Data\Purchasing\SupplierConfirmationRequestData;
use App\Enums\SupplierConfirmationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Services\Purchasing\Exceptions\ConfirmationNotAmendable;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class SupplierConfirmationService
{
    private const array SupportedTargets = [PurchaseOrder::class, Order::class, Quotation::class];

    public function __construct(private SupplierSupportResolver $supportResolver) {}

    public function record(User $actor, Model $target, int $supplierId, ?string $notes = null): SupplierConfirmation
    {
        Gate::forUser($actor)->authorize('create', SupplierConfirmation::class);

        return DB::transaction(function () use ($actor, $target, $supplierId, $notes): SupplierConfirmation {
            $this->assertTargetIsSupported($target);

            $confirmation = $this->newConfirmation($actor, $target, $supplierId, $this->customerFor($target, null), $notes);

            $this->reactOnCustomerOrder($target, SupplierConfirmationStatus::Pending, $notes);

            return $confirmation->refresh();
        });
    }

    public function recordItems(User $actor, SupplierConfirmationRequestData $request): SupplierConfirmation
    {
        Gate::forUser($actor)->authorize('request', SupplierConfirmation::class);

        return DB::transaction(function () use ($actor, $request): SupplierConfirmation {
            if ($request->target instanceof Model) {
                $this->assertTargetIsSupported($request->target);
            }

            $customer = $this->customerFor($request->target, $request->customer);
            $items = $this->validatedItems($request->items);
            $this->assertSupplierSupports($request->supplierId, $items);

            $confirmation = $this->newConfirmation($actor, $request->target, $request->supplierId, $customer, $request->notes);
            $confirmation->items()->createMany($items);

            if ($request->target instanceof Order) {
                $this->recalculateOrderStatus($request->target);
            }

            return $confirmation->load(['customer', 'items.productVariant', 'supplier']);
        });
    }

    /**
     * @param  list<array{id: int, confirmation_status: SupplierConfirmationStatus, promised_at?: CarbonImmutable|null, notes?: string|null}>  $answers
     */
    public function answerItems(User $actor, SupplierConfirmation $confirmation, array $answers): SupplierConfirmation
    {
        Gate::forUser($actor)->authorize('answer', $confirmation);

        return DB::transaction(function () use ($actor, $confirmation, $answers): SupplierConfirmation {
            /** @var SupplierConfirmation $locked */
            $locked = SupplierConfirmation::query()->lockForUpdate()->findOrFail($confirmation->getKey());
            $items = $locked->items()->lockForUpdate()->get()->keyBy('id');

            if ($items->isEmpty()) {
                throw ConfirmationNotAmendable::alreadyAnswered($locked);
            }

            $this->answerPendingItems($actor, $locked, $items, $answers);
            $this->refreshItemStatus($locked, $actor);

            if ($locked->confirmable instanceof Order) {
                $this->recalculateOrderStatus($locked->confirmable);
            }

            return $locked->load(['items.productVariant', 'items.confirmedBy']);
        });
    }

    public function answer(
        User $actor,
        SupplierConfirmation $confirmation,
        SupplierConfirmationStatus $outcome,
        ?CarbonImmutable $promisedAt = null,
        ?string $notes = null,
    ): SupplierConfirmation {
        Gate::forUser($actor)->authorize('answer', $confirmation);

        return DB::transaction(function () use ($actor, $confirmation, $outcome, $promisedAt, $notes): SupplierConfirmation {
            /** @var SupplierConfirmation $locked */
            $locked = SupplierConfirmation::query()->lockForUpdate()->findOrFail($confirmation->getKey());

            if (! $locked->confirmation_status->canTransitionTo($outcome)) {
                throw ConfirmationNotAmendable::alreadyAnswered($locked);
            }

            $target = $locked->confirmable;

            if ($promisedAt instanceof CarbonImmutable && $target instanceof Model) {
                $this->assertPromisedDateIsNotBeforeDocument($target, $promisedAt);
            }

            $locked->forceFill([
                'confirmation_status' => $outcome,
                'promised_at' => $promisedAt?->toDateString(),
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'notes' => $notes ?? $locked->notes,
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($target instanceof Order) {
                if ($locked->items()->exists()) {
                    $this->recalculateOrderStatus($target);
                } else {
                    $this->reactOnCustomerOrder($target, $outcome, $notes ?? $locked->notes);
                }
            }

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['confirmation_status' => $outcome->value]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('purchasing.confirmation.answered');

            return $locked->refresh();
        });
    }

    private function newConfirmation(User $actor, ?Model $target, int $supplierId, ?CustomerProfile $customer, ?string $notes): SupplierConfirmation
    {
        $confirmation = new SupplierConfirmation([
            'confirmable_type' => $target instanceof Model ? $target::class : null,
            'confirmable_id' => $target?->getKey(),
            'supplier_id' => $supplierId,
            'customer_id' => $customer?->getKey(),
            'notes' => $notes,
        ]);

        $confirmation->forceFill([
            'confirmation_status' => SupplierConfirmationStatus::Pending,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $confirmation;
    }

    /**
     * @param  list<array{product_variant_id: int, requested_quantity: float, notes?: string|null}>  $items
     */
    private function assertSupplierSupports(int $supplierId, array $items): void
    {
        $productVariantIds = array_column($items, 'product_variant_id');

        if (! in_array($supplierId, $this->supportResolver->eligibleSupplierIds($productVariantIds), true)) {
            throw ValidationException::withMessages(['supplier_id' => 'The selected supplier does not support every requested product.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_variant_id: int, requested_quantity: float, notes?: string|null}>
     */
    private function validatedItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one product.']);
        }

        $validatedItems = [];

        foreach ($items as $item) {
            $variantId = $item['product_variant_id'] ?? null;
            $quantity = $item['requested_quantity'] ?? null;

            if (! is_int($variantId) || ! is_numeric($quantity) || (float) $quantity <= 0.0) {
                throw ValidationException::withMessages(['items' => 'Each selected product needs a valid quantity.']);
            }

            if (array_key_exists($variantId, $validatedItems)) {
                throw ValidationException::withMessages(['items' => 'A product may only be selected once per confirmation.']);
            }

            $validatedItems[$variantId] = [
                'product_variant_id' => $variantId,
                'requested_quantity' => (float) $quantity,
                'notes' => is_string($item['notes'] ?? null) ? $item['notes'] : null,
            ];
        }

        return array_values($validatedItems);
    }

    /**
     * @param  Collection<int, SupplierConfirmationItem>  $items
     * @param  list<array{id: int, confirmation_status: SupplierConfirmationStatus, promised_at?: CarbonImmutable|null, notes?: string|null}>  $answers
     */
    private function answerPendingItems(User $actor, SupplierConfirmation $confirmation, Collection $items, array $answers): void
    {
        foreach ($answers as $answer) {
            $item = $items->get($answer['id']);

            if (! $item instanceof SupplierConfirmationItem || $item->isAnswered() || ! $answer['confirmation_status']->isAnswered()) {
                throw ConfirmationNotAmendable::alreadyAnswered($confirmation);
            }

            $promisedAt = $answer['promised_at'] ?? null;

            if ($promisedAt instanceof CarbonImmutable && $confirmation->confirmable instanceof Model) {
                $this->assertPromisedDateIsNotBeforeDocument($confirmation->confirmable, $promisedAt);
            }

            $item->forceFill([
                'confirmation_status' => $answer['confirmation_status'],
                'promised_at' => $promisedAt?->toDateString(),
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'notes' => $answer['notes'] ?? $item->notes,
            ])->save();
        }
    }

    private function refreshItemStatus(SupplierConfirmation $confirmation, User $actor): void
    {
        $statuses = $confirmation->items()
            ->get(['id', 'confirmation_status'])
            ->map(static fn (SupplierConfirmationItem $item): SupplierConfirmationStatus => $item->confirmation_status);

        $status = $statuses->contains(static fn (SupplierConfirmationStatus $status): bool => $status === SupplierConfirmationStatus::Pending)
            ? SupplierConfirmationStatus::Pending
            : ($statuses->every(static fn (SupplierConfirmationStatus $status): bool => $status === SupplierConfirmationStatus::Confirmed)
                ? SupplierConfirmationStatus::Confirmed
                : ($statuses->every(static fn (SupplierConfirmationStatus $status): bool => $status === SupplierConfirmationStatus::Rejected)
                    ? SupplierConfirmationStatus::Rejected
                    : SupplierConfirmationStatus::Partial));

        $confirmation->forceFill([
            'confirmation_status' => $status,
            'updated_by' => $actor->getKey(),
        ])->save();
    }

    private function customerFor(?Model $target, ?CustomerProfile $customer): ?CustomerProfile
    {
        if (! $target instanceof Order && ! $target instanceof Quotation) {
            return $target instanceof PurchaseOrder ? null : $customer;
        }

        $sourceCustomer = $target->customer;

        if (! $sourceCustomer instanceof CustomerProfile) {
            throw ValidationException::withMessages(['customer_id' => 'The linked document has no customer.']);
        }

        if ($customer instanceof CustomerProfile && $customer->getKey() !== $sourceCustomer->getKey()) {
            throw ValidationException::withMessages(['customer_id' => 'The linked document belongs to a different customer.']);
        }

        return $sourceCustomer;
    }

    private function recalculateOrderStatus(Order $order): void
    {
        $statuses = $order->confirmations()
            ->with('items:id,supplier_confirmation_id,confirmation_status')
            ->get()
            ->flatMap(static function (SupplierConfirmation $confirmation): array {
                if ($confirmation->items->isEmpty()) {
                    return [$confirmation->confirmation_status];
                }

                return $confirmation->items->pluck('confirmation_status')->all();
            });

        if ($statuses->isEmpty()) {
            return;
        }

        $status = $statuses->contains(static fn (SupplierConfirmationStatus $status): bool => $status === SupplierConfirmationStatus::Pending)
            ? 'pending_supplier_confirmation'
            : ($statuses->contains(static fn (SupplierConfirmationStatus $status): bool => $status === SupplierConfirmationStatus::Rejected)
                ? 'supplier_rejected'
                : 'supplier_confirmed');

        $procurementStillOpen = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->exists();

        $order->forceFill([
            'status' => $status,
            'pending_reason' => $status === 'supplier_confirmed'
                ? ($procurementStillOpen
                    ? 'Supplier confirmed. Purchase and receipt must complete before fulfillment can resume.'
                    : null)
                : $order->pending_reason,
        ])->save();
    }

    private function reactOnCustomerOrder(Model $target, SupplierConfirmationStatus $outcome, ?string $notes): void
    {
        if (! $target instanceof Order) {
            return;
        }

        $status = match ($outcome) {
            SupplierConfirmationStatus::Pending, SupplierConfirmationStatus::Partial => 'pending_supplier_confirmation',
            SupplierConfirmationStatus::Confirmed => 'supplier_confirmed',
            SupplierConfirmationStatus::Rejected => 'supplier_rejected',
        };

        $target->forceFill([
            'status' => $status,
            'pending_reason' => $outcome === SupplierConfirmationStatus::Confirmed ? null : $notes,
        ])->save();
    }

    private function assertTargetIsSupported(Model $target): void
    {
        if (! in_array($target::class, self::SupportedTargets, true)) {
            throw InvalidConfirmationTarget::unsupportedType();
        }
    }

    private function assertPromisedDateIsNotBeforeDocument(Model $target, CarbonImmutable $promisedAt): void
    {
        $documentDate = match (true) {
            $target instanceof PurchaseOrder => $target->ordered_at,
            $target instanceof Quotation => $target->issue_date,
            $target instanceof Order => $target->created_at,
            default => null,
        };

        if (! $documentDate instanceof CarbonInterface) {
            return;
        }

        if ($promisedAt->startOfDay()->lessThan($documentDate->copy()->startOfDay())) {
            throw InvalidConfirmationTarget::promisedBeforeOrdered($promisedAt, $documentDate);
        }
    }
}
