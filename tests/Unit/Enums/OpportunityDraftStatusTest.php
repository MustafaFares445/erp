<?php

declare(strict_types=1);

use App\Enums\OpportunityDraftStatus;

it('allows exactly the documented transitions', function (): void {
    expect(OpportunityDraftStatus::Draft->canTransitionTo(OpportunityDraftStatus::Approved))->toBeTrue()
        ->and(OpportunityDraftStatus::Draft->canTransitionTo(OpportunityDraftStatus::Rejected))->toBeTrue();
});

it('treats Approved and Rejected as terminal', function (): void {
    expect(OpportunityDraftStatus::Approved->canTransitionTo(OpportunityDraftStatus::Rejected))->toBeFalse()
        ->and(OpportunityDraftStatus::Approved->canTransitionTo(OpportunityDraftStatus::Draft))->toBeFalse()
        ->and(OpportunityDraftStatus::Rejected->canTransitionTo(OpportunityDraftStatus::Approved))->toBeFalse()
        ->and(OpportunityDraftStatus::Rejected->canTransitionTo(OpportunityDraftStatus::Draft))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (OpportunityDraftStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
