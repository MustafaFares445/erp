<?php

declare(strict_types=1);

use App\Enums\BonusSuggestionStatus;
use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves its employee, sales plan, sales opportunity, and approver relations', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create();
    $opportunity = SalesOpportunity::factory()->create();
    $approver = User::factory()->admin()->create();

    $bonus = BonusSuggestion::factory()->approved()->create([
        'employee_id' => $employee->getKey(),
        'sales_plan_id' => $plan->getKey(),
        'sales_opportunity_id' => $opportunity->getKey(),
        'approved_by' => $approver->getKey(),
    ]);

    expect($bonus->employee()->first()->is($employee))->toBeTrue()
        ->and($bonus->salesPlan()->first()->is($plan))->toBeTrue()
        ->and($bonus->salesOpportunity()->first()->is($opportunity))->toBeTrue()
        ->and($bonus->approvedBy()->first()->is($approver))->toBeTrue()
        ->and($bonus->status)->toBe(BonusSuggestionStatus::Approved)
        ->and($bonus->approved_at)->not->toBeNull();
});

it('marks a rejected bonus suggestion with decision metadata', function (): void {
    $bonus = BonusSuggestion::factory()->rejected()->create(['decision_notes' => 'Insufficient revenue impact.']);

    expect($bonus->status)->toBe(BonusSuggestionStatus::Rejected)
        ->and($bonus->approved_at)->not->toBeNull()
        ->and($bonus->decision_notes)->toBe('Insufficient revenue impact.');
});
