<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\QuotationStatus;
use App\Enums\TicketBlocker;
use App\Enums\TicketStatus;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Services\Support\TicketBlockerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('covers every support needs attention blocker label', function (): void {
    $resolver = app(TicketBlockerResolver::class);

    $ticket = static fn (TicketStatus $status, array $attributes = []): Ticket => (new Ticket)->forceFill([
        'status' => $status,
        'assigned_employee_id' => 1,
        'response_breached' => false,
        'resolution_breached' => false,
        ...$attributes,
    ]);

    expect($resolver->resolve($ticket(TicketStatus::Pending))->label())->toBe(TicketBlocker::TriageRequired->label())
        ->and($resolver->resolve($ticket(TicketStatus::PendingPayment))->label())->toBe(TicketBlocker::DiagnosticPayment->label())
        ->and($resolver->resolve($ticket(TicketStatus::Live, ['assigned_employee_id' => null]))->label())->toBe(TicketBlocker::Assignment->label())
        ->and($resolver->resolve($ticket(TicketStatus::WaitingCustomer))->label())->toBe(TicketBlocker::CustomerResponse->label())
        ->and($resolver->resolve($ticket(TicketStatus::InProgress, ['response_breached' => true]))->label())->toBe(TicketBlocker::SlaBreach->label())
        ->and($resolver->resolve($ticket(TicketStatus::Cancelled))->label())->toBe(TicketBlocker::Cancelled->label());
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
