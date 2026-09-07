<?php

declare(strict_types=1);

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Database\Seeders\AccountingDemoSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\SalesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a coherent set of demo quotations idempotently', function (): void {
    (new InventoryDemoSeeder)->run();
    (new AccountingDemoSeeder)->run();
    (new SalesDemoSeeder)->run();
    (new SalesDemoSeeder)->run();

    expect(Quotation::query()->count())->toBe(3);

    $draft = Quotation::query()->where('notes', 'Demo workflow: draft resin top-up quotation for Smile Dental Clinic.')->sole();
    $sent = Quotation::query()->where('notes', 'Demo workflow: resin top-up quotation sent to Smile Dental Clinic, awaiting a decision.')->sole();
    $accepted = Quotation::query()->where('notes', 'Demo workflow: resin quotation accepted by Smile Dental Clinic.')->sole();

    expect($draft->status)->toBe(QuotationStatus::Draft)
        ->and($draft->lines()->count())->toBe(1)
        ->and($sent->status)->toBe(QuotationStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull()
        ->and($accepted->status)->toBe(QuotationStatus::Accepted)
        ->and($accepted->decided_at)->not->toBeNull()
        ->and($accepted->decision_note)->toBe('Approved by Smile Dental Clinic procurement.');
});
