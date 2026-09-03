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

it('schedules overdue invoice reminders daily', function (): void {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'notifications:overdue-invoices'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 0 * * *');
});

it('schedules failed notification retries hourly', function (): void {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'notifications:retry-failed'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});


it('schedules expiring-lot reminders daily', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'notifications:expiring-lots'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 0 * * *');
});

it('schedules pending-approval reminders daily', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'notifications:pending-approvals'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 0 * * *');
});

it('schedules visit-due reminders daily', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'notifications:visits-due'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 0 * * *');
});
