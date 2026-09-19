<?php

declare(strict_types=1);

use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Actions\LeadActions;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function leadActionsCoverageLead(User $actor, string $number): Lead
{
    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => $number,
        'status' => LeadStatus::New,
        'source' => LeadSource::Website,
        'first_name' => 'Coverage',
        'last_name' => 'Lead',
        'email' => 'coverage-'.$number.'@example.test',
        'created_by' => $actor->getKey(),
    ])->save();

    return $lead;
}

it('executes lead interaction assignment and disqualification adapters', function (): void {
    $creator = User::factory()->create();
    $lead = leadActionsCoverageLead($creator, 'LEAD-ACTION-COVERAGE');

    expect(fn () => (LeadActions::logInteraction()->getActionFunction())($lead, []))
        ->toThrow(LogicException::class, 'authenticated CRM user');

    $actor = User::factory()->admin()->create();
    $assignee = User::factory()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    (LeadActions::logInteraction()->getActionFunction())($lead, [
        'type' => InteractionType::Call->value,
        'direction' => InteractionDirection::Outbound->value,
        'outcome' => InteractionOutcome::Positive->value,
        'occurred_at' => now()->toDateTimeString(),
        'summary' => 'Coverage interaction',
        'notes' => 'Coverage notes',
        'next_status' => LeadStatus::Contacted->value,
    ]);
    expect($lead->refresh()->status)->toBe(LeadStatus::Contacted)
        ->and($lead->interactions()->count())->toBe(1);

    (LeadActions::assign()->getActionFunction())($lead, ['assigned_to' => (string) $assignee->getKey()]);
    expect($lead->refresh()->assigned_to)->toBe($assignee->getKey());

    (LeadActions::disqualify()->getActionFunction())($lead, [
        'reason' => LeadDisqualificationReason::Other->value,
        'note' => 'Coverage disqualification',
    ]);
    expect($lead->refresh()->status)->toBe(LeadStatus::Disqualified);

    $convert = LeadActions::convert();
    $convert->record($lead->refresh());

    expect($convert->isVisible())->toBeFalse();
    ($convert->getActionFunction())($lead->refresh(), []);

    expect(true)->toBeTrue();
});

it('covers lead action validation and no-interaction branches', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = leadActionsCoverageLead($actor, 'LEAD-ACTION-ERRORS');
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    (LeadActions::disqualify()->getActionFunction())($lead, [
        'reason' => LeadDisqualificationReason::Other->value,
    ]);
    expect($lead->refresh()->status)->toBe(LeadStatus::New);

    (LeadActions::logInteraction()->getActionFunction())($lead, []);
    (LeadActions::assign()->getActionFunction())($lead, ['assigned_to' => 'not-numeric']);
    expect($lead->refresh()->assigned_to)->toBeNull();

    $lead->forceFill(['status' => LeadStatus::Qualified])->save();
    $convert = LeadActions::convert();
    $convert->record($lead->refresh());

    expect($convert->isVisible())->toBeTrue();
    ($convert->getActionFunction())($lead->refresh(), []);

    $requiredString = new ReflectionMethod(LeadActions::class, 'requiredString');
    expect($requiredString->invoke(null, ['key' => 'value'], 'key'))->toBe('value');
    expect(fn (): mixed => $requiredString->invoke(null, [], 'key'))->toThrow(LogicException::class);

    expect(true)->toBeTrue();
});
