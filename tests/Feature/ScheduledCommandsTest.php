<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules canonical inventory reconciliation daily at 01:30', function (): void {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'inventory:lots:reconcile --scheduled'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('30 1 * * *');
});


it('schedules reservation expiry hourly', function (): void {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'inventory:reservations:expire'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});
