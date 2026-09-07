<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\User;
use App\Services\Concerns\EnforcesMakerChecker;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotCancellable;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\Exceptions\SelfApprovalRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The purchase order lifecycle past draft: submission, approval, transmission,
 * short-close, and cancellation.
 *
 * The threshold is read at submission and never re-read (R-004). Changing it
 * does not re-evaluate anything already submitted, because submission is the
 * commitment point and "the rule in force when I committed" is the only
 * defensible reading. A draft edited over several days would otherwise carry
 * whichever threshold happened to be current when it was started.
 *
 * Auto-approval stamps `approved_by` with the submitter rather than leaving it
 * null. "Nobody approved it" is not a truthful record of who caused the state
 * change, and SC-005 requires every one to be attributable.
 *
 * Every transition locks the order row first, so two concurrent approvals
 * cannot both succeed — the second finds a status the matrix will not move from.
 *
 * @see /specs/017-purchasing-orders-suppliers/data-model.md §8
 */
final readonly class PurchaseOrderApprovalService
{
    use EnforcesMakerChecker;

    /**
     * Submits a draft. Below the threshold it approves itself (FR-020); above
     * it, or in a currency the threshold cannot be compared against, it waits.
     */
    public function submit(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('submit', $order);

        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            $locked = $this->lock($order);

            if (! $locked->status->isEditable()) {
                throw PurchaseOrderNotEditable::status($locked);
            }

            if ($locked->lines()->doesntExist()) {
                throw InvalidPurchaseOrderLine::noLines($locked->purchase_order_number);
            }

            $autoApproves = $this->qualifiesForAutoApproval($locked);

            $locked->forceFill([
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'status' => $autoApproves ? PurchaseOrderStatus::Approved : PurchaseOrderStatus::PendingApproval,
                'approved_by' => $autoApproves ? $actor->getKey() : null,
                'approved_at' => $autoApproves ? now() : null,
                'rejection_reason' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, $autoApproves ? 'purchasing.order.auto_approved' : 'purchasing.order.submitted', [
                'status' => $locked->status->value,
            ]);

            return $locked->refresh();
        });
    }

    public function approve(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('approve', $order);

        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertCanTransitionTo($locked, PurchaseOrderStatus::Approved);
            $this->assertNotSelfApproval($locked, $actor);

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejection_reason' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, 'purchasing.order.approved', ['status' => PurchaseOrderStatus::Approved->value]);

            return $locked->refresh();
        });
    }

    /**
     * Declines approval and returns the order to draft so the buyer can revise
     * it, keeping the reason on the record.
     */
    public function reject(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('approve', $order);

        return DB::transaction(function () use ($actor, $order, $reason): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertCanTransitionTo($locked, PurchaseOrderStatus::Rejected);

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Draft,
                'rejection_reason' => $reason,
                'approved_by' => null,
                'approved_at' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, 'purchasing.order.rejected', ['rejection_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * Marks the order as transmitted. This is the immutability boundary
     * (FR-025): supplier, warehouse, currency, lines, quantities, and costs are
     * frozen from here on.
     */
    public function send(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('send', $order);

        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertCanTransitionTo($locked, PurchaseOrderStatus::Sent);

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Sent,
                'sent_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, 'purchasing.order.sent', ['status' => PurchaseOrderStatus::Sent->value]);

            return $locked->refresh();
        });
    }

    /**
     * Abandons the outstanding quantity on a partially received order, keeping
     * what arrived.
     */
    public function close(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('close', $order);

        return DB::transaction(function () use ($actor, $order, $reason): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertCanTransitionTo($locked, PurchaseOrderStatus::Closed);

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'closed_at' => now(),
                'closure_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, 'purchasing.order.closed', ['closure_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * Voids the order. Refused once any receipt has completed (V-13), because
     * cancelling then would leave arrived stock with no commitment behind it.
     */
    public function cancel(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('cancel', $order);

        return DB::transaction(function () use ($actor, $order, $reason): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertCanTransitionTo($locked, PurchaseOrderStatus::Cancelled);

            if ($locked->hasCompletedReceipt()) {
                throw PurchaseOrderNotCancellable::hasCompletedReceipt($locked);
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit($locked, $actor, 'purchasing.order.cancelled', ['cancellation_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * Whether this order's total is at or below the current threshold.
     *
     * A currency the threshold is not expressed in always routes to explicit
     * approval: this feature converts nothing, so comparing 5,000 USD against a
     * 10,000 AED threshold would be arithmetic on incomparable units.
     */
    public function qualifiesForAutoApproval(PurchaseOrder $order): bool
    {
        $settings = PurchaseSetting::current();

        if (mb_strtoupper($settings->approval_threshold_currency) !== mb_strtoupper($order->currency_code)) {
            return false;
        }

        $threshold = (float) $settings->approval_threshold_amount;

        if ($threshold <= 0.0) {
            return false;
        }

        return (float) $order->total_amount <= $threshold;
    }

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        return $locked;
    }

    /**
     * @throws PurchaseOrderNotEditable
     */
    private function assertCanTransitionTo(PurchaseOrder $order, PurchaseOrderStatus $target): void
    {
        if (! $order->status->canTransitionTo($target)) {
            throw PurchaseOrderNotEditable::transition($order, $target);
        }
    }

    /**
     * @throws SelfApprovalRejected
     */
    private function assertNotSelfApproval(PurchaseOrder $order, User $actor): void
    {
        $submittedBy = is_numeric($order->submitted_by) ? (int) $order->submitted_by : null;

        if (! $this->sameActor($submittedBy, $actor)) {
            return;
        }

        if ($this->isSystemAdmin($actor)) {
            return;
        }

        throw SelfApprovalRejected::for($order);
    }

    /**
     * The System Admin exemption from separation of duties (R-005).
     *
     * Not `isAdmin()` alone: every dashboard user in this codebase carries
     * `UserType::Admin`, so that check would exempt everybody and make the
     * threshold decorative. "System Admin" here means what it means everywhere
     * else — the holder of the System Admin role, or an admin who has been given
     * no scoped role at all and therefore still has blanket bypass
     * (contracts/permissions.md §4).
     *
     * The exemption exists so a single-admin deployment does not deadlock with
     * nothing approvable.
     */
    private function isSystemAdmin(User $actor): bool
    {
        if ($actor->hasRole(DashboardRole::SystemAdmin->value)) {
            return true;
        }

        return $actor->isAdmin() && ! $actor->hasAnyRole(DashboardRole::fixedRoleNames());
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $attributes
     */
    private function audit(PurchaseOrder $order, User $actor, string $event, array $attributes): void
    {
        activity()
            ->performedOn($order)
            ->causedBy($actor)
            ->withChanges(['attributes' => $attributes])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($event);
    }
}
