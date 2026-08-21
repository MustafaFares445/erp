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

it('rejects a file larger than the maximum allowed size', function (): void {
    $profile = CustomerProfile::factory()->create();
    $path = 'customer-documents/license/oversized.pdf';
    // UploadedFile::fake()->create() only fakes the reported size, not the bytes actually
    // written to a faked disk, so the disk is filled directly to exceed the 5 MB limit for real.
    Storage::disk('local')->put($path, str_repeat('0', 5 * 1024 * 1024 + 1));

    app(CustomerDocumentSynchronizer::class)->sync($profile, 'license', $path);
})->throws(ValidationException::class);

it('does nothing when the given path already matches the stored document', function (): void {
    $profile = CustomerProfile::factory()->create();
    $synchronizer = app(CustomerDocumentSynchronizer::class);
    $path = UploadedFile::fake()->create('license.pdf', 200, 'application/pdf')->store('customer-documents/license', 'local');
    $synchronizer->sync($profile, 'license', $path);

    $storedPath = $profile->fresh()->getFirstMedia('license')?->getPathRelativeToRoot();

    // The Filament form redisplays the stored media's own path as the field's current value, so
    // resubmitting the form unchanged passes that path straight back in — one outside the
    // customer-documents/ prefix, which would fail validation if this early return did not exist.
    $synchronizer->sync($profile->fresh(), 'license', $storedPath);

    expect($profile->fresh()->getMedia('license'))->toHaveCount(1);
});
