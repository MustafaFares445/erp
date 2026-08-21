<?php

declare(strict_types=1);

use App\Models\CustomerVisit;
use App\Models\VisitGpsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns GPS records in chronological order regardless of insert order', function (): void {
    $visit = CustomerVisit::factory()->create();

    $second = VisitGpsLog::factory()->for($visit, 'customerVisit')->create(['recorded_at' => '2026-03-01 09:10:00']);
    $first = VisitGpsLog::factory()->for($visit, 'customerVisit')->create(['recorded_at' => '2026-03-01 09:00:00']);
    $third = VisitGpsLog::factory()->for($visit, 'customerVisit')->create(['recorded_at' => '2026-03-01 09:20:00']);

    expect($visit->gpsLogs->pluck('id')->all())->toBe([$first->id, $second->id, $third->id]);
});

it('has no update path', function (): void {
    $log = VisitGpsLog::factory()->create();

    expect(fn () => $log->update(['latitude' => 1.0]))->toThrow(DomainException::class);
});

it('has no delete path', function (): void {
    $log = VisitGpsLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(DomainException::class);
});
