<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $chief = User::factory()->admin()->create();
    $chief->assignRole(DashboardRole::ChiefAccountant->value);

    $this->actingAs($chief);
});

function journalEntryWithSource(string $sourceType, int $sourceId): JournalEntry
{
    $entry = JournalEntry::factory()->create();
    $entry->forceFill(['source_type' => $sourceType, 'source_id' => $sourceId])->save();

    return $entry;
}

it('shows a source document by its label and number and links to it, never by its class name', function (): void {
    $bill = Bill::factory()->create();
    $invoice = Invoice::factory()->create();

    $this->get(JournalEntryResource::getUrl('view', ['record' => journalEntryWithSource(Bill::class, $bill->getKey())]))
        ->assertOk()
        ->assertSee(BillResource::getTitleCaseModelLabel().' '.$bill->bill_number)
        ->assertSee(BillResource::getUrl('view', ['record' => $bill]), escape: false)
        ->assertDontSee(Bill::class, escape: false);

    $this->get(JournalEntryResource::getUrl('view', ['record' => journalEntryWithSource(Invoice::class, $invoice->getKey())]))
        ->assertOk()
        ->assertSee(InvoiceResource::getTitleCaseModelLabel().' '.$invoice->invoice_number)
        ->assertDontSee(Invoice::class, escape: false);
});

it('names a source document that no longer exists by its type and id', function (): void {
    $this->get(JournalEntryResource::getUrl('view', ['record' => journalEntryWithSource(Bill::class, 987654)]))
        ->assertOk()
        ->assertSee(__('Bill').' #987654')
        ->assertDontSee(Bill::class, escape: false);
});

it('names a source document without a record page or number by its type and id', function (): void {
    $cost = MaintenanceThirdPartyCost::factory()->create();

    $this->get(JournalEntryResource::getUrl('view', ['record' => journalEntryWithSource(MaintenanceThirdPartyCost::class, $cost->getKey())]))
        ->assertOk()
        ->assertSee(__('Maintenance Third Party Cost').' #'.$cost->getKey())
        ->assertDontSee(MaintenanceThirdPartyCost::class, escape: false);
});
