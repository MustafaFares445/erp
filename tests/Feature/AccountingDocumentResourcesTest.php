<?php

declare(strict_types=1);

use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Refund;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use Database\Seeders\AccountingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingDemoSeeder)->run();

    $this->chief = User::query()->where('email', 'chief.accountant@ierp.com')->sole();
});

it('seeds connected accounting documents and their ledger postings', function (): void {
    expect(Invoice::query()->where('status', 'issued')->count())->toBe(1)
        ->and(Bill::query()->where('status', 'approved')->count())->toBe(1)
        ->and(Expense::query()->where('status', 'approved')->count())->toBe(1)
        ->and(Refund::query()->where('status', 'approved')->count())->toBe(1)
        ->and(TaxRecognitionEntry::query()->count())->toBe(2)
        // Only three of the four documents post on this path: issuing an
        // invoice and approving a bill or expense recognise the ledger event
        // immediately, but a refund's approval is purely a maker-checker
        // authorization gate (RefundStatus::Draft -> Approved -> Paid) — its
        // journal entry is posted by RefundService::pay(), the only caller
        // that constructs it (see NoAutomaticPostingTest's nine named
        // JournalPostingService callers), which this demo data deliberately
        // never calls so the Refund resource's Approve action has a subject.
        ->and(JournalEntry::query()->whereIn('source_type', [Invoice::class, Bill::class, Expense::class, Refund::class])->count())->toBe(3);
});

it('renders all six newly implemented accounting pages for the chief accountant', function (): void {
    $resources = [
        AccountsReceivableResource::class,
        AccountsPayableResource::class,
        BillResource::class,
        ExpenseResource::class,
        RefundResource::class,
        TaxResource::class,
    ];

    foreach ($resources as $resource) {
        $this->actingAs($this->chief)
            ->get($resource::getUrl())
            ->assertOk();
    }
});
