<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Observers\CustomerProfileObserver;
use App\Services\Crm\CustomerOnboardingService;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'user_id',
    'customer_code',
    'company_name',
    'email',
    'phone',
    'address',
    'country',
    'city',
    'latitude',
    'longitude',
    'accountant_name',
    'accountant_phone',
    'accountant_email',
    'contact_is_self',
    'contact_name',
    'contact_phone',
    'contact_email',
    'is_active',
])]
#[ObservedBy(CustomerProfileObserver::class)]
final class CustomerProfile extends Model implements HasMedia
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /**
     * Single-file KYC/delivery documents collected at self-registration
     * ({@see CustomerOnboardingService}) and manageable
     * from the admin CRM. Stored on the private `local` disk since these
     * are sensitive personal/company documents, not public assets.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'contact_is_self' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CustomerDeliveryAddress, $this> */
    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(CustomerDeliveryAddress::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('license')->useDisk('local')->singleFile();
        $this->addMediaCollection('tax_certificate')->useDisk('local')->singleFile();
        $this->addMediaCollection('passport')->useDisk('local')->singleFile();
        $this->addMediaCollection('personal_identity')->useDisk('local')->singleFile();
        $this->addMediaCollection('accommodation')->useDisk('local')->singleFile();
    }
}
