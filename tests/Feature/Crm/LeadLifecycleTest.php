<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces evidence-backed lead lifecycle and preserves CRM history through customer conversion', function (): void {
    $actor = User::factory()->admin()->create();
    $leads = app(LeadService::class);
    $interactions = app(InteractionService::class);

    $lead = $leads->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Jane',
        lastName: 'Buyer',
        companyName: 'Acme Dental',
        email: 'lead@example.test',
        phone: '+35722000000',
    ), $actor);

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->stageTransitions()->count())->toBe(1);

    $firstInteraction = $interactions->log(new InteractionData(
        subject: $lead,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now()->subHour(),
        summary: 'Initial qualification call',
    ), $actor);

    expect(fn () => $leads->transition($lead, LeadStatus::Qualified, $firstInteraction, $actor))
        ->toThrow(DomainException::class);

    $lead = $leads->transition($lead, LeadStatus::Contacted, $firstInteraction, $actor, 'Contact established');

    $secondInteraction = $interactions->log(new InteractionData(
        subject: $lead,
        type: InteractionType::Meeting,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Requirements qualification meeting',
    ), $actor);

    $lead = $leads->transition($lead, LeadStatus::Qualified, $secondInteraction, $actor, 'Qualified opportunity');

    $customer = app(LeadConversionService::class)->convert($lead, [
        'name' => 'Jane Buyer',
        'username' => 'jane-buyer',
        'email' => 'jane.login@example.test',
        'password' => 'secret-password',
        'contact_is_self' => true,
        'company_name' => 'Acme Dental',
        'company_email' => 'lead@example.test',
        'company_phone' => '+35722000000',
        'address' => '1 CRM Street',
        'country' => 'Cyprus',
        'city' => 'Nicosia',
        'latitude' => 35.1856,
        'longitude' => 33.3823,
    ], $actor);

    $lead = Lead::query()->findOrFail($lead->getKey());

    expect($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->converted_customer_id)->toBe($customer->getKey())
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->stageTransitions()->count())->toBe(4)
        ->and($customer->is_active)->toBeTrue();

    $reparented = Interaction::query()
        ->whereKey([$firstInteraction->getKey(), $secondInteraction->getKey()])
        ->get();

    expect($reparented)->toHaveCount(2);

    foreach ($reparented as $interaction) {
        expect($interaction->subject_type)->toBe($customer->getMorphClass())
            ->and((int) $interaction->subject_id)->toBe((int) $customer->getKey());
    }

    expect(fn () => $leads->assign($lead, User::factory()->admin()->create(), $actor))
        ->toThrow(DomainException::class);
});

/**
 * UAT F36 — `logInteraction()` used to call `InteractionService::log()` and
 * `LeadService::transition()` as two separately transactional calls, so a
 * rejected transition left the already-committed interaction (and
 * `leads.last_interaction_at`) on disk, and a retry after the "action
 * failed" notification duplicated it. `logAndAdvance()` wraps both in one
 * transaction and validates the transition before writing anything.
 */
it('logs no interaction and advances nothing when the requested stage advance is invalid', function (): void {
    $actor = User::factory()->admin()->create();
    $leads = app(LeadService::class);

    $lead = $leads->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Amir',
        lastName: 'Buyer',
        companyName: 'Delta Supplies',
        email: 'amir.buyer@example.test',
        phone: '+35722000001',
    ), $actor);

    expect(fn () => $leads->logAndAdvance(
        $lead,
        new InteractionData(
            subject: $lead,
            type: InteractionType::Call,
            direction: InteractionDirection::Outbound,
            occurredAt: now(),
            summary: 'Qualification call',
        ),
        LeadStatus::Qualified,
        $actor,
    ))->toThrow(DomainException::class);

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->last_interaction_at)->toBeNull()
        ->and($lead->interactions()->count())->toBe(0);
});
