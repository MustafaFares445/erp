<?php

declare(strict_types=1);

use App\Models\EquipmentInstallation;
use App\Models\User;
use App\Services\Support\EquipmentInstallationEvidenceService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    Storage::fake('local');
});

function stagedUpload(string $name = 'photo.jpg', string $directory = 'installation-evidence'): string
{
    return UploadedFile::fake()->image($name)->storeAs($directory, $name, 'local');
}

function evidenceInstallation(): EquipmentInstallation
{
    [, , $record] = InstallationFixtures::scenario();

    return InstallationFixtures::installation($record, InstallationFixtures::manager());
}

it('attaches uploaded evidence to the right private collection and removes the scratch file', function (): void {
    $installation = evidenceInstallation();
    $service = app(EquipmentInstallationEvidenceService::class);
    $actor = InstallationFixtures::manager();

    $photo = stagedUpload('rack.jpg');
    $document = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')->storeAs('installation-evidence', 'report.pdf', 'local');
    $proof = stagedUpload('signed.png');

    expect($service->attach($installation, EquipmentInstallation::MEDIA_PHOTOS, [$photo], $actor))->toBe(1)
        ->and($service->attach($installation, EquipmentInstallation::MEDIA_COMMISSIONING, [$document], $actor))->toBe(1)
        ->and($service->attach($installation, EquipmentInstallation::MEDIA_ACCEPTANCE, [$proof], $actor))->toBe(1);

    $installation = $installation->fresh();

    expect($installation->getMedia(EquipmentInstallation::MEDIA_PHOTOS))->toHaveCount(1)
        ->and($installation->getMedia(EquipmentInstallation::MEDIA_COMMISSIONING))->toHaveCount(1)
        ->and($installation->getMedia(EquipmentInstallation::MEDIA_ACCEPTANCE))->toHaveCount(1)
        ->and($installation->getFirstMedia(EquipmentInstallation::MEDIA_PHOTOS)?->disk)->toBe('local')
        ->and($service->counts($installation))->toBe([
            EquipmentInstallation::MEDIA_PHOTOS => 1,
            EquipmentInstallation::MEDIA_COMMISSIONING => 1,
            EquipmentInstallation::MEDIA_ACCEPTANCE => 1,
        ])
        ->and(Storage::disk('local')->exists($photo))->toBeFalse();
});

it('keeps evidence on the installation after a reload and writes an audit entry', function (): void {
    $installation = evidenceInstallation();
    $actor = InstallationFixtures::manager();

    app(EquipmentInstallationEvidenceService::class)->attach($installation, EquipmentInstallation::MEDIA_PHOTOS, [stagedUpload()], $actor);

    $reloaded = EquipmentInstallation::query()->with('media')->findOrFail($installation->getKey());

    expect($reloaded->media)->toHaveCount(1)
        ->and(Activity::query()->where('description', 'support.installation.evidence_attached')->count())->toBe(1);
});

it('rejects tampered paths, oversize files, wrong types and unknown collections', function (): void {
    $installation = evidenceInstallation();
    $service = app(EquipmentInstallationEvidenceService::class);
    $actor = InstallationFixtures::manager();

    Storage::disk('local')->put('secrets/other.pdf', 'x');
    Storage::disk('local')->put('installation-evidence/big.pdf', str_repeat('a', EquipmentInstallationEvidenceService::MaximumFileSizeInBytes + 1));
    Storage::disk('local')->put('installation-evidence/notes.txt', 'plain text');

    foreach ([
        ['secrets/other.pdf', EquipmentInstallation::MEDIA_PHOTOS],
        ['installation-evidence/../secrets/other.pdf', EquipmentInstallation::MEDIA_PHOTOS],
        ['installation-evidence/missing.jpg', EquipmentInstallation::MEDIA_PHOTOS],
        ['installation-evidence/big.pdf', EquipmentInstallation::MEDIA_PHOTOS],
        ['installation-evidence/notes.txt', EquipmentInstallation::MEDIA_PHOTOS],
        [stagedUpload('ok.jpg'), 'ticket-attachments'],
    ] as [$path, $collection]) {
        expect(fn () => $service->attach($installation, $collection, [$path], $actor))->toThrow(ValidationException::class);
    }

    expect($installation->fresh()->media)->toHaveCount(0)
        ->and($service->attach($installation, EquipmentInstallation::MEDIA_PHOTOS, ['', 5, null], $actor))->toBe(0);
});

it('requires installation update permission to attach or remove evidence', function (): void {
    $installation = evidenceInstallation();
    $service = app(EquipmentInstallationEvidenceService::class);
    $reviewer = InstallationFixtures::reviewer();
    $agent = InstallationFixtures::agent();

    expect(fn () => $service->attach($installation, EquipmentInstallation::MEDIA_PHOTOS, [stagedUpload()], $reviewer))
        ->toThrow(AuthorizationException::class);

    $service->attach($installation, EquipmentInstallation::MEDIA_PHOTOS, [stagedUpload('agent.jpg')], $agent);
    $media = $installation->fresh()->getFirstMedia(EquipmentInstallation::MEDIA_PHOTOS);

    expect(fn () => $service->remove($installation, $media, $reviewer))->toThrow(AuthorizationException::class);

    $service->remove($installation, $media, $agent);

    expect($installation->fresh()->media)->toHaveCount(0);
});

it('refuses to remove media that belongs to another installation', function (): void {
    $installation = evidenceInstallation();
    $other = evidenceInstallation();
    $actor = InstallationFixtures::manager();
    $service = app(EquipmentInstallationEvidenceService::class);

    $service->attach($other, EquipmentInstallation::MEDIA_PHOTOS, [stagedUpload()], $actor);

    expect(fn () => $service->remove($installation, $other->fresh()->getFirstMedia(EquipmentInstallation::MEDIA_PHOTOS), $actor))
        ->toThrow(ValidationException::class);
});

it('streams evidence only to authorized users through the private media routes', function (): void {
    $installation = evidenceInstallation();
    $manager = InstallationFixtures::manager();
    app(EquipmentInstallationEvidenceService::class)->attach($installation, EquipmentInstallation::MEDIA_COMMISSIONING, [stagedUpload('plan.jpg')], $manager);
    $media = $installation->fresh()->getFirstMedia(EquipmentInstallation::MEDIA_COMMISSIONING);
    $parameters = ['installation' => $installation, 'media' => $media];

    $this->actingAs($manager)
        ->get(route('admin.equipment-installations.media.preview', $parameters))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($manager)
        ->get(route('admin.equipment-installations.media.download', $parameters))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.equipment-installations.media.preview', $parameters))
        ->assertForbidden();

    config(['support.equipment_installation_enabled' => false]);

    $this->actingAs($manager)
        ->get(route('admin.equipment-installations.media.download', $parameters))
        ->assertForbidden();
});

it('returns not found for media of another installation', function (): void {
    $installation = evidenceInstallation();
    $other = evidenceInstallation();
    $manager = InstallationFixtures::manager();
    app(EquipmentInstallationEvidenceService::class)->attach($other, EquipmentInstallation::MEDIA_PHOTOS, [stagedUpload()], $manager);
    $media = $other->fresh()->getFirstMedia(EquipmentInstallation::MEDIA_PHOTOS);

    $this->actingAs($manager)
        ->get(route('admin.equipment-installations.media.preview', ['installation' => $installation, 'media' => $media]))
        ->assertNotFound();
});
