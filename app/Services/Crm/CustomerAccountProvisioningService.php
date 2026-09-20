<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerApprovalStatus;
use App\Enums\CustomerProvisioningSource;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The single place that creates a customer-channel {@see User} and its
 * {@see CustomerProfile} together, atomically.
 *
 * Both current callers funnel through here: the public `/join-us`
 * self-registration ({@see CustomerOnboardingService}) and the Filament
 * dashboard's "create a complete customer account" flow. Whatever admin
 * console or future channel needs a third caller should call this service
 * directly rather than duplicating User/Profile creation.
 */
final readonly class CustomerAccountProvisioningService
{
    private const int MaxCustomerCodeAttempts = 20;

    /** @var Closure(int, int): int */
    private Closure $randomInt;

    /** @param null|Closure(int, int): int $randomInt */
    public function __construct(?Closure $randomInt = null)
    {
        $this->randomInt = $randomInt ?? random_int(...);
    }

    /**
     * @param  array<string, mixed>  $account  name, username, email, password
     * @param  array<string, mixed>  $profile  the remaining CustomerProfile fields; `customer_code` is optional
     *                                         (auto-generated when omitted) and `is_active` decides the initial
     *                                         {@see CustomerApprovalStatus} (`true` -> Approved, otherwise Pending).
     * @param  array<string, UploadedFile|string|null>  $documents  keyed by media collection name; an UploadedFile
     *                                                              is attached directly, a string is treated as an
     *                                                              already-uploaded temp path on the `local` disk
     *                                                              (synchronized via {@see CustomerDocumentSynchronizer}).
     */
    public function provision(
        array $account,
        array $profile,
        array $documents,
        CustomerProvisioningSource $source,
    ): CustomerProfile {
        return DB::transaction(function () use ($account, $profile, $documents, $source): CustomerProfile {
            $user = User::query()->create([
                'name' => $account['name'],
                'username' => $account['username'],
                'email' => $account['email'],
                'password' => Hash::make($this->requireString($account, 'password')),
                'user_type' => UserType::Customer,
            ]);

            $contactIsSelf = (bool) ($profile['contact_is_self'] ?? true);
            $isActive = (bool) ($profile['is_active'] ?? false);
            $customerCode = $profile['customer_code'] ?? null;

            $customerProfile = CustomerProfile::query()->create([
                'user_id' => $user->id,
                'customer_code' => is_string($customerCode) && $customerCode !== ''
                    ? $customerCode
                    : $this->generateCustomerCode(),
                'company_name' => $profile['company_name'] ?? null,
                'email' => $profile['email'] ?? null,
                'phone' => $profile['phone'] ?? null,
                'address' => $profile['address'] ?? null,
                'country' => $profile['country'] ?? null,
                'city' => $profile['city'] ?? null,
                'latitude' => $profile['latitude'] ?? null,
                'longitude' => $profile['longitude'] ?? null,
                'accountant_name' => $profile['accountant_name'] ?? null,
                'accountant_phone' => $profile['accountant_phone'] ?? null,
                'accountant_email' => $profile['accountant_email'] ?? null,
                'contact_is_self' => $contactIsSelf,
                'contact_name' => $contactIsSelf ? null : ($profile['contact_name'] ?? null),
                'contact_phone' => $contactIsSelf ? null : ($profile['contact_phone'] ?? null),
                'contact_email' => $contactIsSelf ? null : ($profile['contact_email'] ?? null),
                'is_active' => $isActive,
                'approval_status' => $isActive ? CustomerApprovalStatus::Approved : CustomerApprovalStatus::Pending,
                'allow_direct_orders' => (bool) ($profile['allow_direct_orders'] ?? false),
            ]);

            foreach ($documents as $collection => $file) {
                if ($file instanceof UploadedFile) {
                    $customerProfile->addMedia($file)->toMediaCollection($collection, 'local');

                    continue;
                }

                if (is_string($file) && $file !== '') {
                    app(CustomerDocumentSynchronizer::class)->sync($customerProfile, $collection, $file);
                }
            }

            activity()
                ->performedOn($customerProfile)
                ->withProperties(['source_channel' => $source->value])
                ->log('customer.provisioned');

            return $customerProfile->refresh();
        });
    }

    private function generateCustomerCode(): string
    {
        for ($attempt = 0; $attempt < self::MaxCustomerCodeAttempts; $attempt++) {
            $code = 'CUST-'.mb_str_pad((string) ($this->randomInt)(0, 9999), 4, '0', STR_PAD_LEFT);

            if (! CustomerProfile::withTrashed()->where('customer_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique customer code.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('Expected a string value for "%s".', $key));
        }

        return $value;
    }
}
