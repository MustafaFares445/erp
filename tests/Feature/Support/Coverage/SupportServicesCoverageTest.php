<?php

declare(strict_types=1);

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Enums\WarrantyClaimDecision;
use App\Events\MaintenanceRecordBilled;
use App\Events\TicketClosed;
use App\Events\TicketUpdated;
use App\Listeners\SendBusinessNotification;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\NotificationDelivery;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Notifications\NotificationTemplateCatalog;
use App\Services\Support\EquipmentReliabilityService;
use App\Services\Support\SupportReportService;
use App\Services\Support\TicketSatisfactionService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('buckets the open backlog by age and skips tickets without a creation time', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    Ticket::factory()->create(['status' => TicketStatus::Live, 'created_at' => now()->subHours(2)]);
    Ticket::factory()->create(['status' => TicketStatus::Live, 'created_at' => now()->subHours(50)]);
    Ticket::factory()->create(['status' => TicketStatus::Pending, 'created_at' => now()->subDays(5)]);
    Ticket::factory()->create(['status' => TicketStatus::Pending, 'created_at' => now()->subDays(12)]);
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'created_at' => now()->subDays(30)]);
    Ticket::factory()->create(['status' => TicketStatus::Live, 'created_at' => null]);

    expect(app(SupportReportService::class)->backlogAging($manager))->toBe([
        'total' => 5,
        'under_24h' => 1,
        'one_to_three_days' => 1,
        'four_to_seven_days' => 1,
        'over_seven_days' => 1,
    ]);
});

it('rejects out-of-range satisfaction ratings without storing a response', function (int $rating): void {
    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Closed,
        'closed_at' => now(),
    ]);

    try {
        app(TicketSatisfactionService::class)->submit($ticket, $customer->user, $rating);
        $this->fail('An out-of-range rating must be rejected.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('rating')
            ->and($validationException->errors()['rating'][0])->toBe('The rating must be between 1 and 5.');
    }

    expect(TicketSatisfactionResponse::query()->count())->toBe(0);
})->with([0, 6, -3]);

it('adds covered cost and warranty recovery balances to equipment reliability metrics', function (): void {
    $unit = SerializedInventoryUnit::factory()->create();

    $covered = MaintenanceRecord::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'maintenance_kind' => MaintenanceKind::Corrective,
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
    ]);
    MaintenanceThirdPartyCost::factory()->create(['maintenance_record_id' => $covered->id, 'amount_minor' => 5000]);
    WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $covered->id,
        'claimed_amount_minor' => 20000,
        'approved_amount_minor' => 15000,
        'received_amount_minor' => 5000,
    ]);

    $uncovered = MaintenanceRecord::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'maintenance_kind' => MaintenanceKind::Corrective,
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
    ]);
    MaintenanceThirdPartyCost::factory()->create(['maintenance_record_id' => $uncovered->id, 'amount_minor' => 3000]);

    $metrics = app(EquipmentReliabilityService::class)->metrics($unit);

    expect($metrics->lifetimeServiceCostMinor)->toBe(8000)
        ->and($metrics->warrantyCoveredCostMinor)->toBe(5000)
        ->and($metrics->recoveryClaimedMinor)->toBe(20000)
        ->and($metrics->recoveryReceivedMinor)->toBe(5000)
        ->and($metrics->recoveryOutstandingMinor)->toBe(10000);
});

it('ignores missing template texts when listing unsupported notification variables', function (): void {
    $catalog = app(NotificationTemplateCatalog::class);

    expect($catalog->unsupportedVariables('ticket.updated', null, 'Hello {{ nonexistent_variable }}', null))
        ->toBe(['nonexistent_variable'])
        ->and($catalog->unsupportedVariables('ticket.updated', null, null))->toBe([]);
});

it('sends no support notification when the ticket has no customer to notify', function (): void {
    config()->set('support.csat_enabled', true);
    $ticket = Ticket::factory()->make(['customer_id' => null, 'status' => TicketStatus::Closed]);

    $listener = app(SendBusinessNotification::class);
    $listener->handle(new TicketClosed($ticket));

    config()->set('support.csat_enabled', false);
    $listener->handle(new TicketUpdated($ticket));

    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('sends no billing notification when the maintenance record has no customer to notify', function (): void {
    $record = MaintenanceRecord::factory()->make(['customer_id' => null]);

    app(SendBusinessNotification::class)->handle(new MaintenanceRecordBilled($record));

    expect(NotificationDelivery::query()->count())->toBe(0);
});
