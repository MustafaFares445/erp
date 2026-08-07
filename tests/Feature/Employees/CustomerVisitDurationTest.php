<?php

declare(strict_types=1);

use App\Models\CustomerVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('computes visit duration from checked-in and checked-out timestamps', function (): void {
    $visit = CustomerVisit::factory()->create([
        'checked_in_at' => '2026-03-01 09:00:00',
        'checked_out_at' => '2026-03-01 09:45:00',
    ]);

    expect($visit->durationMinutes())->toBe(45);
});

it('returns a null duration when checked-out is missing', function (): void {
    $visit = CustomerVisit::factory()->create([
        'checked_in_at' => '2026-03-01 09:00:00',
        'checked_out_at' => null,
    ]);

    expect($visit->durationMinutes())->toBeNull();
});

it('returns a null duration when checked-in is missing', function (): void {
    $visit = CustomerVisit::factory()->create([
        'checked_in_at' => null,
        'checked_out_at' => '2026-03-01 09:45:00',
    ]);

    expect($visit->durationMinutes())->toBeNull();
});

it('never stores a duration column', function (): void {
    expect(Schema::hasColumn('customer_visits', 'duration_minutes'))->toBeFalse();
});
