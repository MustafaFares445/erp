<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerProfile;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Sales\SalesOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a manual opportunity without an AI transcript', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $opportunity = app(SalesOpportunityService::class)->createManual([
        'summary' => 'Expansion opportunity',
        'customer_profile_id' => $customer->getKey(),
        'estimated_value' => 12500,
        'expected_close_date' => now()->addMonth()->toDateString(),
    ], $actor);

    expect($opportunity->voice_note_transcription_id)->toBeNull()
        ->and($opportunity->review_status)->toBe(SalesOpportunityReviewStatus::NotRequired)
        ->and($opportunity->status)->toBe(SalesOpportunityStatus::Draft)
        ->and($opportunity->customer_profile_id)->toBe($customer->getKey())
        ->and($opportunity->owner_id)->toBe($actor->getKey());
});

it('keeps AI review separate from the commercial pipeline stage', function (): void {
    $reviewer = User::factory()->create();
    $opportunity = SalesOpportunity::factory()->create();
    $this->actingAs($reviewer);

    $approved = app(OpportunityReviewService::class)->approve($opportunity, 'Valid opportunity');

    expect($approved->review_status)->toBe(SalesOpportunityReviewStatus::Approved)
        ->and($approved->status)->toBe(SalesOpportunityStatus::Draft)
        ->and($approved->reviewed_by)->toBe($reviewer->getKey());
});

it('closes a rejected AI opportunity as lost with evidence', function (): void {
    $reviewer = User::factory()->create();
    $opportunity = SalesOpportunity::factory()->create();
    $this->actingAs($reviewer);

    $rejected = app(OpportunityReviewService::class)->reject($opportunity, 'False positive');

    expect($rejected->review_status)->toBe(SalesOpportunityReviewStatus::Rejected)
        ->and($rejected->status)->toBe(SalesOpportunityStatus::ClosedLost)
        ->and($rejected->close_reason)->toBe('False positive')
        ->and($rejected->closed_at)->not->toBeNull();
});

it('enforces controlled qualified to won and terminal transitions', function (): void {
    $actor = User::factory()->create();
    $opportunity = SalesOpportunity::factory()->manual()->create();
    $service = app(SalesOpportunityService::class);

    $qualified = $service->qualify($opportunity, $actor);
    $won = $service->closeWon($qualified, 'Purchase commitment received', $actor);

    expect($qualified->status)->toBe(SalesOpportunityStatus::Qualified)
        ->and($won->status)->toBe(SalesOpportunityStatus::ClosedWon)
        ->and($won->close_reason)->toBe('Purchase commitment received');

    expect(fn () => $service->closeLost($won, 'Cannot rewrite a terminal outcome', $actor))
        ->toThrow(InvalidStatusTransition::class);
});
