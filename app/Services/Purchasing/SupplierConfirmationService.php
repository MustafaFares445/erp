<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\SupplierConfirmationStatus;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\Exceptions\ConfirmationNotAmendable;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Recording what a supplier said, against either a purchase order or a customer
 * order.
 *
 * Two document types, one record type. The `confirmable` morph is restricted
 * here rather than in the database, because a `varchar` column accepts whatever
 * it is given (V-09).
 *
 * Answering a customer order's confirmation moves that order's own status: a
 * confirmed supply becomes `supplier_confirmed`, a rejection becomes
 * `supplier_rejected` and keeps the reason in `pending_reason` (FR-033). A
 * purchase order is deliberately **not** moved the same way — a supplier
 * declining is information the buyer acts on, not a lifecycle transition
 * (FR-034), so it surfaces as a flag while the order stays receivable.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-007
 */
final readonly class SupplierConfirmationService
{
    /** The only two documents a supplier can be asked to confirm. */
    private const array SUPPORTED_TARGETS = [PurchaseOrder::class, Order::class];

    /**
     * Opens a pending confirmation against a document.
     */
    public function record(User $actor, Model $target, int $supplierId, ?string $notes = null): SupplierConfirmation
    {
        Gate::forUser($actor)->authorize('create', SupplierConfirmation::class);

        return DB::transaction(function () use ($actor, $target, $supplierId, $notes): SupplierConfirmation {
            $this->assertTargetIsSupported($target);

            $confirmation = new SupplierConfirmation([
                'confirmable_type' => $target::class,
                'confirmable_id' => $target->getKey(),
                'supplier_id' => $supplierId,
                'notes' => $notes,
            ]);

            $confirmation->forceFill([
                'confirmation_status' => SupplierConfirmationStatus::Pending,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->reactOnCustomerOrder($target, SupplierConfirmationStatus::Pending, $notes);

            return $confirmation->refresh();
        });
    }

    /**
     * Records the supplier's answer. Permitted once, while the record is still
     * pending (V-11).
     */
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

            if ($promisedAt instanceof CarbonImmutable && ($target instanceof PurchaseOrder || $target instanceof Order)) {
                $this->assertPromisedDateIsNotBeforeOrdering($target, $promisedAt);
            }

            $locked->forceFill([
                'confirmation_status' => $outcome,
                'promised_at' => $promisedAt?->toDateString(),
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'notes' => $notes ?? $locked->notes,
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($target instanceof Model) {
                $this->reactOnCustomerOrder($target, $outcome, $notes ?? $locked->notes);
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

    /**
     * @throws InvalidConfirmationTarget
     */
    private function assertTargetIsSupported(Model $target): void
    {
        if (! in_array($target::class, self::SUPPORTED_TARGETS, true)) {
            throw InvalidConfirmationTarget::unsupportedType();
        }
    }

    /**
     * A supplier cannot promise a date earlier than the day the document was
     * raised (V-10).
     *
     * A purchase order carries its own `ordered_at`; a customer order has no
     * such column yet, so its creation date is the closest honest equivalent.
     *
     * @throws InvalidConfirmationTarget
     */
    private function assertPromisedDateIsNotBeforeOrdering(PurchaseOrder|Order $target, CarbonImmutable $promisedAt): void
    {
        $orderedAt = $target instanceof PurchaseOrder
            ? $target->ordered_at
            : $target->created_at;

        if (! $orderedAt instanceof CarbonInterface) {
            return;
        }

        if ($promisedAt->startOfDay()->lessThan($orderedAt->copy()->startOfDay())) {
            throw InvalidConfirmationTarget::promisedBeforeOrdered($promisedAt, $orderedAt);
        }
    }

    /**
     * Moves a customer order's status to match the supplier's answer (FR-033).
     *
     * Purchase orders are left alone on purpose — see the class docblock.
     */
    private function reactOnCustomerOrder(Model $target, SupplierConfirmationStatus $outcome, ?string $notes): void
    {
        if (! $target instanceof Order) {
            return;
        }

        $status = match ($outcome) {
            SupplierConfirmationStatus::Pending => 'pending_supplier_confirmation',
            SupplierConfirmationStatus::Confirmed => 'supplier_confirmed',
            SupplierConfirmationStatus::Rejected => 'supplier_rejected',
        };

        $target->forceFill([
            'status' => $status,
            // Kept only while the order is still waiting or has been declined.
            // A confirmed order is no longer pending on anything, so leaving a
            // reason behind would read as an unresolved problem.
            'pending_reason' => $outcome === SupplierConfirmationStatus::Confirmed ? null : $notes,
        ])->save();
    }
}
