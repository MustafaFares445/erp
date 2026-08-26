<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a unique, sequential QT- number to each quotation', function (): void {
    $service = app(QuotationService::class);
    $customer = CustomerProfile::factory()->create();

    $first = $service->create(['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()], []);
    $second = $service->create(['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()], []);

    expect($first->quotation_number)->toStartWith('QT-')
        ->and($second->quotation_number)->not->toBe($first->quotation_number);
});

it('never reissues a number after its draft is deleted (withTrashed guard)', function (): void {
    $service = app(QuotationService::class);
    $customer = CustomerProfile::factory()->create();

    $first = $service->create(['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()], []);
    $firstNumber = $first->quotation_number;
    $first->delete();

    $second = $service->create(['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()], []);

    expect($second->quotation_number)->not->toBe($firstNumber)
        ->and(Quotation::withTrashed()->where('quotation_number', $firstNumber)->exists())->toBeTrue();
});
