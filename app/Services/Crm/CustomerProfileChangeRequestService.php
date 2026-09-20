<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileChangeRequest;
use App\Models\User;
use App\Services\Crm\Exceptions\InvalidCustomerProfileChangeRequestTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Owns every transition of a {@see CustomerProfileChangeRequest}. It is the
 * only place that ever writes the legal/company identity fields or
 * documents this workflow guards — the request row itself is a proposal,
 * never a live edit ({@see CustomerProfileChangeRequest}).
 */
final readonly class CustomerProfileChangeRequestService
{
    /**
     * @param  array<string, mixed>  $requestedChanges  keys must be a subset of
     *                                                  {@see CustomerProfileChangeRequest::EditableFields}
     * @param  array<string, UploadedFile|string|null>  $documents  keyed by media collection name; a subset of
     *                                                              {@see CustomerProfileChangeRequest::DocumentCollections}
     */
    public function create(
        CustomerProfile $customer,
        array $requestedChanges,
        array $documents = [],
        ?User $requestedBy = null,
        ?string $reason = null,
        string $sourceChannel = 'dashboard',
    ): CustomerProfileChangeRequest {
        $this->assertSupportedFields(array_keys($requestedChanges));
        $this->assertSupportedFields(array_keys($documents));

        return DB::transaction(function () use ($customer, $requestedChanges, $documents, $requestedBy, $reason, $sourceChannel): CustomerProfileChangeRequest {
            $request = CustomerProfileChangeRequest::query()->create([
                'customer_id' => $customer->getKey(),
                'requested_by_user_id' => $requestedBy?->getKey(),
                'status' => CustomerProfileChangeRequestStatus::Pending,
                'requested_changes' => $requestedChanges,
                'reason' => $reason,
                'source_channel' => $sourceChannel,
            ]);

            foreach ($documents as $collection => $file) {
                if ($file instanceof UploadedFile) {
                    $request->addMedia($file)->toMediaCollection($collection, 'local');
                }
            }

            return $request->refresh();
        });
    }

    public function approve(User $actor, CustomerProfileChangeRequest $request, ?string $note = null): CustomerProfileChangeRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): CustomerProfileChangeRequest {
            /** @var CustomerProfileChangeRequest $locked */
            $locked = CustomerProfileChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->sole();

            if (! $locked->isPending()) {
                throw InvalidCustomerProfileChangeRequestTransition::notPending($locked->status->value);
            }

            /** @var CustomerProfile $customer */
            $customer = CustomerProfile::query()->whereKey($locked->customer_id)->lockForUpdate()->sole();

            $changes = array_intersect_key(
                $locked->requested_changes,
                array_flip(CustomerProfileChangeRequest::EditableFields),
            );

            if ($changes !== []) {
                $customer->forceFill([...$changes, 'updated_by' => $actor->getKey()])->save();
            }

            foreach (CustomerProfileChangeRequest::DocumentCollections as $collection) {
                $media = $locked->getFirstMedia($collection);

                if ($media !== null) {
                    $customer->addMediaFromDisk($media->getPathRelativeToRoot(), 'local')->toMediaCollection($collection, 'local');
                }
            }

            $locked->forceFill([
                'status' => CustomerProfileChangeRequestStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'applied_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('customer.change_request.approved');

            return $locked->refresh();
        });
    }

    public function reject(User $actor, CustomerProfileChangeRequest $request, string $reason): CustomerProfileChangeRequest
    {
        return $this->decideWithoutApplying($actor, $request, CustomerProfileChangeRequestStatus::Rejected, $reason, 'customer.change_request.rejected');
    }

    public function cancel(User $actor, CustomerProfileChangeRequest $request): CustomerProfileChangeRequest
    {
        return $this->decideWithoutApplying($actor, $request, CustomerProfileChangeRequestStatus::Cancelled, null, 'customer.change_request.cancelled');
    }

    private function decideWithoutApplying(
        User $actor,
        CustomerProfileChangeRequest $request,
        CustomerProfileChangeRequestStatus $target,
        ?string $note,
        string $logName,
    ): CustomerProfileChangeRequest {
        return DB::transaction(function () use ($actor, $request, $target, $note, $logName): CustomerProfileChangeRequest {
            /** @var CustomerProfileChangeRequest $locked */
            $locked = CustomerProfileChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->sole();

            if (! $locked->isPending()) {
                throw InvalidCustomerProfileChangeRequestTransition::notPending($locked->status->value);
            }

            $locked->forceFill([
                'status' => $target,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($logName);

            return $locked->refresh();
        });
    }

    /** @param list<string> $fields */
    private function assertSupportedFields(array $fields): void
    {
        $allowed = [...CustomerProfileChangeRequest::EditableFields, ...CustomerProfileChangeRequest::DocumentCollections];

        foreach ($fields as $field) {
            if (! in_array($field, $allowed, true)) {
                throw InvalidCustomerProfileChangeRequestTransition::unsupportedField($field);
            }
        }
    }
}
