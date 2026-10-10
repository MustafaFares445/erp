<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function supportListFitUser(string $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  class-string  $page
 */
function supportListFitTable(User $user, string $page, string $locale): Table
{
    app()->setLocale($locale);

    return Livewire::actingAs($user)->test($page)->instance()->getTable();
}

/**
 * @return list<string>
 */
function supportListFitFirstLevelActionNames(Table $table): array
{
    return collect($table->getRecordActions())
        ->reject(static fn (mixed $action): bool => $action instanceof ActionGroup)
        ->map(static fn (mixed $action): string => (string) $action->getName())
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function supportListFitGroupedActionNames(Table $table): array
{
    return collect($table->getRecordActions())
        ->filter(static fn (mixed $action): bool => $action instanceof ActionGroup)
        ->flatMap(static fn (ActionGroup $group): array => array_keys($group->getFlatActions()))
        ->values()
        ->all();
}

/**
 * @param  list<string>  $names
 * @return list<Column>
 */
function supportListFitColumns(Table $table, array $names): array
{
    return array_map(static function (string $name) use ($table): Column {
        $column = $table->getColumn($name);

        expect($column)->toBeInstanceOf(Column::class);

        return $column;
    }, $names);
}

it('keeps the operational ticket columns visible by default and the secondary ones toggled off', function (string $locale): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListTickets::class, $locale);

    expect(array_values(array_map(static fn (Column $column): string => $column->getName(), $table->getVisibleColumns())))->toBe([
        'is_favorited',
        'ticket_number',
        'customer.company_name',
        'priority',
        'workspace_stage',
        'next_action',
        'assignedEmployee.user.name',
        'sla_state',
    ]);

    foreach (supportListFitColumns($table, ['type', 'equipment', 'warranty_status', 'blocked_by', 'supportTeam.name', 'updated_at', 'created_at', 'resolution_due_at']) as $column) {
        expect($column->isToggleable())->toBeTrue()
            ->and($column->isToggledHidden())->toBeTrue();
    }
})->with(['en', 'ar']);

it('lets long ticket text wrap and gives the next action a usable width so the list fits a 1440px desktop', function (string $locale): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListTickets::class, $locale);

    foreach (supportListFitColumns($table, ['ticket_number', 'customer.company_name', 'next_action', 'assignedEmployee.user.name']) as $column) {
        expect($column->canWrap())->toBeTrue("{$column->getName()} must wrap instead of widening the table");
    }

    [$nextAction, $sla] = supportListFitColumns($table, ['next_action', 'sla_state']);

    expect($nextAction->getExtraHeaderAttributes())->toMatchArray(['style' => 'min-width: 9rem'])
        ->and($sla->canHeaderWrap())->toBeTrue()
        ->and($sla->getExtraCellAttributes())->toMatchArray(['class' => 'ierp-ta-wrap-badge']);
})->with(['en', 'ar']);

it('keeps every ticket row action in the overflow menu because the row itself opens the ticket', function (): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListTickets::class, 'en');

    expect(supportListFitFirstLevelActionNames($table))->toBe([])
        ->and(supportListFitGroupedActionNames($table))->toContain('view', 'edit', 'triage', 'cancel', 'archive', 'restore');
});

// One record per component: Filament resolves a row action's visibility against the first rendered record.
it('keeps the ticket row action visibility rules', function (TicketStatus $status, bool $canCancel): void {
    $ticket = Ticket::factory()->create(['status' => $status]);

    $component = Livewire::actingAs(supportListFitUser('Support Manager'))
        ->test(ListTickets::class)
        ->assertTableActionVisible('view', $ticket)
        ->assertTableActionVisible('edit', $ticket)
        ->assertTableActionVisible('archive', $ticket)
        ->assertTableActionHidden('restore', $ticket);

    $canCancel
        ? $component->assertTableActionVisible('cancel', $ticket)
        : $component->assertTableActionHidden('cancel', $ticket);
})->with([
    'live ticket can be cancelled' => [TicketStatus::Live, true],
    'closed ticket cannot be cancelled' => [TicketStatus::Closed, false],
]);

