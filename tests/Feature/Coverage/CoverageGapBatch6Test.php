<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\QuotationStatus;
use App\Enums\TicketStatus;
use App\Filament\Widgets\SupportNeedsAttention;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\Quotation;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('covers every support needs attention blocker label', function (): void {
    $method = new ReflectionMethod(SupportNeedsAttention::class, 'blockedBy');

    $ticket = static fn (TicketStatus $status, array $attributes = []): Ticket => (new Ticket)->forceFill([
        'status' => $status,
        'assigned_employee_id' => 1,
        'response_breached' => false,
        'resolution_breached' => false,
        'pending_reason' => null,
        ...$attributes,
    ]);

    expect($method->invoke(null, $ticket(TicketStatus::Pending)))->toBe('Awaiting triage')
        ->and($method->invoke(null, $ticket(TicketStatus::PendingPayment)))->toBe('Payment')
        ->and($method->invoke(null, $ticket(TicketStatus::Live, ['assigned_employee_id' => null])))->toBe('Assignment')
        ->and($method->invoke(null, $ticket(TicketStatus::WaitingCustomer)))->toBe('Customer')
        ->and($method->invoke(null, $ticket(TicketStatus::InProgress, ['response_breached' => true])))->toBe('SLA breach')
        ->and($method->invoke(null, $ticket(TicketStatus::Assigned, ['pending_reason' => 'Waiting for supplier'])))->toBe('Waiting for supplier')
        ->and($method->invoke(null, $ticket(TicketStatus::Assigned)))->toBe('Action required');
});

it('reports quotation expiry failures and returns failure from the command', function (): void {
    $quotation = Quotation::factory()->sent()->create([
        'expires_at' => today()->subDay(),
        'status' => QuotationStatus::Sent,
    ]);

    DB::statement("CREATE TRIGGER coverage_fail_quotation_update BEFORE UPDATE ON quotations BEGIN SELECT RAISE(ABORT, 'coverage forced quotation failure'); END;");

    try {
        expect(Artisan::call('sales:quotations:expire'))->toBe(1);
        expect(Artisan::output())
            ->toContain("Quotation #{$quotation->getKey()} failed to expire")
            ->toContain('1 failed');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS coverage_fail_quotation_update');
    }
});

it('reports maintenance schedule sweep failures and returns failure from the command', function (): void {
    MaintenanceScheduleOccurrence::factory()
        ->pastDue()
        ->for(
            MaintenanceSchedule::factory()->inactive(),
            'schedule',
        )
        ->create(['status' => OccurrenceStatus::Pending]);

    DB::statement("CREATE TRIGGER coverage_fail_occurrence_update BEFORE UPDATE ON maintenance_schedule_occurrences BEGIN SELECT RAISE(ABORT, 'coverage forced maintenance failure'); END;");

    try {
        expect(Artisan::call('maintenance:schedules:generate'))->toBe(1);
        expect(Artisan::output())->toContain('Preventive maintenance schedule sweep failed');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS coverage_fail_occurrence_update');
    }
});
