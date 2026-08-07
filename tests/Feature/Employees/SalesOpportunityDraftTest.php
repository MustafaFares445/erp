<?php

declare(strict_types=1);

use App\Enums\OpportunityDraftStatus;
use App\Models\SalesOpportunityDraft;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Employees\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('never reaches Approved without a recorded reviewer and timestamp', function (): void {
    $draft = SalesOpportunityDraft::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OpportunityReviewService::class)->approve($draft, 'Looks promising');

    expect($draft->fresh()->status)->toBe(OpportunityDraftStatus::Approved)
        ->and($draft->fresh()->reviewed_by)->toBe($admin->id)
        ->and($draft->fresh()->reviewed_at)->not->toBeNull()
        ->and($draft->fresh()->review_notes)->toBe('Looks promising');
});

it('never reaches Rejected without a recorded reviewer and timestamp', function (): void {
    $draft = SalesOpportunityDraft::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OpportunityReviewService::class)->reject($draft, 'Not relevant');

    expect($draft->fresh()->status)->toBe(OpportunityDraftStatus::Rejected)
        ->and($draft->fresh()->reviewed_by)->toBe($admin->id)
        ->and($draft->fresh()->reviewed_at)->not->toBeNull();
});

it('treats Approved and Rejected as terminal — no further decision is ever recorded', function (): void {
    $approved = SalesOpportunityDraft::factory()->create(['status' => OpportunityDraftStatus::Approved]);
    $rejected = SalesOpportunityDraft::factory()->create(['status' => OpportunityDraftStatus::Rejected]);

    expect(fn () => app(OpportunityReviewService::class)->reject($approved))
        ->toThrow(InvalidStatusTransition::class);
    expect(fn () => app(OpportunityReviewService::class)->approve($rejected))
        ->toThrow(InvalidStatusTransition::class);
});
