<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('passes a read-only financial tie-out when no accounting documents exist', function (): void {
    $this->artisan('demo:finance-audit', ['--anchor' => '2026-10-10', '--months' => '2'])
        ->assertSuccessful();

    expect(DB::table('journal_entries')->count())->toBe(0)
        ->and(DB::table('fiscal_periods')->count())->toBe(0);
});

it('detects an issued invoice with no underlying journal posting', function (): void {
    $invoice = Invoice::factory()->create([
        'invoice_date' => '2026-10-03',
        'issued_at' => '2026-10-03 11:00:00',
        'total_amount' => '105.00',
        'subtotal' => '100.00',
        'tax_total' => '5.00',
        'status' => 'issued',
    ]);

    $this->artisan('demo:finance-audit', ['--anchor' => '2026-10-10', '--months' => '1'])
        ->expectsOutputToContain($invoice->invoice_number)
        ->assertFailed();

    expect($invoice->fresh()->status->value)->toBe('issued')
        ->and(DB::table('journal_entries')->count())->toBe(0);
});

it('rejects malformed dates and excessive month counts', function (): void {
    $this->artisan('demo:finance-audit', ['--anchor' => '2026-02-30', '--months' => '1'])
        ->assertFailed();
    $this->artisan('demo:finance-audit', ['--anchor' => '2026-10-10', '--months' => '25'])
        ->assertFailed();
});

it('refuses finance demo auditing in a production environment', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('demo:finance-audit', ['--anchor' => '2026-10-10', '--months' => '1'])
        ->assertFailed();
});
