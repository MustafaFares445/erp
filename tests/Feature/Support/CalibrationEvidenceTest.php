<?php

declare(strict_types=1);

use App\Models\EquipmentCalibration;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationEvidenceService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CalibrationFixtures;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    Storage::fake('local');
});

function calibrationUpload(string $name = 'certificate.jpg', string $directory = 'calibration-evidence'): string
{
    return UploadedFile::fake()->image($name)->storeAs($directory, $name, 'local');
}

function evidenceCalibration(): EquipmentCalibration
{
    [, , $record] = CalibrationFixtures::scenario();

    return CalibrationFixtures::started($record, InstallationFixtures::manager());
}

it('attaches uploaded evidence to the right private collection and removes the scratch file', function (): void {
    $calibration = evidenceCalibration();
    $service = app(EquipmentCalibrationEvidenceService::class);
    $actor = InstallationFixtures::manager();

    $certificate = UploadedFile::fake()->create('certificate.pdf', 20, 'application/pdf')->storeAs('calibration-evidence', 'certificate.pdf', 'local');
    $photo = calibrationUpload('setup.jpg');

    expect($service->attach($calibration, EquipmentCalibration::MEDIA_CERTIFICATES, [$certificate], $actor))->toBe(1)
        ->and($service->attach($calibration, EquipmentCalibration::MEDIA_EVIDENCE, [$photo], $actor))->toBe(1);

    $calibration = $calibration->fresh();

    expect($calibration->getMedia(EquipmentCalibration::MEDIA_CERTIFICATES))->toHaveCount(1)
        ->and($calibration->getMedia(EquipmentCalibration::MEDIA_EVIDENCE))->toHaveCount(1)
        ->and($calibration->getFirstMedia(EquipmentCalibration::MEDIA_CERTIFICATES)?->disk)->toBe('local')
        ->and($service->counts($calibration))->toBe([
            EquipmentCalibration::MEDIA_CERTIFICATES => 1,
            EquipmentCalibration::MEDIA_EVIDENCE => 1,
        ])
        ->and(Storage::disk('local')->exists($certificate))->toBeFalse()
        ->and(Activity::query()->where('description', 'support.calibration.evidence_attached')->count())->toBe(2);
});

it('rejects tampered paths, oversize files, wrong types and unknown collections', function (): void {
    $calibration = evidenceCalibration();
    $service = app(EquipmentCalibrationEvidenceService::class);
    $actor = InstallationFixtures::manager();

    Storage::disk('local')->put('secrets/other.pdf', 'x');
    Storage::disk('local')->put('calibration-evidence/big.pdf', str_repeat('a', EquipmentCalibrationEvidenceService::MaximumFileSizeInBytes + 1));
    Storage::disk('local')->put('calibration-evidence/notes.txt', 'plain text');

    foreach ([
        ['secrets/other.pdf', EquipmentCalibration::MEDIA_EVIDENCE],
        ['calibration-evidence/../secrets/other.pdf', EquipmentCalibration::MEDIA_EVIDENCE],
        ['calibration-evidence/missing.jpg', EquipmentCalibration::MEDIA_EVIDENCE],
        ['calibration-evidence/big.pdf', EquipmentCalibration::MEDIA_EVIDENCE],
        ['calibration-evidence/notes.txt', EquipmentCalibration::MEDIA_EVIDENCE],
        [calibrationUpload('ok.jpg'), 'installation-photos'],
    ] as [$path, $collection]) {
        expect(fn () => $service->attach($calibration, $collection, [$path], $actor))->toThrow(ValidationException::class);
    }

    expect($calibration->fresh()->media)->toHaveCount(0)
        ->and($service->attach($calibration, EquipmentCalibration::MEDIA_EVIDENCE, ['', 5, null], $actor))->toBe(0);
});

it('requires calibration update permission to attach or remove evidence', function (): void {
    $calibration = evidenceCalibration();
    $service = app(EquipmentCalibrationEvidenceService::class);
    $reviewer = InstallationFixtures::reviewer();
    $agent = InstallationFixtures::agent();

    expect(fn () => $service->attach($calibration, EquipmentCalibration::MEDIA_EVIDENCE, [calibrationUpload()], $reviewer))
        ->toThrow(AuthorizationException::class);

    $service->attach($calibration, EquipmentCalibration::MEDIA_EVIDENCE, [calibrationUpload('agent.jpg')], $agent);
    $media = $calibration->fresh()->getFirstMedia(EquipmentCalibration::MEDIA_EVIDENCE);

    expect(fn () => $service->remove($calibration, $media, $reviewer))->toThrow(AuthorizationException::class);

    $service->remove($calibration, $media, $agent);

    expect($calibration->fresh()->media)->toHaveCount(0)
        ->and(Activity::query()->where('description', 'support.calibration.evidence_removed')->count())->toBe(1);
});

it('refuses to remove media that belongs to another calibration', function (): void {
    $calibration = evidenceCalibration();
    $other = evidenceCalibration();
    $actor = InstallationFixtures::manager();
    $service = app(EquipmentCalibrationEvidenceService::class);

    $service->attach($other, EquipmentCalibration::MEDIA_EVIDENCE, [calibrationUpload()], $actor);

    expect(fn () => $service->remove($calibration, $other->fresh()->getFirstMedia(EquipmentCalibration::MEDIA_EVIDENCE), $actor))
        ->toThrow(ValidationException::class);
});

it('streams evidence only to authorized users through the private media routes', function (): void {
    $calibration = evidenceCalibration();
    $manager = InstallationFixtures::manager();
    app(EquipmentCalibrationEvidenceService::class)->attach($calibration, EquipmentCalibration::MEDIA_CERTIFICATES, [calibrationUpload('cert.jpg')], $manager);
    $media = $calibration->fresh()->getFirstMedia(EquipmentCalibration::MEDIA_CERTIFICATES);
    $parameters = ['calibration' => $calibration, 'media' => $media];

    $this->actingAs($manager)
        ->get(route('admin.equipment-calibrations.media.preview', $parameters))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($manager)
        ->get(route('admin.equipment-calibrations.media.download', $parameters))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.equipment-calibrations.media.preview', $parameters))
        ->assertForbidden();

    config(['support.calibration_enabled' => false]);

    $this->actingAs($manager)
        ->get(route('admin.equipment-calibrations.media.download', $parameters))
        ->assertForbidden();
});

it('returns not found for media of another calibration', function (): void {
    $calibration = evidenceCalibration();
    $other = evidenceCalibration();
    $manager = InstallationFixtures::manager();
    app(EquipmentCalibrationEvidenceService::class)->attach($other, EquipmentCalibration::MEDIA_EVIDENCE, [calibrationUpload()], $manager);
    $media = $other->fresh()->getFirstMedia(EquipmentCalibration::MEDIA_EVIDENCE);

    $this->actingAs($manager)
        ->get(route('admin.equipment-calibrations.media.preview', ['calibration' => $calibration, 'media' => $media]))
        ->assertNotFound();
});
