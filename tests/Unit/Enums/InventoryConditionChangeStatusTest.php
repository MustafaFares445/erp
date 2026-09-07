<?php

declare(strict_types=1);

use App\Enums\InventoryConditionChangeStatus;

it('allows only draft to posted or cancelled transitions', function (): void {
    expect(InventoryConditionChangeStatus::Draft->canTransitionTo(InventoryConditionChangeStatus::Posted))->toBeTrue()
        ->and(InventoryConditionChangeStatus::Draft->canTransitionTo(InventoryConditionChangeStatus::Cancelled))->toBeTrue()
        ->and(InventoryConditionChangeStatus::Draft->canTransitionTo(InventoryConditionChangeStatus::Draft))->toBeFalse()
        ->and(InventoryConditionChangeStatus::Posted->canTransitionTo(InventoryConditionChangeStatus::Draft))->toBeFalse()
        ->and(InventoryConditionChangeStatus::Posted->canTransitionTo(InventoryConditionChangeStatus::Cancelled))->toBeFalse()
        ->and(InventoryConditionChangeStatus::Cancelled->canTransitionTo(InventoryConditionChangeStatus::Posted))->toBeFalse();
});

it('treats posted and cancelled condition changes as terminal', function (): void {
    expect(InventoryConditionChangeStatus::Draft->isTerminal())->toBeFalse()
        ->and(InventoryConditionChangeStatus::Posted->isTerminal())->toBeTrue()
        ->and(InventoryConditionChangeStatus::Cancelled->isTerminal())->toBeTrue();
});
