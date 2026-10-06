<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Enums\TicketPriority;
use App\Enums\VisitStatus;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('renders the customer list with presets, Starred and groups', function (): void {
    $pending = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Pending]);
    $approved = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Approved, 'is_active' => false]);

    $component = Livewire::test(ListCustomers::class)
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertCanSeeTableRecords([$pending, $approved])
        ->call('selectTableView', 'preset', 'pending_approval')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved])
        ->call('selectTableView', 'preset', 'inactive')
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending])
        ->call('selectTableView', 'preset', 'all');

    foreach (['approval_status', 'is_active', 'city', 'country', 'created_at'] as $group) {
        $component->set('tableGrouping', $group)->assertSuccessful()->assertCanSeeTableRecords([$pending, $approved]);
    }
});

it('stars customers and lists them under the Starred tab', function (): void {
    [$starred, $other] = CustomerProfile::factory()->count(2)->create()->all();

    Livewire::test(ListCustomers::class)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($this->admin))->toBeTrue();
});

it('filters customers with query-builder rules', function (): void {
    $approved = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Approved, 'is_active' => true]);
    $rejected = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Rejected, 'is_active' => false]);

    Livewire::test(ListCustomers::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'approval_status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [CustomerApprovalStatus::Approved->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$rejected])
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'is_active',
                    'data' => ['operator' => 'isTrue', 'settings' => []],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$rejected]);
});

it('renders the ticket list with presets, Starred and groups', function (): void {
    $employee = EmployeeProfile::factory()->create(['user_id' => $this->admin->id]);
    $mine = Ticket::factory()->create(['assigned_employee_id' => $employee->id]);
    $other = Ticket::factory()->create();

    $component = Livewire::test(ListTickets::class)
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertCanSeeTableRecords([$mine, $other])
        ->call('selectTableView', 'preset', 'mine')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->call('selectTableView', 'preset', 'all');

    foreach (['status', 'priority', 'type', 'customer.company_name', 'assignedEmployee.employee_code', 'created_at'] as $group) {
        $component->set('tableGrouping', $group)->assertSuccessful()->assertCanSeeTableRecords([$mine, $other]);
    }
});

it('stars tickets and lists them under the Starred tab', function (): void {
    [$starred, $other] = Ticket::factory()->count(2)->create()->all();

    Livewire::test(ListTickets::class)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($this->admin))->toBeTrue();
});

it('filters tickets with query-builder rules', function (): void {
    $urgent = Ticket::factory()->create(['priority' => TicketPriority::Urgent, 'title' => 'Printer on fire']);
    $low = Ticket::factory()->create(['priority' => TicketPriority::Low, 'title' => 'Change wallpaper']);

    Livewire::test(ListTickets::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'priority',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [TicketPriority::Urgent->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$urgent])
        ->assertCanNotSeeTableRecords([$low])
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'customer',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$low->customer_id]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$urgent]);
});

it('renders the visit list with presets, Starred and groups', function (): void {
    $employee = EmployeeProfile::factory()->create(['user_id' => $this->admin->id]);
    $mine = CustomerVisit::factory()->create(['employee_id' => $employee->id, 'status' => VisitStatus::Scheduled]);
    $other = CustomerVisit::factory()->completed()->create();

    $component = Livewire::test(ListVisits::class)
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertCanSeeTableRecords([$mine, $other])
        ->call('selectTableView', 'preset', 'mine')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->call('selectTableView', 'preset', 'completed')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$mine])
        ->call('selectTableView', 'preset', 'all');

    foreach (['status', 'employee.user.name', 'customer.company_name', 'planned_at'] as $group) {
        $component->set('tableGrouping', $group)->assertSuccessful()->assertCanSeeTableRecords([$mine, $other]);
    }
});

it('stars visits and lists them under the Starred tab', function (): void {
    [$starred, $other] = CustomerVisit::factory()->count(2)->create()->all();

    Livewire::test(ListVisits::class)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($this->admin))->toBeTrue();
});

it('filters visits with query-builder rules', function (): void {
    $planned = CustomerVisit::factory()->create(['status' => VisitStatus::Scheduled]);
    $completed = CustomerVisit::factory()->completed()->create();

    Livewire::test(ListVisits::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [VisitStatus::Completed->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$planned]);
});
