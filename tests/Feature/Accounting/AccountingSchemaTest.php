<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the five accounting tables with their columns', function (): void {
    expect(Schema::hasColumns('account_types', ['id', 'name', 'normal_balance', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('chart_accounts', [
            'id', 'account_type_id', 'parent_id', 'code', 'name', 'is_postable', 'is_active',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('fiscal_periods', [
            'id', 'name', 'starts_at', 'ends_at', 'is_closed', 'created_by', 'updated_by',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('journal_entries', [
            'id', 'fiscal_period_id', 'entry_number', 'entry_date', 'description',
            'source_type', 'source_id', 'status', 'created_by', 'updated_by',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('journal_entry_lines', [
            'id', 'journal_entry_id', 'chart_account_id', 'debit', 'credit', 'description',
            'sort_order', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('omits the columns the ERD deviations deliberately drop', function (): void {
    // E-1: fiscal_periods carries no generic `status` — `is_closed` is its single
    // lifecycle source of truth, and two columns could disagree.
    expect(Schema::hasColumn('fiscal_periods', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('fiscal_periods', 'deleted_at'))->toBeFalse()
        // FR-025: a posted entry is undeletable and a draft is hard-deleted, so
        // neither entries nor their lines are soft-deletable at all.
        ->and(Schema::hasColumn('journal_entries', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('journal_entry_lines', 'deleted_at'))->toBeFalse()
        // research.md R-003: a reversal is linked through the `source` morph, so
        // there is no dedicated reversal column.
        ->and(Schema::hasColumn('journal_entries', 'reversed_by'))->toBeFalse()
        ->and(Schema::hasColumn('journal_entries', 'reversal_id'))->toBeFalse()
        // research.md R-008: a balance is computed from posted lines, never stored.
        ->and(Schema::hasColumn('chart_accounts', 'balance'))->toBeFalse();
});

it('adds sort_order to journal entry lines as ERD deviation E-2', function (): void {
    expect(Schema::hasColumn('journal_entry_lines', 'sort_order'))->toBeTrue();

    $line = JournalEntryLine::factory()->for(JournalEntry::factory())->create();

    expect($line->sort_order)->toBeInt();
});

it('enforces the unique natural keys', function (): void {
    ChartAccount::factory()->create(['code' => '1100']);
    FiscalPeriod::factory()->create(['name' => 'Unique Period']);
    JournalEntry::factory()->create(['entry_number' => 'JE-000001']);

    expect(fn () => ChartAccount::factory()->create(['code' => '1100']))->toThrow(QueryException::class)
        ->and(fn () => FiscalPeriod::factory()->create(['name' => 'Unique Period']))->toThrow(QueryException::class)
        ->and(fn () => JournalEntry::factory()->create(['entry_number' => 'JE-000001']))->toThrow(QueryException::class);
});

it('restricts deleting an account type or a chart account a row still points at', function (): void {
    $account = ChartAccount::factory()->create();

    expect(fn () => DB::table('account_types')->where('id', $account->account_type_id)->delete())
        ->toThrow(QueryException::class);

    JournalEntryLine::factory()->for(JournalEntry::factory())->create([
        'chart_account_id' => $account->getKey(),
    ]);

    // Restrict, not cascade: a posted line's account must outlive any attempt to
    // remove it, which is why the service marks an account inactive instead
    // (FR-010, FR-011).
    expect(fn () => DB::table('chart_accounts')->where('id', $account->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('restricts deleting a fiscal period a journal entry points at', function (): void {
    $period = FiscalPeriod::factory()->create();
    JournalEntry::factory()->posted($period)->create();

    expect(fn () => DB::table('fiscal_periods')->where('id', $period->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('cascades a deleted draft entry to its lines', function (): void {
    $entry = JournalEntry::factory()->balanced()->create();

    expect($entry->lines()->count())->toBe(2);

    // Safe precisely because a posted entry can never be deleted, so the cascade
    // only ever fires for a draft.
    $entry->delete();

    expect(JournalEntryLine::query()->where('journal_entry_id', $entry->getKey())->count())->toBe(0);
});

it('allows a self-referencing parent and a null fiscal period on a draft', function (): void {
    $parent = ChartAccount::factory()->header()->create();
    $child = ChartAccount::factory()->create(['parent_id' => $parent->getKey()]);
    $draft = JournalEntry::factory()->create();

    expect($child->parent_id)->toBe($parent->getKey())
        ->and($draft->fiscal_period_id)->toBeNull();
});

it('stores journal amounts as two-decimal fixed-point values', function (): void {
    $line = JournalEntryLine::factory()->for(JournalEntry::factory())->create([
        'debit' => '12345678.33',
        'credit' => '0.00',
    ]);

    // decimal(15,2), cast to `decimal:2` — the third decimal place is dropped
    // rather than kept as float noise, which is what lets `toMinorUnits()` treat a
    // stored amount as exact (FR-030).
    expect($line->refresh()->debit)->toBe('12345678.33');

    $rounded = JournalEntryLine::factory()->for(JournalEntry::factory())->create([
        'debit' => '0.005',
        'credit' => '0.00',
    ]);

    expect($rounded->refresh()->debit)->toBe('0.01');
});

it('indexes the columns the posting path looks up', function (): void {
    expect(indexedColumnsOf('fiscal_periods'))->toContain('starts_at')
        ->and(indexedColumnsOf('fiscal_periods'))->toContain('ends_at')
        ->and(indexedColumnsOf('journal_entries'))->toContain('entry_date')
        ->and(indexedColumnsOf('journal_entries'))->toContain('status')
        ->and(indexedColumnsOf('journal_entry_lines'))->toContain('sort_order')
        ->and(indexedColumnsOf('chart_accounts'))->toContain('is_active');
});

/**
 * Every column named by any index on the table, flattened.
 *
 * @return list<string>
 */
function indexedColumnsOf(string $table): array
{
    $columns = [];

    foreach (Schema::getIndexes($table) as $index) {
        foreach ($index['columns'] as $column) {
            $columns[] = $column;
        }
    }

    return array_values(array_unique($columns));
}
