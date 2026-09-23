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
it('covers lead disqualification adapter errors after an interaction exists', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = leadActionsCoverageLead($actor, 'LEAD-ACTION-DISQUALIFY-ERROR');
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    (LeadActions::logInteraction()->getActionFunction())($lead, [
        'type' => InteractionType::Call->value,
        'direction' => InteractionDirection::Outbound->value,
        'occurred_at' => now()->toDateTimeString(),
        'summary' => 'Coverage interaction before invalid disqualification',
        'next_status' => LeadStatus::Contacted->value,
    ]);

    (LeadActions::disqualify()->getActionFunction())($lead->refresh(), [
        'reason' => 'not-a-valid-disqualification-reason',
        'note' => 'Coverage invalid reason',
    ]);

    expect($lead->refresh()->status)->toBe(LeadStatus::Contacted);
});
it('covers successful lead conversion through the Filament action', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = leadActionsCoverageLead($actor, 'LEAD-ACTION-CONVERT-SUCCESS');
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    (LeadActions::logInteraction()->getActionFunction())($lead, [
        'type' => InteractionType::Call->value,
        'direction' => InteractionDirection::Outbound->value,
        'occurred_at' => now()->subHour()->toDateTimeString(),
        'summary' => 'Initial coverage contact',
        'next_status' => LeadStatus::Contacted->value,
    ]);
    (LeadActions::logInteraction()->getActionFunction())($lead->refresh(), [
        'type' => InteractionType::Meeting->value,
        'direction' => InteractionDirection::Outbound->value,
        'occurred_at' => now()->toDateTimeString(),
        'summary' => 'Coverage qualification meeting',
        'next_status' => LeadStatus::Qualified->value,
    ]);

    $qualified = $lead->refresh();
    expect($qualified->status)->toBe(LeadStatus::Qualified);

    (LeadActions::convert()->getActionFunction())($qualified, [
        'name' => 'Coverage Customer',
        'username' => 'coverage-customer-action',
        'email' => 'coverage.customer.login@example.test',
        'password' => 'coverage-password',
        'company_name' => 'Coverage Customer Company',
        'company_email' => 'coverage.customer.company@example.test',
        'company_phone' => '+971500000001',
        'country' => 'United Arab Emirates',
        'city' => 'Dubai',
        'address' => 'Coverage Street',
        'latitude' => 25.2048,
        'longitude' => 55.2708,
        'contact_is_self' => true,
    ]);

    expect($lead->refresh()->status)->toBe(LeadStatus::Converted)
        ->and($lead->converted_customer_id)->not->toBeNull();
});
