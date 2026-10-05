<?php

declare(strict_types=1);

use App\Enums\CommissioningStatus;
use App\Enums\EquipmentLoanStatus;
use App\Enums\ExternalRepairStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\QualityResolutionType;
use App\Enums\SupportPermission;
use App\Enums\TicketType;
use App\Models\CustomerReturnRequest;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentLoan;
use App\Models\InventoryLot;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\MaintenanceExternalRepair;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\SupportLifecycleReportService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function coverage86Reporter(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(SupportPermission::ReportView->value);

    return $user;
}

it('authorizes lifecycle reports and covers installation duration metrics', function (): void {
    $service = app(SupportLifecycleReportService::class);
    $unauthorized = User::factory()->create();

    expect($service->canView($unauthorized))->toBeFalse()
        ->and(fn () => $service->authorizeView($unauthorized))
        ->toThrow(DomainException::class, 'not authorized');

    $reporter = coverage86Reporter();
    expect($service->canView($reporter))->toBeTrue();

    $shipment = Shipment::factory()->arrived()->create([
        'confirmed_at' => now()->subDays(2),
    ]);

    EquipmentInstallation::factory()->create([
        'shipment_id' => $shipment->id,
        'installed_at' => now()->subDay(),
        'commissioned_at' => now()->subHours(12),
        'commissioning_status' => CommissioningStatus::Failed,
    ]);

    EquipmentInstallation::factory()->installed()->create([
        'commissioning_status' => CommissioningStatus::Pending,
    ]);

    $stats = $service->installations(
        $reporter,
        Carbon::parse(now()->subDays(3)->toDateString()),
        Carbon::parse(now()->toDateString()),
    );

    expect($stats['completed'])->toBeGreaterThanOrEqual(2)
        ->and($stats['commissioned'])->toBeGreaterThanOrEqual(1)
        ->and($stats['commissioning_failed'])->toBeGreaterThanOrEqual(1)
        ->and($stats['commissioning_failure_rate_percent'])->not->toBeNull()
        ->and($stats['average_delivery_to_installation_hours'])->toBe(24.0);
});

it('covers loan and external-repair duration projections plus recovery outstanding balance', function (): void {
    $service = app(SupportLifecycleReportService::class);
    $reporter = coverage86Reporter();

    EquipmentLoan::factory()->create([
        'status' => EquipmentLoanStatus::Returned,
        'issued_at' => now()->subDays(4),
        'returned_at' => now()->subDay(),
        'expected_return_at' => now()->subDays(2),
    ]);
    EquipmentLoan::factory()->issued()->create([
        'issued_at' => now()->subDays(2),
        'expected_return_at' => now()->subDay(),
    ]);

    $loanStats = $service->loaners($reporter, now()->subDays(10), now());
    expect($loanStats['active'])->toBeGreaterThanOrEqual(1)
        ->and($loanStats['overdue'])->toBeGreaterThanOrEqual(1)
        ->and($loanStats['returned'])->toBeGreaterThanOrEqual(1)
        ->and($loanStats['average_loan_duration_days'])->toBe(3.0);

    MaintenanceExternalRepair::factory()->create([
        'status' => ExternalRepairStatus::ReturnedToCompany,
        'requested_at' => now()->subDays(6),
        'returned_at' => now()->subDay(),
    ]);
    MaintenanceExternalRepair::factory()->create([
        'status' => ExternalRepairStatus::Repairing,
        'requested_at' => now()->subDays(2),
    ]);
    WarrantyRecoveryClaim::factory()->create([
        'claimed_amount_minor' => 10000,
        'approved_amount_minor' => 8000,
        'received_amount_minor' => 3000,
    ]);

    $rmaStats = $service->rma($reporter, now()->subDays(10), now());
    expect($rmaStats['open'])->toBeGreaterThanOrEqual(1)
        ->and($rmaStats['awaiting_supplier'])->toBeGreaterThanOrEqual(1)
        ->and($rmaStats['completed'])->toBeGreaterThanOrEqual(1)
        ->and($rmaStats['average_supplier_turnaround_days'])->toBe(5.0)
        ->and($rmaStats['warranty_recovery_outstanding_minor'])->toBeGreaterThanOrEqual(5000);
});

it('covers quality complaint product lot customer and returned-quantity projections', function (): void {
    $service = app(SupportLifecycleReportService::class);
    $reporter = coverage86Reporter();

    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $variant = ProductVariant::factory()->create(['name' => 'Coverage Lifecycle Product']);
    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-LIFECYCLE-86',
    ]);

    TicketProductContext::factory()->create([
        'ticket_id' => $ticket->id,
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => $lot->id,
        'quantity' => '2.500000',
    ]);

    $inventoryReturn = InventoryReturn::factory()->customer()->create([
        'customer_id' => $ticket->customer_id,
    ]);
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $inventoryReturn->id,
        'product_variant_id' => $variant->id,
        'transaction_quantity' => '1.500000',
    ]);
    $inventoryReturn->forceFill([
        'status' => InventoryReturnStatus::Posted,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ])->save();

    $returnRequest = CustomerReturnRequest::factory()->create([
        'customer_id' => $ticket->customer_id,
        'resulting_inventory_return_id' => $inventoryReturn->id,
    ]);
    TicketQualityResolution::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_type' => QualityResolutionType::Replacement,
        'customer_return_request_id' => $returnRequest->id,
    ]);

    $stats = $service->quality($reporter, now()->subDay(), now()->addDay());

    expect($stats['complaints'])->toBe(1)
        ->and($stats['affected_quantity'])->toBe(2.5)
        ->and($stats['returned_quantity'])->toBe(1.5)
        ->and($stats['by_product'])->toHaveCount(1)
        ->and($stats['by_product'][0]['product_variant_id'])->toBe($variant->id)
        ->and($stats['by_product'][0]['complaints'])->toBe(1)
        ->and($stats['by_lot'])->toHaveCount(1)
        ->and($stats['by_lot'][0]['inventory_lot_id'])->toBe($lot->id)
        ->and($stats['by_lot'][0]['affected_customers'])->toBe(1)
        ->and($stats['top_problematic_lots'])->toHaveCount(1);
});
