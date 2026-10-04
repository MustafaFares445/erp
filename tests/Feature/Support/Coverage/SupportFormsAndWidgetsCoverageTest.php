<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\SlaCalendars\Schemas\SlaCalendarForm;
use App\Filament\Resources\SupportEntitlements\Pages\CreateSupportEntitlement;
use App\Filament\Widgets\SupportStatistics;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('ignores calendar period rows that are not arrays or lack a start or end time', function (): void {
    expect(SlaCalendarForm::periodsProblem([
        'not-a-row',
        ['weekday' => 1, 'ends_at' => '10:00'],
        ['weekday' => 1, 'starts_at' => '09:00'],
        ['weekday' => 1, 'starts_at' => 900, 'ends_at' => 1000],
    ]))->toBeNull()
        ->and(SlaCalendarForm::periodsProblem([
            ['weekday' => 1, 'ends_at' => '10:00'],
            ['weekday' => 1, 'starts_at' => '11:00', 'ends_at' => '10:00'],
        ]))->toBe(__('Every working period must end after it starts.'));
});

it('lets the equipment rule pass an empty selection and rejects equipment outside the customer custody', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $ownUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);
    $foreignUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->id,
    ]);

    $component = Livewire::actingAs($manager)
        ->test(CreateSupportEntitlement::class)
        ->fillForm(['customer_id' => $customer->id]);

    $allRules = $component->instance()->form->getValidationRules();
    $equipmentRules = collect($allRules)
        ->filter(static fn (mixed $rules, string $path): bool => str_ends_with($path, 'serialized_inventory_unit_id'))
        ->flatten(1)
        ->filter(static fn (mixed $rule): bool => $rule instanceof Closure)
        ->values();

    expect($equipmentRules)->toHaveCount(1);

    $rule = $equipmentRules->first();
    $failures = [];
    $fail = static function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    $rule('serialized_inventory_unit_id', null, $fail);
    $rule('serialized_inventory_unit_id', '', $fail);
    $rule('serialized_inventory_unit_id', $ownUnit->id, $fail);

    expect($failures)->toBe([]);

    $rule('serialized_inventory_unit_id', $foreignUnit->id, $fail);
    $rule('serialized_inventory_unit_id', 'not-a-unit', $fail);

    expect($failures)->toHaveCount(2);
});

it('averages first-response time across tickets responded to in the period', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(SupportPermission::TicketView->value);
    $this->actingAs($viewer);

    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'response_sla_started_at' => now()->subHours(2),
        'first_response_at' => now()->subHours(2)->addMinutes(30),
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'response_sla_started_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(3)->addMinutes(90),
    ]);
    // Responded to but with no SLA start, so it must not skew the average.
    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'first_response_at' => now()->subHour(),
    ]);

    $widget = app(SupportStatistics::class);
    $widget->pageFilters = [];

    /** @var list<Stat> $stats */
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[4]->getValue())->toBe(__('dashboards.support.kpis.minutes', ['value' => 60.0]))
        ->and($stats[4]->getColor())->toBe('info');
});
