<?php

declare(strict_types=1);

use App\Data\Sales\OpportunityData;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerProfile;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Sales\OpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a human opportunity without a transcription when a customer is present', function (): void {
    $actor = User::factory()->create(); $customer = CustomerProfile::factory()->create();
    $opportunity = app(OpportunityService::class)->create(new OpportunityData(summary: 'Expansion opportunity', customerId: $customer->getKey(), title: 'Expansion', estimatedValueMinor: 1250000), $actor);
    expect($opportunity->voice_note_transcription_id)->toBeNull()->and($opportunity->status)->toBe(SalesOpportunityStatus::Approved)->and($opportunity->stage)->toBe(OpportunityStage::Qualification)->and($opportunity->origin)->toBe(OpportunityOrigin::ExistingCustomer);
});

it('refuses a human opportunity with neither customer nor lead', function (): void {
    $actor = User::factory()->create();
    expect(fn () => app(OpportunityService::class)->create(new OpportunityData(summary: 'Unattributed'), $actor))->toThrow(ValidationException::class);
});

it('keeps AI review separate from the sales stage', function (): void {
    $reviewer = User::factory()->create(); $opportunity = SalesOpportunity::factory()->create(); $this->actingAs($reviewer);
    $approved = app(OpportunityReviewService::class)->approve($opportunity, 'Valid');
    expect($approved->status)->toBe(SalesOpportunityStatus::Approved)->and($approved->stage)->toBe(OpportunityStage::Qualification);
});

it('requires a controlled close reason and writes stage history', function (): void {
    $actor = User::factory()->create(); $customer = CustomerProfile::factory()->create();
    $opportunity = app(OpportunityService::class)->create(new OpportunityData(summary: 'Deal', customerId: $customer->getKey()), $actor);
    $service = app(OpportunityService::class);
    expect(fn () => $service->transitionStage($opportunity, OpportunityStage::ClosedLost, null, $actor))->toThrow(ValidationException::class);
    $closed = $service->transitionStage($opportunity, OpportunityStage::ClosedLost, null, $actor, OpportunityCloseReason::LostOnPrice, 'Budget gap');
    expect($closed->stage)->toBe(OpportunityStage::ClosedLost)->and($closed->close_reason)->toBe(OpportunityCloseReason::LostOnPrice)->and($closed->stageTransitions()->count())->toBe(1);
    expect(fn () => $service->transitionStage($closed, OpportunityStage::Proposal, null, $actor))->toThrow(InvalidStatusTransition::class);
});
