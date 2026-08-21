<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('renders the join-us form with an OpenStreetMap location picker', function (): void {
    $this->get(route('join-us.create'))
        ->assertOk()
        ->assertSee('Join us')
        ->assertSee('id="join-us-map"', false)
        ->assertSee('data-default-zoom="15"', false)
        ->assertSee('for="map-search"', false)
        ->assertSee('type="hidden" id="latitude"', false)
        ->assertSee('type="hidden" id="longitude"', false)
        ->assertDontSee('>Latitude<', false)
        ->assertDontSee('>Longitude<', false);
});

it('renders the thank-you page', function (): void {
    $this->get(route('join-us.thank-you'))
        ->assertOk()
        ->assertSee('Thanks for applying!');
});

it('registers a new customer application pending review with all documents attached', function (): void {
    $this->post(route('join-us.store'), joinUsPayload())
        ->assertRedirect(route('join-us.thank-you'));

    $user = User::query()->where('username', 'jane-applicant')->sole();

    expect($user->user_type)->toBe(UserType::Customer)
        ->and(Hash::check('password123', $user->password))->toBeTrue();

    $profile = CustomerProfile::query()->where('user_id', $user->id)->sole();

    expect($profile->is_active)->toBeFalse()
        ->and($profile->customer_code)->toStartWith('CUST-')
        ->and($profile->contact_is_self)->toBeTrue()
        ->and($profile->contact_name)->toBeNull()
        ->and((float) $profile->latitude)->toBe(33.5138)
        ->and($profile->getFirstMedia('license'))->not->toBeNull()
        ->and($profile->getFirstMedia('tax_certificate'))->not->toBeNull()
        ->and($profile->getFirstMedia('passport'))->not->toBeNull()
        ->and($profile->getFirstMedia('personal_identity'))->not->toBeNull()
        ->and($profile->getFirstMedia('accommodation'))->not->toBeNull();
});

it('generates unique customer codes across applications', function (): void {
    $this->post(route('join-us.store'), joinUsPayload([
        'username' => 'first-applicant',
        'email' => 'first@example.com',
    ]));
    $this->post(route('join-us.store'), joinUsPayload([
        'username' => 'second-applicant',
        'email' => 'second@example.com',
    ]));

    $codes = CustomerProfile::query()->pluck('customer_code');

    expect($codes)->toHaveCount(2)
        ->and($codes->unique())->toHaveCount(2);
});

it("stores a separate contact person when not using the applicant's own account", function (): void {
    $this->post(route('join-us.store'), joinUsPayload([
        'username' => 'other-contact',
        'email' => 'other-contact@example.com',
        'contact_is_self' => '0',
        'contact_name' => 'Contact Person',
        'contact_phone' => '+963999888777',
        'contact_email' => 'contact-person@example.com',
    ]))->assertRedirect(route('join-us.thank-you'));

    $profile = CustomerProfile::query()
        ->whereRelation('user', 'username', 'other-contact')
        ->sole();

    expect($profile->contact_is_self)->toBeFalse()
        ->and($profile->contact_name)->toBe('Contact Person')
        ->and($profile->contact_phone)->toBe('+963999888777')
        ->and($profile->contact_email)->toBe('contact-person@example.com');
});

it("requires contact person details when not using the applicant's own account", function (): void {
    $this->post(route('join-us.store'), joinUsPayload([
        'username' => 'missing-contact',
        'email' => 'missing-contact@example.com',
        'contact_is_self' => '0',
    ]))->assertSessionHasErrors(['contact_name', 'contact_phone', 'contact_email']);

    expect(User::query()->where('username', 'missing-contact')->exists())->toBeFalse();
});

it('rejects a duplicate username', function (): void {
    User::factory()->customer()->create(['username' => 'taken-name']);

    $this->post(route('join-us.store'), joinUsPayload(['username' => 'taken-name']))
        ->assertSessionHasErrors('username');
});

it('rejects a duplicate account email', function (): void {
    User::factory()->customer()->create(['email' => 'taken@example.com']);

    $this->post(route('join-us.store'), joinUsPayload(['email' => 'taken@example.com']))
        ->assertSessionHasErrors('email');
});

it('requires every document to be uploaded', function (): void {
    $payload = joinUsPayload();
    unset($payload['passport']);

    $this->post(route('join-us.store'), $payload)->assertSessionHasErrors('passport');
});

it('rejects a non-image upload for an image-only document', function (): void {
    $this->post(route('join-us.store'), joinUsPayload([
        'passport' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ]))->assertSessionHasErrors('passport');
});

it('requires the delivery location coordinates', function (): void {
    $payload = joinUsPayload();
    unset($payload['latitude'], $payload['longitude']);

    $this->post(route('join-us.store'), $payload)->assertSessionHasErrors(['latitude', 'longitude']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function joinUsPayload(array $overrides = []): array
{
    $base = [
        'name' => 'Jane Applicant',
        'username' => 'jane-applicant',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'company_name' => 'Acme Trading',
        'company_email' => 'contact@acme.test',
        'company_phone' => '+963111222333',
        'country' => 'Syria',
        'city' => 'Damascus',
        'address' => 'Some street, Damascus',
        'latitude' => '33.5138000',
        'longitude' => '36.2765000',
        'contact_is_self' => '1',
    ];

    $documents = [
        'license' => UploadedFile::fake()->create('license.pdf', 200, 'application/pdf'),
        'tax_certificate' => UploadedFile::fake()->create('tax-certificate.pdf', 200, 'application/pdf'),
        'passport' => UploadedFile::fake()->image('passport.jpg'),
        'personal_identity' => UploadedFile::fake()->image('personal-identity.jpg'),
        'accommodation' => UploadedFile::fake()->image('accommodation.jpg'),
    ];

    return array_merge($base, $documents, $overrides);
}
