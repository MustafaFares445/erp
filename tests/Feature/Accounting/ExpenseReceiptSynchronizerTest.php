<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Services\Accounting\ExpenseReceiptSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('moves an uploaded receipt into the expense media collection', function (): void {
    $expense = Expense::factory()->create();
    $path = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')->store('expense-receipts', 'local');

    app(ExpenseReceiptSynchronizer::class)->sync($expense, $path);

    expect($expense->fresh()->getFirstMedia('receipt'))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('rejects a receipt outside the expected upload directory', function (): void {
    $expense = Expense::factory()->create();
    $path = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')->store('elsewhere', 'local');

    app(ExpenseReceiptSynchronizer::class)->sync($expense, $path);
})->throws(ValidationException::class);
