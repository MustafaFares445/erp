<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Campaigns\RelationManagers\CampaignRecipientsRelationManager;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Explicit extension avoids inherited Pest.php path classification ambiguity
// caused by the worker-05 Windows shared-vendor/junction snapshot.
uses(TestCase::class, RefreshDatabase::class);

it('keeps invalid lead advancement atomic', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'E2E',
        lastName: 'Evidence',
        companyName: 'Worker 05 isolated test',
        email: 'agent5.atomic@example.test',
        phone: '+35722123450',
    ), $actor);

    $interaction = new InteractionData(
        subject: $lead,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Contact evidence from isolated CRM test',
    );

    expect(fn () => app(LeadService::class)->logAndAdvance(
        $lead, $interaction, LeadStatus::Qualified, $actor,
    ))->toThrow(DomainException::class);

    expect($lead->fresh()->status)->toBe(LeadStatus::New)
        ->and($lead->interactions()->count())->toBe(0)
        ->and($lead->fresh()->last_interaction_at)->toBeNull();

    $logged = app(LeadService::class)->logAndAdvance(
        $lead, $interaction, LeadStatus::Contacted, $actor,
    );
    expect($logged->exists)->toBeTrue()
        ->and($lead->fresh()->status)->toBe(LeadStatus::Contacted)
        ->and($lead->interactions()->count())->toBe(1);
});

it('translates the Campaign recipient title and respects the Arabic language', function (): void {
    app()->setLocale('ar');
    $actual = CampaignRecipientsRelationManager::getTitle(
        new Campaign, '',
    );
    expect($actual)->toBe(__('Recipients'))->not->toBe('Recipients');
    app()->setLocale('en');
});
