<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Services\Accounting\ExpenseReceiptSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('ignores empty receipt input and rejects invalid temporary paths', function (): void {
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $service = app(ExpenseReceiptSynchronizer::class);

    $service->sync($expense, null);
    $service->sync($expense, '');

    expect($expense->getMedia('receipt'))->toHaveCount(0);

    expect(fn () => $service->sync($expense, 'wrong/missing.pdf'))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->sync($expense, 'expense-receipts/missing.pdf'))
        ->toThrow(ValidationException::class);
});

it('rejects oversized and unsupported receipt files', function (): void {
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $service = app(ExpenseReceiptSynchronizer::class);

    $oversized = 'expense-receipts/oversized.pdf';
    Storage::disk('local')->put($oversized, str_repeat('x', (10 * 1024 * 1024) + 1));
    expect(fn () => $service->sync($expense, $oversized))->toThrow(ValidationException::class);

    $unsupported = UploadedFile::fake()->create('receipt.txt', 1, 'text/plain')
        ->storeAs('expense-receipts', 'receipt.txt', 'local');
    expect(fn () => $service->sync($expense, $unsupported))->toThrow(ValidationException::class);
});

it('stores a valid receipt once and removes the temporary upload', function (): void {
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $service = app(ExpenseReceiptSynchronizer::class);
    $path = UploadedFile::fake()->image('receipt.jpg')
        ->storeAs('expense-receipts', 'receipt.jpg', 'local');

    $service->sync($expense, $path);
    expect($expense->refresh()->getMedia('receipt'))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('does not re-import the receipt already attached to the expense', function (): void {
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $path = UploadedFile::fake()->image('same.jpg')
        ->storeAs('expense-receipts', 'same.jpg', 'local');
    $service = app(ExpenseReceiptSynchronizer::class);
    $service->sync($expense, $path);

    $relative = $expense->refresh()->getFirstMedia('receipt')?->getPathRelativeToRoot();
    expect($relative)->not->toBeNull();
    $service->sync($expense, $relative);
    expect($expense->getMedia('receipt'))->toHaveCount(1);
});