it('keeps the operational maintenance columns visible by default and the commercial ones toggled off', function (string $locale): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListMaintenanceRequests::class, $locale);

    expect(array_values(array_map(static fn (Column $column): string => $column->getName(), $table->getVisibleColumns())))->toBe([
        'is_favorited',
        'id',
        'customer.company_name',
        'equipment',
        'status',
        'warranty_status',
        'coverage_decision',
    ]);

    foreach (supportListFitColumns($table, ['customer_responsibility', 'next_action', 'billing_type', 'updated_at', 'warranty_expiry_date', 'created_at']) as $column) {
        expect($column->isToggleable())->toBeTrue()
            ->and($column->isToggledHidden())->toBeTrue();
    }
})->with(['en', 'ar']);

it('lets long maintenance text and badge labels wrap so the list fits a 1440px desktop', function (string $locale): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListMaintenanceRequests::class, $locale);

    foreach (supportListFitColumns($table, ['customer.company_name', 'equipment']) as $column) {
        expect($column->canWrap())->toBeTrue("{$column->getName()} must wrap instead of widening the table");
    }

    foreach (supportListFitColumns($table, ['status', 'warranty_status', 'coverage_decision']) as $column) {
        expect($column->getExtraCellAttributes())->toMatchArray(['class' => 'ierp-ta-wrap-badge']);
    }
})->with(['en', 'ar']);

it('keeps the primary step button first and tucks view and edit into the overflow menu', function (): void {
    $table = supportListFitTable(supportListFitUser('Support Manager'), ListMaintenanceRequests::class, 'en');

    expect(supportListFitFirstLevelActionNames($table))->toContain('startRepairRow', 'confirmApprovalRow')->not->toContain('view', 'edit')
        ->and(supportListFitGroupedActionNames($table))->toBe(['view', 'edit', 'cancel', 'archive', 'restore']);
});

// One record per component: Filament resolves a row action's visibility against the first rendered record.
it('keeps the maintenance row action visibility rules', function (MaintenanceStatus $status, bool $isOpenForChanges): void {
    $record = MaintenanceRecord::factory()->create(['status' => $status]);

    $component = Livewire::actingAs(supportListFitUser('Support Manager'))
        ->test(ListMaintenanceRequests::class)
        ->assertTableActionVisible('view', $record);

    if ($isOpenForChanges) {
        $component->assertTableActionVisible('edit', $record)->assertTableActionVisible('cancel', $record);
    } else {
        $component->assertTableActionHidden('edit', $record)->assertTableActionHidden('cancel', $record);
    }
})->with([
    'ready for repair stays editable and cancellable' => [MaintenanceStatus::ReadyForRepair, true],
    'closed is locked' => [MaintenanceStatus::Closed, false],
]);

it('shows the start repair button only for a request that is ready for repair', function (MaintenanceStatus $status, bool $isShown): void {
    $record = MaintenanceRecord::factory()->create(['status' => $status]);

    $component = Livewire::actingAs(supportListFitUser('Support Manager'))->test(ListMaintenanceRequests::class);

    $isShown
        ? $component->assertTableActionVisible('startRepairRow', $record)
        : $component->assertTableActionHidden('startRepairRow', $record);
})->with([
    'ready for repair' => [MaintenanceStatus::ReadyForRepair, true],
    'closed' => [MaintenanceStatus::Closed, false],
]);

it('does not widen maintenance row access: an agent can view but not edit or delete', function (): void {
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs(supportListFitUser('Support Agent'))
        ->test(ListMaintenanceRequests::class)
        ->assertTableActionVisible('view', $record)
        ->assertTableActionHidden('edit', $record)
        ->assertTableActionHidden('archive', $record);
});
