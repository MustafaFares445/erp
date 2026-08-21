<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;

it('allows exactly the contracts/maintenance-lifecycle.md §1 transitions and rejects everything else', function (): void {
    $expected = [
        MaintenanceStatus::Open->value => [MaintenanceStatus::InProgress, MaintenanceStatus::Cancelled],
        MaintenanceStatus::InProgress->value => [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled],
        MaintenanceStatus::Closed->value => [],
        MaintenanceStatus::Cancelled->value => [],
    ];

    foreach (MaintenanceStatus::cases() as $from) {
        $allowed = $expected[$from->value];

        foreach (MaintenanceStatus::cases() as $to) {
            $shouldAllow = in_array($to, $allowed, true);

            expect($from->canTransitionTo($to))
                ->toBe($shouldAllow, sprintf('Expected %s -> %s to be ', $from->value, $to->value).($shouldAllow ? 'allowed' : 'rejected'));
        }
    }
});

it('treats closed and cancelled as terminal', function (): void {
    expect(MaintenanceStatus::Closed->allowedTransitions())->toBe([])
        ->and(MaintenanceStatus::Cancelled->allowedTransitions())->toBe([]);
});
