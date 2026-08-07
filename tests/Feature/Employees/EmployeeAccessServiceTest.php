<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enables and disables app access', function (): void {
    $profile = EmployeeProfile::factory()->create(['is_active' => true]);
    $service = app(EmployeeAccessService::class);

    $service->disable($profile);

    expect($profile->fresh()->is_active)->toBeFalse();

    $service->enable($profile);
    expect($profile->fresh()->is_active)->toBeTrue();
});

it('archives an employee as a soft delete that preserves their history', function (): void {
    $profile = EmployeeProfile::factory()->create();
    $jobTitle = $profile->job_title;

    app(EmployeeAccessService::class)->archive($profile);

    expect(EmployeeProfile::query()->find($profile->id))->toBeNull()
        ->and(EmployeeProfile::withTrashed()->find($profile->id))->not->toBeNull()
        ->and(EmployeeProfile::withTrashed()->find($profile->id)->job_title)->toBe($jobTitle);
});

it('restores an archived employee back to active data, never bypassing archived state directly', function (): void {
    $profile = EmployeeProfile::factory()->create();
    app(EmployeeAccessService::class)->archive($profile);

    $archived = EmployeeProfile::withTrashed()->findOrFail($profile->id);
    $restored = app(EmployeeAccessService::class)->restore($archived);

    expect($restored->trashed())->toBeFalse()
        ->and(EmployeeProfile::query()->find($profile->id))->not->toBeNull();
});
