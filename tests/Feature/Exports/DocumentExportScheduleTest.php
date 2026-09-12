<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules retained document export cleanup daily at 03:00', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'exports:cleanup'),
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 3 * * *');
});
