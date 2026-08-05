<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates the customer-channel {@see User} and {@see CustomerProfile} pair
 * produced by the public `/join-us` self-registration form. The resulting
 * profile is always inactive until an admin reviews it.
 */
final class CustomerOnboardingService
{
    private const int MaxCustomerCodeAttempts = 20;

    /**
     * @param  array<string, mixed>  $data  validated join-us request data
     * @param  array<string, UploadedFile>  $documents  keyed by media collection name
     */
    public function register(array $data, array $documents): CustomerProfile
    {
        return DB::transaction(function () use ($data, $documents): CustomerProfile {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($this->requireString($data, 'password')),
                'user_type' => UserType::Customer,
            ]);

            $contactIsSelf = (bool) $data['contact_is_self'];

            $profile = CustomerProfile::query()->create([
                'user_id' => $user->id,
                'customer_code' => $this->generateCustomerCode(),
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
            ]);

            foreach ($documents as $collection => $file) {
                $profile->addMedia($file)->toMediaCollection($collection, 'local');
            }

            return $profile;
        });
    }

    private function generateCustomerCode(): string
    {
        for ($attempt = 0; $attempt < self::MaxCustomerCodeAttempts; $attempt++) {
            $code = 'CUST-'.mb_str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

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
            throw new RuntimeException("Expected a string value for \"{$key}\".");
        }

        return $value;
    }
}
