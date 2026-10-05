<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create(['default_tax_percent' => '0.00']);
});

function coverage68QuotedRecord(QuotationStatus $status, bool $attachQuotation = true): array
{
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'coverage_decision' => WarrantyClaimDecision::Rejected,
        'billing_type' => MaintenanceBillingType::Quoted,
        'quotation_id' => null,
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->id,
        'total_cost_minor' => 5000,
    ]);

    $quotation = null;
    if ($attachQuotation) {
        $quotation = Quotation::factory()->create([
            'customer_id' => $record->customer_id,
            'status' => $status,
            'subtotal' => '100.00',
            'tax_total' => '0.00',
            'grand_total' => '100.00',
            'sent_at' => $status === QuotationStatus::Draft ? null : now()->subDay(),
            'decided_at' => in_array($status, [
                QuotationStatus::Accepted,
                QuotationStatus::Rejected,
                QuotationStatus::Cancelled,
            ], true) ? now() : null,
        ]);

        $record->forceFill(['quotation_id' => $quotation->id])->save();
    }

    return compact('record', 'quotation');
}

it('refuses a requote when the accepted quotation already covers current customer responsibility', function (): void {
    ['record' => $record] = coverage68QuotedRecord(QuotationStatus::Accepted);

    expect(fn () => app(MaintenanceBillingService::class)->createQuotation(
        $record,
        User::factory()->create(),
    ))->toThrow(
        ValidationException::class,
        'accepted quotation already covers the current customer responsibility',
    );
});

it('rejects final invoicing when a quoted request has lost its quotation link', function (): void {
    ['record' => $record] = coverage68QuotedRecord(QuotationStatus::Draft, false);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice(
        $record,
        User::factory()->create(),
    ))->toThrow(ValidationException::class, 'customer-responsibility quotation is missing');
});

it('rejects final invoicing while the latest quotation is still undecided', function (): void {
    ['record' => $record] = coverage68QuotedRecord(QuotationStatus::Draft);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice(
        $record,
        User::factory()->create(),
    ))->toThrow(ValidationException::class, 'latest customer quotation must be decided');
});

it('rejects final invoicing when the decided quotation chain contains no accepted quotation', function (): void {
    ['record' => $record] = coverage68QuotedRecord(QuotationStatus::Rejected);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice(
        $record,
        User::factory()->create(),
    ))->toThrow(ValidationException::class, 'must be accepted before creating the final invoice');
});

it('covers quotable guards for settled billing and zero-customer coverage decisions', function (): void {
    $service = app(MaintenanceBillingService::class);
    $assertQuotable = new ReflectionMethod(MaintenanceBillingService::class, 'assertQuotable');

    $settled = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Invoiced,
    ]);

    expect(fn () => $assertQuotable->invoke($service, $settled))
        ->toThrow(InvalidBillingTransition::class);

    $covered = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
        'coverage_decision' => WarrantyClaimDecision::Goodwill,
    ]);

    expect(fn () => $assertQuotable->invoke($service, $covered))
        ->toThrow(ValidationException::class, 'leaves no customer responsibility to quote');
});
