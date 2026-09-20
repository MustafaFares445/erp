<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Crm\CustomerProfileChangeRequestService;
use Database\Factories\CustomerProfileChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A proposal to change a customer's legal/company identity fields or replace
 * a legal document — evidence, not a live edit. It never mutates
 * {@see CustomerProfile} on save; only
 * {@see CustomerProfileChangeRequestService::approve()}
 * applies its content, atomically, to the profile.
 */
#[Fillable([
    'customer_id', 'requested_by_user_id', 'status', 'requested_changes', 'reason',
    'reviewed_by', 'reviewed_at', 'review_note', 'applied_at', 'source_channel',
])]
final class CustomerProfileChangeRequest extends Model implements HasMedia
{
    /**
     * The only {@see CustomerProfile} fields a change request may propose.
     * Contact/delivery details remain directly editable elsewhere; legal and
     * company identity fields must go through this approval workflow.
     *
     * @var list<string>
     */
    public const array EditableFields = ['company_name', 'country', 'city', 'address'];

    /**
     * The only {@see CustomerProfile} media collections a change request may
     * propose a replacement for, mirroring its own document collections.
     *
     * @var list<string>
     */
    public const array DocumentCollections = ['license', 'tax_certificate', 'passport', 'personal_identity', 'accommodation'];

    /** @use HasFactory<CustomerProfileChangeRequestFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => CustomerProfileChangeRequestStatus::class,
            'requested_changes' => 'array',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === CustomerProfileChangeRequestStatus::Pending;
    }

    public function registerMediaCollections(): void
    {
        foreach (self::DocumentCollections as $collection) {
            $this->addMediaCollection($collection)->useDisk('local')->singleFile();
        }
    }
}
