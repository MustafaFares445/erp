<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * FR-025 at the model layer. The posting service refuses these too, but this
 * file deliberately bypasses it: the model guard exists to stop code that never
 * went through the service from rewriting posted history (research.md R-002),
 * so every test here writes directly through Eloquent.
 */

it('refuses to update a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create()->fresh();

    expect(fn (): bool => $entry->update(['description' => 'rewritten']))
        ->toThrow(PostedEntryIsImmutable::class);

    expect($entry->fresh()->description)->not->toBe('rewritten');
});

it('refuses to change a posted entry back to draft', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create()->fresh();

    expect(fn (): bool => $entry->update(['status' => JournalEntryStatus::Draft]))
        ->toThrow(PostedEntryIsImmutable::class);

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Posted);
});

it('refuses to delete a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create()->fresh();

    expect(fn (): ?bool => $entry->delete())
        ->toThrow(PostedEntryIsImmutable::class);

    expect(JournalEntry::query()->whereKey($entry->getKey())->exists())->toBeTrue();
});

it('refuses to touch a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create()->fresh();

    // Time must actually advance: a touch() within the same second leaves
    // `updated_at` unchanged, so Eloquent finds nothing dirty and skips the
    // update entirely — writing nothing and firing no `updating` event.
    $this->travel(1)->minute();

    expect(fn (): bool => $entry->touch())->toThrow(PostedEntryIsImmutable::class);
});

it('permits the draft to posted transition itself', function (): void {
    $entry = JournalEntry::factory()->balanced()->create();

    $entry->update(['status' => JournalEntryStatus::Posted]);

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Posted);
});

it('refuses to append a line to a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();
    $lineCount = $entry->lines()->count();

    expect(fn (): JournalEntryLine => JournalEntryLine::factory()->for($entry)->create())
        ->toThrow(PostedEntryIsImmutable::class);

    expect($entry->lines()->count())->toBe($lineCount);
});

it('refuses to update a line of a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();
    $line = $entry->lines()->firstOrFail();

    expect(fn (): bool => $line->update(['debit' => '999.00']))
        ->toThrow(PostedEntryIsImmutable::class);

    expect($line->fresh()->debit)->not->toBe('999.00');
});

it('refuses to delete a line of a posted entry', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();
    $line = $entry->lines()->firstOrFail();

    expect(fn (): ?bool => $line->delete())
        ->toThrow(PostedEntryIsImmutable::class);

    expect($entry->lines()->count())->toBe(2);
});

it('refuses to move a line off a posted entry onto a draft', function (): void {
    $posted = JournalEntry::factory()->postedAndBalanced()->create();
    $draft = JournalEntry::factory()->create();
    $line = $posted->lines()->firstOrFail();

    expect(fn (): bool => $line->update(['journal_entry_id' => $draft->getKey()]))
        ->toThrow(PostedEntryIsImmutable::class);
});

it('refuses to move a line from a draft onto a posted entry', function (): void {
    $posted = JournalEntry::factory()->postedAndBalanced()->create();
    $draft = JournalEntry::factory()->balanced()->create();
    $line = $draft->lines()->firstOrFail();

    expect(fn (): bool => $line->update(['journal_entry_id' => $posted->getKey()]))
        ->toThrow(PostedEntryIsImmutable::class);
});

it('permits every write on a draft entry and its lines', function (): void {
    $entry = JournalEntry::factory()->balanced()->create();

    $entry->update(['description' => 'freely editable']);

    expect($entry->fresh()->description)->toBe('freely editable');

    $line = $entry->lines()->firstOrFail();
    $line->update(['debit' => '250.00']);

    expect($line->fresh()->debit)->toBe('250.00');

    JournalEntryLine::factory()->for($entry)->create([
        'chart_account_id' => ChartAccount::factory()->create()->getKey(),
        'sort_order' => 3,
    ]);
    expect($entry->lines()->count())->toBe(3);

    $line->delete();
    expect($entry->lines()->count())->toBe(2);

    $entry->delete();
    expect(JournalEntry::query()->whereKey($entry->getKey())->exists())->toBeFalse();
});

it('cascades line deletion when a draft entry is deleted', function (): void {
    $entry = JournalEntry::factory()->balanced()->create();

    $entry->delete();

    expect(JournalEntryLine::query()->where('journal_entry_id', $entry->getKey())->count())->toBe(0);
});
