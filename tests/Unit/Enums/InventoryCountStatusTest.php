<?php

declare(strict_types=1);

use App\Enums\InventoryCountStatus;

it('allows only the documented forward transitions', function (): void {
    expect(InventoryCountStatus::Draft->canTransitionTo(InventoryCountStatus::Counting))->toBeTrue()
        ->and(InventoryCountStatus::Draft->canTransitionTo(InventoryCountStatus::Cancelled))->toBeTrue()
        ->and(InventoryCountStatus::Draft->canTransitionTo(InventoryCountStatus::PendingReview))->toBeFalse()
        ->and(InventoryCountStatus::Draft->canTransitionTo(InventoryCountStatus::Confirmed))->toBeFalse()
        ->and(InventoryCountStatus::Counting->canTransitionTo(InventoryCountStatus::PendingReview))->toBeTrue()
        ->and(InventoryCountStatus::Counting->canTransitionTo(InventoryCountStatus::Cancelled))->toBeTrue()
        ->and(InventoryCountStatus::Counting->canTransitionTo(InventoryCountStatus::Confirmed))->toBeFalse()
        ->and(InventoryCountStatus::Counting->canTransitionTo(InventoryCountStatus::Draft))->toBeFalse()
        ->and(InventoryCountStatus::PendingReview->canTransitionTo(InventoryCountStatus::Confirmed))->toBeTrue()
        ->and(InventoryCountStatus::PendingReview->canTransitionTo(InventoryCountStatus::Counting))->toBeTrue()
        ->and(InventoryCountStatus::PendingReview->canTransitionTo(InventoryCountStatus::Cancelled))->toBeTrue()
        ->and(InventoryCountStatus::PendingReview->canTransitionTo(InventoryCountStatus::Draft))->toBeFalse();
});

it('treats confirmed and cancelled as terminal with no further transitions', function (): void {
    expect(InventoryCountStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(InventoryCountStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(InventoryCountStatus::Draft->isTerminal())->toBeFalse()
        ->and(InventoryCountStatus::Counting->isTerminal())->toBeFalse()
        ->and(InventoryCountStatus::PendingReview->isTerminal())->toBeFalse();

    foreach (InventoryCountStatus::cases() as $target) {
        expect(InventoryCountStatus::Confirmed->canTransitionTo($target))->toBeFalse()
            ->and(InventoryCountStatus::Cancelled->canTransitionTo($target))->toBeFalse();
    }
});
