<?php

declare(strict_types=1);

use App\Models\BonusSuggestion;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves its salary calculation and bonus suggestion relations', function (): void {
    $plan = SalesPlan::factory()->create();
    $calculation = EmployeeSalaryCalculation::factory()->create(['sales_plan_id' => $plan->getKey()]);
    $bonus = BonusSuggestion::factory()->create(['sales_plan_id' => $plan->getKey()]);

    expect($plan->salaryCalculations()->first()->is($calculation))->toBeTrue()
        ->and($plan->bonusSuggestions()->first()->is($bonus))->toBeTrue();
});
