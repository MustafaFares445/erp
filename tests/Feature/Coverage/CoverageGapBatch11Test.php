<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\ExpenseStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers campaign creation authentication required-string guards and valid creation', function (): void {
    $page = new ReflectionClass(CreateCampaign::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreateCampaign::class, 'handleRecordCreation');

    auth()->logout();
    expect(fn (): mixed => $create->invoke($page, [
        'name' => 'Coverage campaign',
        'channel' => CampaignChannel::Email->value,
    ]))->toThrow(LogicException::class, 'authenticated CRM user');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    expect(fn (): mixed => $create->invoke($page, [
        'name' => '',
        'channel' => CampaignChannel::Email->value,
    ]))->toThrow(LogicException::class, 'non-empty string');

    $campaign = $create->invoke($page, [
        'name' => 'Coverage campaign',
        'channel' => CampaignChannel::Email->value,
        'content_template_id' => null,
    ]);

    expect($campaign->name)->toBe('Coverage campaign')
        ->and($campaign->channel)->toBe(CampaignChannel::Email);
});

it('covers expense create action authentication normalization and valid creation', function (): void {
    (new ChartOfAccountsSeeder)->run();

    $page = new ReflectionClass(ManageExpenses::class)->newInstanceWithoutConstructor();
    $headerActions = new ReflectionMethod(ManageExpenses::class, 'getHeaderActions');
    $action = $headerActions->invoke($page)[0];

    expect($action)->toBeInstanceOf(CreateAction::class);

    auth()->logout();
    expect(fn (): mixed => $action->process(null, ['data' => []]))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $normalize = new ReflectionMethod(ManageExpenses::class, 'normalizeData');
    expect($normalize->invoke(null, null))->toBe([])
        ->and($normalize->invoke(null, [0 => 'skip', 'description' => 'keep']))
        ->toBe(['description' => 'keep']);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $supplier = Supplier::factory()->create();
    $account = ChartAccount::query()->where('code', '5300')->sole();

    $expense = $action->process(null, ['data' => [
        'expense_number' => 'EXP-COVERAGE-ACTION',
        'supplier_id' => $supplier->getKey(),
        'expense_account_id' => $account->getKey(),
        'expense_date' => today()->toDateString(),
        'due_date' => today()->addDays(10)->toDateString(),
        'merchant_name' => 'Coverage merchant',
        'description' => 'Coverage action expense',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '0.00',
        'status' => ExpenseStatus::Draft->value,
        'receipt' => null,
        77 => 'ignored',
    ]]);

    expect($expense)->toBeInstanceOf(Expense::class)
        ->and($expense->description)->toBe('Coverage action expense');
});

it('covers maintenance schedule deactivate and skip action callbacks including caught skip failure', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $schedule = MaintenanceSchedule::factory()->create(['created_by' => $actor->getKey()]);
    $deactivate = MaintenanceScheduleActions::deactivate()->getActionFunction();
    expect($deactivate)->toBeInstanceOf(Closure::class);
    $deactivate($schedule);

    expect($schedule->refresh()->is_active)->toBeFalse();

    $schedule = MaintenanceSchedule::factory()->create(['created_by' => $actor->getKey()]);
    $occurrence = MaintenanceScheduleOccurrence::factory()->for($schedule, 'schedule')->create([
        'status' => OccurrenceStatus::Pending,
    ]);

    $skip = MaintenanceScheduleActions::skipOccurrence()->getActionFunction();
    expect($skip)->toBeInstanceOf(Closure::class);

    $skip($occurrence, ['reason' => 'Coverage skip reason']);
    expect($occurrence->refresh()->status)->toBe(OccurrenceStatus::Skipped);

    $skip($occurrence, ['reason' => 'Coverage second skip']);
    expect($occurrence->refresh()->status)->toBe(OccurrenceStatus::Skipped);
});
