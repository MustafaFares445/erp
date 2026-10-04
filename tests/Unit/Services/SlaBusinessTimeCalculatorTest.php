<?php

declare(strict_types=1);

use App\Models\SlaCalendar;
use App\Services\Support\SlaBusinessTimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds minutes across configured business periods and weekends', function (): void {
    $calendar = SlaCalendar::query()->create([
        'name' => 'Business hours',
        'timezone' => 'UTC',
        'is_24x7' => false,
        'is_default' => false,
        'is_active' => true,
    ]);

    foreach (range(1, 5) as $weekday) {
        $calendar->periods()->create(['weekday' => $weekday, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
    }

    $due = app(SlaBusinessTimeCalculator::class)->addBusinessMinutes(
        CarbonImmutable::parse('2026-10-02 16:00:00', 'UTC'),
        120,
        $calendar,
    );

    expect($due->toDateTimeString())->toBe('2026-10-05 10:00:00');
});

it('respects non-working exceptions and computes business minutes between timestamps', function (): void {
    $calendar = SlaCalendar::query()->create([
        'name' => 'Business hours',
        'timezone' => 'UTC',
        'is_24x7' => false,
        'is_default' => false,
        'is_active' => true,
    ]);

    foreach (range(1, 5) as $weekday) {
        $calendar->periods()->create(['weekday' => $weekday, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
    }

    $calendar->exceptions()->create([
        'date' => '2026-10-05',
        'name' => 'Holiday',
        'is_working_day' => false,
    ]);

    $calculator = app(SlaBusinessTimeCalculator::class);
    $from = CarbonImmutable::parse('2026-10-02 16:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC');

    expect($calculator->addBusinessMinutes($from, 120, $calendar)->toDateTimeString())->toBe('2026-10-06 10:00:00')
        ->and($calculator->businessMinutesBetween($from, $to, $calendar))->toBe(120);
});

it('preserves continuous arithmetic for the migration-compatible 24x7 calendar', function (): void {
    $calendar = SlaCalendar::query()->create([
        'name' => '24x7',
        'timezone' => 'UTC',
        'is_24x7' => true,
        'is_default' => true,
        'is_active' => true,
    ]);

    $from = CarbonImmutable::parse('2026-10-04 01:00:00', 'UTC');
    $calculator = app(SlaBusinessTimeCalculator::class);

    expect($calculator->addBusinessMinutes($from, 90, $calendar)->toDateTimeString())->toBe('2026-10-04 02:30:00')
        ->and($calculator->businessMinutesBetween($from, $from->addMinutes(90), $calendar))->toBe(90);
});
