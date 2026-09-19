<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    SalesSetting::factory()->create();
});

it('creates a quotation for a closed unbilled maintenance record', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    $user = User::factory()->create();

    $quotation = app(MaintenanceBillingService::class)->createQuotation($record, $user);

    expect($quotation->customer_id)->toBe($record->customer_id)
        ->and($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Quoted)
        ->and($record->quotation_id)->toBe($quotation->getKey())
        ->and($record->billed_at)->not->toBeNull();
});

it('rejects invoicing a closed record with no priceable parts or labour', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice(
        $record,
        User::factory()->create(),
    ))->toThrow(
        ValidationException::class,
        'This maintenance request has no priceable parts or labour to bill.',
    );
});

it('skips a maintenance part whose product variant no longer resolves', function (): void {
    $customer = CustomerProfile::factory()->create();
    $customer->setRelation('user', null);

    $part = new ServiceRecordPart;
    $part->forceFill([
        'quantity' => '1.000000',
        'reversed_at' => null,
    ]);
    $part->setRelation('productVariant', null);

    $task = new MaintenanceTask;
    $task->setRelation('parts', new EloquentCollection([$part]));

    $record = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    $record->setRelation('serviceRecords', new EloquentCollection([$task]));
    $record->setRelation('customer', $customer);

    $lines = new ReflectionMethod(MaintenanceBillingService::class, 'partsLines')
        ->invoke(app(MaintenanceBillingService::class), $record);

    expect($lines)->toBe([]);
});
