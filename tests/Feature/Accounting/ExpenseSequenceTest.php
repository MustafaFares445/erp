<?php

declare(strict_types=1);

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not reissue numeric expense references when legacy demo identifiers sort later', function (): void {
    Expense::factory()->create(['expense_number' => 'EXP-DEMO-2026-001']);
    Expense::factory()->create(['expense_number' => 'EXP-0000002']);

    $generated = Expense::factory()->create(['expense_number' => null]);

    expect($generated->expense_number)->toBe('EXP-0000003');
});

it('includes archived numeric expense references when generating the next one', function (): void {
    $archived = Expense::factory()->create(['expense_number' => 'EXP-0000042']);
    $archived->delete();

    $generated = Expense::factory()->create(['expense_number' => null]);

    expect($generated->expense_number)->toBe('EXP-0000043');
});
