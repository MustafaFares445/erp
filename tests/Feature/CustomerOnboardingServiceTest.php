<?php

declare(strict_types=1);

namespace App\Services\Crm {
    /**
     * Overridden so {@see CustomerOnboardingService::generateCustomerCode()} can be forced to
     * collide deterministically. Inert (delegates to the real function) unless a test opts in via
     * the global flag, so every other caller in this namespace is unaffected.
     */
    function random_int(int $min, int $max): int
    {
        $override = $GLOBALS['customerOnboardingServiceTestRandomIntOverride'] ?? null;

        return is_int($override) ? $override : \random_int($min, $max);
    }
}

namespace {
    use App\Models\CustomerProfile;
    use App\Services\Crm\CustomerOnboardingService;
    use Illuminate\Foundation\Testing\RefreshDatabase;

    uses(RefreshDatabase::class);

    afterEach(function (): void {
        unset($GLOBALS['customerOnboardingServiceTestRandomIntOverride']);
    });

    it('rejects registration when the password field is not a string', function (): void {
        $service = app(CustomerOnboardingService::class);

        expect(fn (): CustomerProfile => $service->register(registrationData(['password' => null]), []))
            ->toThrow(RuntimeException::class, 'Expected a string value for "password".')
            ->and(CustomerProfile::query()->count())->toBe(0);
    });

    it('gives up generating a unique customer code once every attempt collides', function (): void {
        CustomerProfile::factory()->create(['customer_code' => 'CUST-1234']);
        $GLOBALS['customerOnboardingServiceTestRandomIntOverride'] = 1234;
        $service = app(CustomerOnboardingService::class);

        expect(fn (): CustomerProfile => $service->register(registrationData(), []))
            ->toThrow(RuntimeException::class, 'Unable to generate a unique customer code.')
            ->and(CustomerProfile::query()->count())->toBe(1);
    });

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function registrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Applicant',
            'username' => 'jane-applicant',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'contact_is_self' => true,
            'company_name' => 'Acme Trading',
            'company_email' => 'contact@acme.test',
            'company_phone' => '+963111222333',
            'address' => 'Some street, Damascus',
            'country' => 'Syria',
            'city' => 'Damascus',
            'latitude' => '33.5138000',
            'longitude' => '36.2765000',
            'accountant_name' => null,
            'accountant_phone' => null,
            'accountant_email' => null,
            'contact_name' => null,
            'contact_phone' => null,
            'contact_email' => null,
        ], $overrides);
    }
}
