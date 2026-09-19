<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function leadCoverageCreate(LeadService $service, User $actor, ?string $email): Lead
{
    return $service->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Coverage',
        email: $email,
    ), $actor);
}
it('assigns active leads and rejects assignment or updates for terminal leads', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $assignee = User::factory()->employee()->create();
    $service = app(LeadService::class);
    $lead = leadCoverageCreate($service, $actor, 'assign@example.test');

    $assigned = $service->assign($lead, $assignee, $actor);
    expect($assigned->assigned_to)->toBe($assignee->getKey());
    expect($service->assign($assigned, null, $actor)->assigned_to)->toBeNull();

    $lead->forceFill(['status' => LeadStatus::Converted])->saveQuietly();
    expect(fn () => $service->assign($lead->refresh(), $assignee, $actor))
        ->toThrow(DomainException::class, 'A terminal lead cannot be reassigned.');
    expect(fn () => $service->update($lead->refresh(), new LeadData(
        source: LeadSource::Website,
        firstName: 'Changed',
    ), $actor))->toThrow(DomainException::class, 'A converted or disqualified lead is immutable.');
});

it('rejects direct terminal transitions and invalid interaction evidence', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(LeadService::class);
    $interactions = app(InteractionService::class);
    $leadA = leadCoverageCreate($service, $actor, 'evidence-a@example.test');
    $leadB = leadCoverageCreate($service, $actor, 'evidence-b@example.test');
    $interactionA = $interactions->log(new InteractionData(
        subject: $leadA,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Coverage evidence',
    ), $actor);

    expect(fn () => $service->transition($leadA, LeadStatus::Converted, $interactionA, $actor))
        ->toThrow(DomainException::class, 'Use the conversion or disqualification workflow for terminal lead states.');
    expect(fn () => $service->transition($leadA, LeadStatus::Disqualified, $interactionA, $actor))
        ->toThrow(DomainException::class, 'Use the conversion or disqualification workflow for terminal lead states.');
    expect(fn () => $service->transition($leadA, LeadStatus::Qualified, $interactionA, $actor))
        ->toThrow(DomainException::class, 'Lead cannot transition from new to qualified.');
    expect(fn () => $service->disqualify(
        $leadB, LeadDisqualificationReason::Other, $interactionA, $actor,
    ))->toThrow(DomainException::class, 'A lead stage transition requires an interaction recorded against that lead.');
});

it('disqualifies a lead with evidence and rejects repeat disqualification', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(LeadService::class);
    $lead = leadCoverageCreate($service, $actor, 'disqualify@example.test');
    $interaction = app(InteractionService::class)->log(new InteractionData(
        subject: $lead,
        type: InteractionType::Note,
        direction: InteractionDirection::Inbound,
        occurredAt: now(),
        summary: 'Not a fit',
    ), $actor);

    $disqualified = $service->disqualify(
        $lead, LeadDisqualificationReason::NoFit, $interaction, $actor, 'Coverage note',
    );
    expect($disqualified->status)->toBe(LeadStatus::Disqualified)
        ->and($disqualified->disqualified_reason)->toBe(LeadDisqualificationReason::NoFit);

    expect(fn () => $service->disqualify(
        $disqualified, LeadDisqualificationReason::Other, $interaction, $actor,
    ))->toThrow(DomainException::class, 'The lead cannot be disqualified from its current state.');
});

it('rejects customer and lead duplicate emails while allowing blank email', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(LeadService::class);
    CustomerProfile::factory()->create(['email' => 'customer-duplicate@example.test']);
    expect(fn (): Lead => leadCoverageCreate($service, $actor, ' CUSTOMER-DUPLICATE@example.test '))
        ->toThrow(DomainException::class, 'This email already belongs to a customer.');

    leadCoverageCreate($service, $actor, 'lead-duplicate@example.test');
    expect(fn (): Lead => leadCoverageCreate($service, $actor, 'LEAD-DUPLICATE@example.test'))
        ->toThrow(DomainException::class, 'A lead with this email already exists.');

    $blank = leadCoverageCreate($service, $actor, null);
    expect($blank->email)->toBeNull();
});

it('rejects nonnumeric CRM model keys in the internal key guard', function (): void {
    $service = app(LeadService::class);
    $method = new ReflectionMethod($service, 'modelKey');

    $record = new class extends Model
    {
        protected $keyType = 'string';

        public $incrementing = false;
    };
    $record->setAttribute($record->getKeyName(), 'not-numeric');

    expect(fn (): mixed => $method->invoke($service, $record))
        ->toThrow(DomainException::class, 'CRM records require an integer primary key.');
});
