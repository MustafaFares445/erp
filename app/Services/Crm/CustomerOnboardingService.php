<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerProvisioningSource;
use App\Models\CustomerProfile;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Creates the customer-channel User and {@see CustomerProfile} pair produced
 * by the public `/join-us` self-registration form. The resulting profile is
 * always inactive/Pending until an admin reviews it.
 *
 * A thin, join-us-shaped wrapper over the shared
 * {@see CustomerAccountProvisioningService} — actual User/Profile creation
 * lives there so the Filament dashboard's "create a complete customer
 * account" flow does not duplicate it.
 */
final readonly class CustomerOnboardingService
{
    /** @var Closure(int, int): int */
    private Closure $randomInt;

    /** @param null|Closure(int, int): int $randomInt */
    public function __construct(?Closure $randomInt = null)
    {
        $this->randomInt = $randomInt ?? random_int(...);
    }

    /**
     * @param  array<string, mixed>  $data  validated join-us request data
     * @param  array<string, UploadedFile>  $documents  keyed by media collection name
     */
    public function register(array $data, array $documents): CustomerProfile
    {
        $contactIsSelf = (bool) $data['contact_is_self'];

        return new CustomerAccountProvisioningService($this->randomInt)->provision(
            account: [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'] ?? null,
            ],
            profile: [
                'company_name' => $data['company_name'],
                'email' => $data['company_email'],
                'phone' => $data['company_phone'],
                'address' => $data['address'] ?? null,
                'country' => $data['country'],
                'city' => $data['city'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accountant_name' => $data['accountant_name'] ?? null,
                'accountant_phone' => $data['accountant_phone'] ?? null,
                'accountant_email' => $data['accountant_email'] ?? null,
                'contact_is_self' => $contactIsSelf,
                'contact_name' => $contactIsSelf ? null : $data['contact_name'],
                'contact_phone' => $contactIsSelf ? null : $data['contact_phone'],
                'contact_email' => $contactIsSelf ? null : $data['contact_email'],
                'is_active' => false,
            ],
            documents: $documents,
            source: CustomerProvisioningSource::JoinUs,
        );
    }
}
