<?php

declare(strict_types=1);

use App\Enums\SlaMilestoneKey;
use App\Enums\SupportEntitlementStatus;
use App\Enums\TicketPriority;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\SlaPolicy;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\Ticket;
use App\Services\Support\SlaPolicyResolver;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SlaPolicySeeder)->run();
});

it('prefers a matching service-level entitlement policy over the priority default', function (): void {
    $customer = CustomerProfile::factory()->create();
    $level = SupportServiceLevel::query()->create(['code' => 'GOLD', 'name' => 'Gold', 'is_active' => true]);
    $entitlement = SupportEntitlement::query()->create([
        'customer_id' => $customer->id,
        'support_service_level_id' => $level->id,
        'starts_on' => today()->subDay(),
        'status' => SupportEntitlementStatus::Active,
    ]);

    $calendar = SlaPolicy::query()->firstOrFail()->calendar;
    $policy = SlaPolicy::query()->create([
        'name' => 'Gold urgent',
        'code' => 'gold-urgent',
        'is_active' => true,
        'precedence' => 10,
        'sla_calendar_id' => $calendar?->id,
        'priority' => TicketPriority::Urgent,
        'support_service_level_id' => $level->id,
        'response_target_minutes' => 15,
        'resolution_target_minutes' => 120,
    ]);
    $policy->milestones()->create([
        'key' => SlaMilestoneKey::FirstResponse,
        'target_minutes' => 15,
        'at_risk_before_minutes' => 5,
        'is_active' => true,
        'sort_order' => 10,
    ]);

    $ticket = Ticket::factory()->for($customer, 'customer')->withPriority(TicketPriority::Urgent)->create();
    $resolved = app(SlaPolicyResolver::class)->resolve($ticket);

    expect($resolved->policy->is($policy))->toBeTrue()
        ->and($resolved->entitlement?->is($entitlement))->toBeTrue();
});

it('prefers an equipment-specific entitlement over a customer-wide entitlement', function (): void {
    $customer = CustomerProfile::factory()->create();
    $standard = SupportServiceLevel::query()->create(['code' => 'STD-X', 'name' => 'Standard X', 'is_active' => true]);
    $premium = SupportServiceLevel::query()->create(['code' => 'PREM-X', 'name' => 'Premium X', 'is_active' => true]);
    $unit = SerializedInventoryUnit::factory()->create();

    SupportEntitlement::query()->create([
        'customer_id' => $customer->id,
        'support_service_level_id' => $standard->id,
        'starts_on' => today()->subDay(),
        'status' => SupportEntitlementStatus::Active,
    ]);
    $specific = SupportEntitlement::query()->create([
        'customer_id' => $customer->id,
        'support_service_level_id' => $premium->id,
        'serialized_inventory_unit_id' => $unit->id,
        'starts_on' => today()->subDay(),
        'status' => SupportEntitlementStatus::Active,
    ]);

    $ticket = Ticket::factory()->for($customer, 'customer')->create(['serialized_inventory_unit_id' => $unit->id]);

    expect(app(SlaPolicyResolver::class)->resolve($ticket)->entitlement?->is($specific))->toBeTrue();
});

it('rejects ambiguous policies with the same precedence and specificity', function (): void {
    $default = SlaPolicy::query()->where('priority', TicketPriority::Normal)->firstOrFail();
    $default->update(['precedence' => 1]);

    SlaPolicy::query()->create([
        'name' => 'Ambiguous normal',
        'code' => 'ambiguous-normal',
        'is_active' => true,
        'precedence' => 1,
        'sla_calendar_id' => $default->sla_calendar_id,
        'priority' => TicketPriority::Normal,
        'response_target_minutes' => 30,
        'resolution_target_minutes' => 90,
    ]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create();

    expect(fn () => app(SlaPolicyResolver::class)->resolve($ticket))
        ->toThrow(DomainException::class, 'same precedence and specificity');
});
