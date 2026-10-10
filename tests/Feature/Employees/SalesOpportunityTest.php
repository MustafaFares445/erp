<?php

declare(strict_types=1);

use App\Enums\EmployeePermission;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SalesOpportunities\Pages\ViewSalesOpportunity;
use App\Filament\Resources\SalesOpportunities\RelationManagers\StageHistoryRelationManager;
use App\Models\OpportunityStageTransition;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Employees\OpportunityReviewService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('never reaches Approved without a recorded reviewer and timestamp', function (): void {
    $draft = SalesOpportunity::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OpportunityReviewService::class)->approve($draft, 'Looks promising');

    expect($draft->fresh()->status)->toBe(SalesOpportunityStatus::Approved)
        ->and($draft->fresh()->reviewed_by)->toBe($admin->id)
        ->and($draft->fresh()->reviewed_at)->not->toBeNull()
        ->and($draft->fresh()->review_notes)->toBe('Looks promising');
});

it('never reaches Rejected without a recorded reviewer and timestamp', function (): void {
    $draft = SalesOpportunity::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OpportunityReviewService::class)->reject($draft, 'Not relevant');

    expect($draft->fresh()->status)->toBe(SalesOpportunityStatus::Rejected)
        ->and($draft->fresh()->reviewed_by)->toBe($admin->id)
        ->and($draft->fresh()->reviewed_at)->not->toBeNull();
});

it('treats Approved and Rejected as terminal — no further decision is ever recorded', function (): void {
    $approved = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Approved]);
    $rejected = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Rejected]);

    expect(fn () => app(OpportunityReviewService::class)->reject($approved))
        ->toThrow(InvalidStatusTransition::class);
    expect(fn () => app(OpportunityReviewService::class)->approve($rejected))
        ->toThrow(InvalidStatusTransition::class);
});

it('records decisions from the sales opportunity table actions with a required rejection reason', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->givePermissionTo(EmployeePermission::OpportunityReview->value);

    $approved = SalesOpportunity::factory()->create();
    $rejected = SalesOpportunity::factory()->create();

    $list = Livewire::actingAs($reviewer)->test(ListSalesOpportunities::class);

    $list->callTableAction('approve', $approved)
        ->assertHasNoTableActionErrors()
        ->callTableAction('reject', $rejected, ['review_notes' => 'Not a qualified opportunity'])
        ->assertHasNoTableActionErrors();

    expect($approved->fresh()->status)->toBe(SalesOpportunityStatus::Approved)
        ->and($rejected->fresh()->status)->toBe(SalesOpportunityStatus::Rejected);
});

it('shows opportunity stage, origin and stage history as readable labels instead of raw values', function (): void {
    $reviewer = User::factory()->admin()->create();
    $opportunity = SalesOpportunity::factory()->manual()->create([
        'stage' => OpportunityStage::NeedsAnalysis,
        'origin' => OpportunityOrigin::ExistingCustomer,
    ]);
    $transition = OpportunityStageTransition::query()->create([
        'sales_opportunity_id' => $opportunity->getKey(),
        'from_stage' => OpportunityStage::Qualification,
        'to_stage' => OpportunityStage::NeedsAnalysis,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($reviewer)
        ->test(ListSalesOpportunities::class)
        ->assertTableColumnFormattedStateSet('stage', 'Needs Analysis', record: $opportunity);

    Livewire::actingAs($reviewer)
        ->test(ViewSalesOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSeeText('Needs Analysis')
        ->assertSeeText('Existing Customer')
        ->assertDontSeeText('needs_analysis')
        ->assertDontSeeText('existing_customer');

    Livewire::actingAs($reviewer)
        ->test(StageHistoryRelationManager::class, ['ownerRecord' => $opportunity, 'pageClass' => ViewSalesOpportunity::class])
        ->assertTableColumnFormattedStateSet('from_stage', 'Qualification', record: $transition)
        ->assertTableColumnFormattedStateSet('to_stage', 'Needs Analysis', record: $transition);
});

it('translates every opportunity stage, origin and close reason label into Arabic', function (string $enum): void {
    app()->setLocale('ar');

    foreach ($enum::cases() as $case) {
        $english = str($case->value)->replace('_', ' ')->headline()->toString();

        expect($case->getLabel())->not->toBe($english, $case->name);
    }
})->with([OpportunityStage::class, OpportunityOrigin::class, OpportunityCloseReason::class]);
