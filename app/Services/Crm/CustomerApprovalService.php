<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerApprovalStatus;
use App\Events\CustomerAccountApproved;
use App\Events\CustomerAccountChangesRequested;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\Exceptions\InvalidCustomerApprovalTransition;
use App\Services\Inventory\PriceResolver;
use Illuminate\Support\Facades\DB;

/**
 * The only place a {@see CustomerProfile}'s {@see CustomerApprovalStatus}
 * may change. Every transition records the reviewer, timestamp, and note,
 * keeps `is_active` in sync (`Approved` -> true, everything else -> false —
 * the field {@see PriceResolver} and every other
 * commercial rule already reads), and writes an activity-log entry
 * following this codebase's `customer.*` naming convention.
 */
final readonly class CustomerApprovalService
{
    /**
     * @var array<string, list<CustomerApprovalStatus>>
     */
    private const array AllowedTargets = [
        'approve' => [CustomerApprovalStatus::Pending, CustomerApprovalStatus::ChangesRequested],
        'requestChanges' => [CustomerApprovalStatus::Pending, CustomerApprovalStatus::Approved],
        'reject' => [CustomerApprovalStatus::Pending, CustomerApprovalStatus::ChangesRequested, CustomerApprovalStatus::Approved],
        'reactivate' => [CustomerApprovalStatus::Rejected],
    ];

    public function approve(User $actor, CustomerProfile $customer, ?string $note = null): CustomerProfile
    {
        $approved = $this->transition($actor, $customer, 'approve', CustomerApprovalStatus::Approved, $note, 'customer.approved');

        CustomerAccountApproved::dispatch($approved);

        return $approved;
    }

    public function requestChanges(User $actor, CustomerProfile $customer, string $note): CustomerProfile
    {
        $updated = $this->transition($actor, $customer, 'requestChanges', CustomerApprovalStatus::ChangesRequested, $note, 'customer.changes_requested');

        CustomerAccountChangesRequested::dispatch($updated, $note);

        return $updated;
    }

    public function reject(User $actor, CustomerProfile $customer, string $reason): CustomerProfile
    {
        return $this->transition($actor, $customer, 'reject', CustomerApprovalStatus::Rejected, $reason, 'customer.rejected');
    }

    public function reactivate(User $actor, CustomerProfile $customer, ?string $note = null): CustomerProfile
    {
        return $this->transition($actor, $customer, 'reactivate', CustomerApprovalStatus::Pending, $note, 'customer.reactivated');
    }

    private function transition(
        User $actor,
        CustomerProfile $customer,
        string $ability,
        CustomerApprovalStatus $target,
        ?string $note,
        string $logName,
    ): CustomerProfile {
        return DB::transaction(function () use ($actor, $customer, $ability, $target, $note, $logName): CustomerProfile {
            /** @var CustomerProfile $locked */
            $locked = CustomerProfile::query()->whereKey($customer->getKey())->lockForUpdate()->sole();

            if (! in_array($locked->approval_status, self::AllowedTargets[$ability], true)) {
                throw InvalidCustomerApprovalTransition::fromTo($locked->approval_status->value, $target->value);
            }

            $locked->forceFill([
                'approval_status' => $target,
                'is_active' => $target === CustomerApprovalStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['approval_status' => $target->value, 'is_active' => $locked->is_active]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'note' => $note])
                ->log($logName);

            return $locked->refresh();
        });
    }
}
