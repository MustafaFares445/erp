<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->chief = User::factory()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);
    $this->actingAs($this->chief);

    FiscalPeriod::factory()->create();
});

it('resolves the blameable authors of every accounting record', function (): void {
    $account = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100']);
    $sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);
    $period = FiscalPeriod::query()->sole();

    $entry = app(JournalPostingService::class)->postNew($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => $account->id, 'debit' => '10.00', 'credit' => '0.00'],
        ['chart_account_id' => $sales->id, 'debit' => '0.00', 'credit' => '10.00'],
    ]);

    // Every accounting table carries created_by/updated_by, and the dashboard reads
    // them back through these relations — FiscalPeriods already renders
    // `updatedBy.name` as its "Last updated by" column.
    expect($account->createdBy?->id)->toBe($this->chief->id)
        ->and($account->updatedBy?->id)->toBe($this->chief->id)
        ->and($period->refresh()->createdBy?->id)->toBe($this->chief->id)
        ->and($period->updatedBy?->id)->toBe($this->chief->id)
        ->and($entry->createdBy?->id)->toBe($this->chief->id)
        ->and($entry->updatedBy?->id)->toBe($this->chief->id);
});

it('navigates every accounting relation in both directions', function (): void {
    $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $leaf = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'parent_id' => $header->id]);
    $sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);

    $assetType = AccountType::query()->where('name', AccountElement::Asset->value)->sole();
    $period = FiscalPeriod::query()->sole();

    $entry = app(JournalPostingService::class)->postNew($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => $leaf->id, 'debit' => '10.00', 'credit' => '0.00'],
        ['chart_account_id' => $sales->id, 'debit' => '0.00', 'credit' => '10.00'],
    ]);

    $line = $entry->lines->firstWhere('chart_account_id', $leaf->id);

    expect($assetType->accounts->pluck('id')->all())->toContain($leaf->id, $header->id)
        ->and($leaf->accountType?->id)->toBe($assetType->id)
        ->and($leaf->parent?->id)->toBe($header->id)
        ->and($header->children->pluck('id')->all())->toBe([$leaf->id])
        ->and($leaf->journalEntryLines->pluck('id')->all())->toBe([$line->id])
        ->and($line->chartAccount?->id)->toBe($leaf->id)
        ->and($line->journalEntry?->id)->toBe($entry->id)
        ->and($entry->fiscalPeriod?->id)->toBe($period->id)
        ->and($period->refresh()->journalEntries->pluck('id')->all())->toBe([$entry->id]);
});

it('treats a non-numeric amount as zero minor units', function (): void {
    // What an unfilled side of a draft line looks like coming back from the form
    // layer. `decimal(15,2)` cannot hold anything else, so this is the boundary
    // narrowing rather than a real currency case.
    expect(JournalEntryLine::toMinorUnits(null))->toBe(0)
        ->and(JournalEntryLine::toMinorUnits(''))->toBe(0)
        ->and(JournalEntryLine::toMinorUnits('12.34'))->toBe(1234);
});

it('allows a line with no parent yet to pass the posted-parent guard', function (): void {
    // A line built but not yet associated has no parent to check, so the guard has
    // to let it through rather than treat id 0 as an entry.
    $line = new JournalEntryLine(['debit' => '5.00', 'credit' => '0.00']);

    expect($line->journal_entry_id ?? null)->toBeNull();

    $guard = new ReflectionMethod(JournalEntryLine::class, 'guardAgainstPostedParent');

    expect($guard->invoke(null, null))->toBeNull();
});

it('names a posted entry by id when its stored number is somehow absent', function (): void {
    $entry = JournalEntry::factory()->postedAndBalanced()->create();

    // The NOT NULL column prevents this, but a refusal must still identify the row
    // rather than name an empty string.
    $entry->setRawAttributes(['id' => $entry->id, 'status' => 'posted'], sync: true);

    expect(fn (): never => $entry->guardAgainstPostedWrite())
        ->toThrow(PostedEntryIsImmutable::class, '#'.$entry->id);
});
