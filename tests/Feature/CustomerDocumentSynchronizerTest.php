<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Services\Crm\CustomerDocumentSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('moves an uploaded document into the matching media collection and clears the temp file', function (): void {
    $profile = CustomerProfile::factory()->create();
    $path = UploadedFile::fake()->create('license.pdf', 200, 'application/pdf')->store('customer-documents/license', 'local');

    app(CustomerDocumentSynchronizer::class)->sync($profile, 'license', $path);

    expect($profile->fresh()->getFirstMedia('license'))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('replaces an existing document with a new upload', function (): void {
    $profile = CustomerProfile::factory()->create();
    $synchronizer = app(CustomerDocumentSynchronizer::class);

    $firstPath = UploadedFile::fake()->image('passport-1.jpg')->store('customer-documents/passport', 'local');
    $synchronizer->sync($profile, 'passport', $firstPath);
    $firstMediaId = $profile->fresh()->getFirstMedia('passport')?->getKey();

    // Re-fetch the model to mirror how each admin save is its own request
    // against a freshly-hydrated record — reusing $profile in-process would
    // rely on Spatie's media relation cache invalidating mid-request, which
    // it does not do.
    $reloadedProfile = CustomerProfile::query()->findOrFail($profile->getKey());
    $secondPath = UploadedFile::fake()->image('passport-2.jpg')->store('customer-documents/passport', 'local');
    $synchronizer->sync($reloadedProfile, 'passport', $secondPath);

    $profile->refresh();

    expect($profile->getMedia('passport'))->toHaveCount(1)
        ->and($profile->getFirstMedia('passport')?->getKey())->not->toBe($firstMediaId);
});

it('does nothing when no path is given', function (): void {
    $profile = CustomerProfile::factory()->create();

    app(CustomerDocumentSynchronizer::class)->sync($profile, 'license', null);

    expect($profile->fresh()->getFirstMedia('license'))->toBeNull();
});

it('rejects a file outside the expected upload directory', function (): void {
    $profile = CustomerProfile::factory()->create();
    $path = UploadedFile::fake()->create('license.pdf', 200, 'application/pdf')->store('elsewhere', 'local');

    app(CustomerDocumentSynchronizer::class)->sync($profile, 'license', $path);
})->throws(ValidationException::class);

it('rejects an unsupported mime type for an image-only collection', function (): void {
    $profile = CustomerProfile::factory()->create();
    $path = UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf')->store('customer-documents/passport', 'local');

    app(CustomerDocumentSynchronizer::class)->sync($profile, 'passport', $path);
})->throws(ValidationException::class);
