<?php

declare(strict_types=1);

use App\Enums\MaintenanceIntervalType;
use Carbon\Carbon;

it('advances a date by the given number of days', function (): void {
    $result = MaintenanceIntervalType::Days->advance(Carbon::parse('2026-01-10'), 5);

    expect($result->toDateString())->toBe('2026-01-15');
});

it('advances a date by the given number of weeks', function (): void {
    $result = MaintenanceIntervalType::Weeks->advance(Carbon::parse('2026-01-01'), 2);

    expect($result->toDateString())->toBe('2026-01-15');
});

it('advances a date by the given number of months without overflowing a shorter month', function (): void {
    // 31 January + 1 month is clamped to the last day of February (28 in a
    // non-leap year) rather than overflowing into March — see the enum's own
    // doc comment for why a silently-slipping due date is the wrong default
    // for a preventive-service schedule.
    $result = MaintenanceIntervalType::Months->advance(Carbon::parse('2026-01-31'), 1);

    expect($result->toDateString())->toBe('2026-02-28');
});

it('advances a date by whole months normally when no overflow is possible', function (): void {
    $result = MaintenanceIntervalType::Months->advance(Carbon::parse('2026-01-15'), 3);

    expect($result->toDateString())->toBe('2026-04-15');
});

it('advances a date by the given number of years, clamping 29 February on a non-leap target', function (): void {
    $result = MaintenanceIntervalType::Years->advance(Carbon::parse('2024-02-29'), 1);

    expect($result->toDateString())->toBe('2025-02-28');
});

it('throws for usage-hours schedules because they have no calendar-based next-due date', function (): void {
    MaintenanceIntervalType::UsageHours->advance(Carbon::parse('2026-01-01'), 100);
})->throws(DomainException::class);

it('exposes every case as a string value list', function (): void {
    expect(MaintenanceIntervalType::values())->toBe(['days', 'weeks', 'months', 'years', 'usage_hours']);
});
