<?php

declare(strict_types=1);

use App\Enums\BonusSuggestionStatus;

it('allows exactly the documented transitions', function (): void {
    expect(BonusSuggestionStatus::Pending->canTransitionTo(BonusSuggestionStatus::Approved))->toBeTrue()
        ->and(BonusSuggestionStatus::Pending->canTransitionTo(BonusSuggestionStatus::Rejected))->toBeTrue();
});

it('treats Approved and Rejected as terminal', function (): void {
    expect(BonusSuggestionStatus::Approved->canTransitionTo(BonusSuggestionStatus::Rejected))->toBeFalse()
        ->and(BonusSuggestionStatus::Approved->canTransitionTo(BonusSuggestionStatus::Pending))->toBeFalse()
        ->and(BonusSuggestionStatus::Rejected->canTransitionTo(BonusSuggestionStatus::Approved))->toBeFalse()
        ->and(BonusSuggestionStatus::Rejected->canTransitionTo(BonusSuggestionStatus::Pending))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (BonusSuggestionStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
