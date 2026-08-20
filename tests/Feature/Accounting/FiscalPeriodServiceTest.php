<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\Exceptions\OverlappingFiscalPeriod;
use App\Services\Accounting\Exceptions\PeriodNotDeletable;
use App\Services\Accounting\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->service = app(FiscalPeriodService::class);

    $this->chief = User::factory()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    $this->accountant = User::factory()->create();
    $this->accountant->assignRole(DashboardRole::Accountant->value);

    $this->actingAs($this->chief);

    $this->january = CarbonImmutable::create(2026, 1, 1);
});

it('creates an open period', function (): void {
    $period = $this->service->create(
        $this->chief,
        'January 2026',
        $this->january,
        $this->january->endOfMonth(),
    );

    expect($period->is_closed)->toBeFalse()
        ->and($period->starts_at->toDateString())->toBe('2026-01-01')
        ->and($period->ends_at->toDateString())->toBe('2026-01-31')
        ->and($period->created_by)->toBe($this->chief->getKey());
});

it('refuses a period overlapping an existing one', function (): void {
    $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    expect(fn (): FiscalPeriod => $this->service->create(
        $this->chief,
        'Mid January 2026',
        $this->january->addDays(10),
        $this->january->addDays(40),
    ))->toThrow(OverlappingFiscalPeriod::class, 'January 2026');

    expect(FiscalPeriod::query()->count())->toBe(1);
});

it('refuses a period that fully contains an existing one', function (): void {
    $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    expect(fn (): FiscalPeriod => $this->service->create(
        $this->chief,
        'Q1 2026',
        $this->january,
        $this->january->addMonths(3),
    ))->toThrow(OverlappingFiscalPeriod::class);
});

it('refuses a period that shares only its boundary day', function (): void {
    $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    expect(fn (): FiscalPeriod => $this->service->create(
        $this->chief,
        'Overlapping by one day',
        $this->january->endOfMonth(),
        $this->january->addMonth()->endOfMonth(),
    ))->toThrow(OverlappingFiscalPeriod::class);
});

it('allows consecutive periods that do not share a day', function (): void {
    $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    $february = $this->january->addMonth();
    $second = $this->service->create($this->chief, 'February 2026', $february, $february->endOfMonth());

    expect($second->exists)->toBeTrue()
        ->and(FiscalPeriod::query()->count())->toBe(2);
});

it('allows a period to be edited without tripping its own overlap check', function (): void {
    $period = $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    $updated = $this->service->update(
        $this->chief,
        $period,
        'January 2026 (revised)',
        $this->january,
        $this->january->addDays(20),
    );

    expect($updated->name)->toBe('January 2026 (revised)')
        ->and($updated->ends_at->toDateString())->toBe('2026-01-21');
});

it('refuses an edit that would overlap a different period', function (): void {
    $january = $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());
    $february = $this->january->addMonth();
    $this->service->create($this->chief, 'February 2026', $february, $february->endOfMonth());

    expect(fn (): FiscalPeriod => $this->service->update(
        $this->chief,
        $january,
        'January 2026',
        $this->january,
        $february->addDays(5),
    ))->toThrow(OverlappingFiscalPeriod::class, 'February 2026');
});

it('closes a period and audits who did it', function (): void {
    $period = $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

    $closed = $this->service->close($this->chief, $period);

    expect($closed->is_closed)->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.fiscal_period.closed',
        'subject_type' => FiscalPeriod::class,
        'subject_id' => $period->getKey(),
        'causer_id' => $this->chief->getKey(),
    ]);
});

it('reopens a closed period and audits it', function (): void {
    $period = FiscalPeriod::factory()->closed()->create();

    $reopened = $this->service->reopen($this->chief, $period);

    expect($reopened->is_closed)->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.fiscal_period.reopened',
        'subject_id' => $period->getKey(),
        'causer_id' => $this->chief->getKey(),
    ]);
});

it('refuses an accountant who lacks the close permission', function (): void {
    $period = FiscalPeriod::factory()->create();

    expect(fn (): FiscalPeriod => $this->service->close($this->accountant, $period))
        ->toThrow(AuthorizationException::class);

    expect($period->fresh()->is_closed)->toBeFalse();
});

it('refuses an accountant who lacks the manage permission', function (): void {
    expect(fn (): FiscalPeriod => $this->service->create(
        $this->accountant,
        'January 2026',
        $this->january,
        $this->january->endOfMonth(),
    ))->toThrow(AuthorizationException::class);
});

it('refuses to delete a period that has journal entries', function (): void {
    $period = FiscalPeriod::factory()->create();
    JournalEntry::factory()->postedAndBalanced('50.00', $period)->create();

    expect(fn () => $this->service->delete($this->chief, $period))
        ->toThrow(PeriodNotDeletable::class, (string) $period->name);

    expect(FiscalPeriod::query()->whereKey($period->getKey())->exists())->toBeTrue();
});

it('deletes an empty period', function (): void {
    $period = FiscalPeriod::factory()->create();

    $this->service->delete($this->chief, $period);

    expect(FiscalPeriod::query()->whereKey($period->getKey())->exists())->toBeFalse();
});

describe('forDate', function (): void {
    it('resolves the period containing a date', function (): void {
        $period = $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

        expect($this->service->forDate($this->january->addDays(14))?->getKey())->toBe($period->getKey());
    });

    it('includes both boundary days', function (): void {
        $period = $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

        expect($this->service->forDate($this->january)?->getKey())->toBe($period->getKey())
            ->and($this->service->forDate($this->january->endOfMonth())?->getKey())->toBe($period->getKey());
    });

    it('returns null for a date outside every period', function (): void {
        $this->service->create($this->chief, 'January 2026', $this->january, $this->january->endOfMonth());

        expect($this->service->forDate($this->january->addMonths(2)))->toBeNull();
    });
});
