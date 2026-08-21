<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;

it('allows exactly the documented transitions', function (): void {
    expect(SalesOpportunityStatus::Draft->canTransitionTo(SalesOpportunityStatus::Approved))->toBeTrue()
        ->and(SalesOpportunityStatus::Draft->canTransitionTo(SalesOpportunityStatus::Rejected))->toBeTrue();
});

it('treats Approved and Rejected as terminal', function (): void {
    expect(SalesOpportunityStatus::Approved->canTransitionTo(SalesOpportunityStatus::Rejected))->toBeFalse()
        ->and(SalesOpportunityStatus::Approved->canTransitionTo(SalesOpportunityStatus::Draft))->toBeFalse()
        ->and(SalesOpportunityStatus::Rejected->canTransitionTo(SalesOpportunityStatus::Approved))->toBeFalse()
        ->and(SalesOpportunityStatus::Rejected->canTransitionTo(SalesOpportunityStatus::Draft))->toBeFalse();
});

it('rejects every self-transition', function (): void {
    foreach (SalesOpportunityStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
