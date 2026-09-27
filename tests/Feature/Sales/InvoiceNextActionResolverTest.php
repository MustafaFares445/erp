<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\DepositApplicationIssue;
use App\Models\Invoice;
use App\Services\Sales\InvoiceNextActionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves every invoice next-action branch', function (): void {
    $resolver = app(InvoiceNextActionResolver::class);

    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    expect($resolver->resolve($draft))->toBe('Issue invoice');

    $cancelled = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled, 'issued_at' => now()]);
    expect($resolver->resolve($cancelled))->toBe('No action required');

    $writtenOff = Invoice::factory()->create(['status' => InvoiceStatus::WrittenOff, 'issued_at' => now()]);
    expect($resolver->resolve($writtenOff))->toBe('No collection required');

    $withOpenIssue = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'amount_paid' => 0,
        'credited_amount' => 0,
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $withOpenIssue->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);
    expect($resolver->resolve($withOpenIssue))->toBe('Retry deposit application');

    $settled = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'amount_paid' => 100,
        'credited_amount' => 0,
    ]);
    expect($resolver->resolve($settled))->toBe('No action required');

    $overdue = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'amount_paid' => 0,
        'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    expect($resolver->resolve($overdue))->toStartWith('Follow up: collect');

    $unpaid = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'amount_paid' => 0,
        'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);
    expect($resolver->resolve($unpaid))->toStartWith('Collect');
});
