<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\Exceptions\OverlappingFiscalPeriod;
use App\Services\Accounting\Exceptions\PeriodNotDeletable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Fiscal period lifecycle, and the lookup that resolves an entry's period from
 * its date at posting time (FR-018).
 *
 * Closing a period is the only mechanism that stops a correction being backdated
 * into figures that have already been reported, which is why it carries its own
 * permission (FR-040) and is audited.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §4
 */
final readonly class FiscalPeriodService
{
    /**
     * The period containing a date, or null when none does.
     *
     * Unauthorized on purpose: this is an internal lookup
     * {@see JournalPostingService} performs on the caller's behalf during an
     * already-authorized posting, not a user-facing read.
     */
    public function forDate(CarbonInterface $date): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString())
            ->orderBy('starts_at')
            ->first();
    }

    public function create(
        User $actor,
        string $name,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): FiscalPeriod {
        Gate::forUser($actor)->authorize('create', FiscalPeriod::class);

        return DB::transaction(function () use ($actor, $name, $startsAt, $endsAt): FiscalPeriod {
            $this->guardAgainstOverlap($startsAt, $endsAt, exceptId: null);

            $period = new FiscalPeriod([
                'name' => $name,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => $endsAt->toDateString(),
                'is_closed' => false,
            ]);

            $period->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $period;
        });
    }

    public function update(
        User $actor,
        FiscalPeriod $period,
        string $name,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): FiscalPeriod {
        Gate::forUser($actor)->authorize('update', $period);

        return DB::transaction(function () use ($actor, $period, $name, $startsAt, $endsAt): FiscalPeriod {
            $this->guardAgainstOverlap($startsAt, $endsAt, exceptId: $period->id);

            $period->update([
                'name' => $name,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => $endsAt->toDateString(),
                'updated_by' => $actor->getKey(),
            ]);

            return $period->refresh();
        });
    }

    public function delete(User $actor, FiscalPeriod $period): void
    {
        Gate::forUser($actor)->authorize('delete', $period);

        $entryCount = $period->journalEntries()->count();

        if ($entryCount > 0) {
            throw PeriodNotDeletable::hasEntries((string) $period->name, $entryCount);
        }

        $period->delete();
    }

    public function close(User $actor, FiscalPeriod $period): FiscalPeriod
    {
        Gate::forUser($actor)->authorize('close', $period);

        return $this->setClosed($actor, $period, closed: true, logName: 'accounting.fiscal_period.closed');
    }

    public function reopen(User $actor, FiscalPeriod $period): FiscalPeriod
    {
        Gate::forUser($actor)->authorize('reopen', $period);

        return $this->setClosed($actor, $period, closed: false, logName: 'accounting.fiscal_period.reopened');
    }

    private function setClosed(User $actor, FiscalPeriod $period, bool $closed, string $logName): FiscalPeriod
    {
        return DB::transaction(function () use ($actor, $period, $closed, $logName): FiscalPeriod {
            $period->update([
                'is_closed' => $closed,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($period)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['is_closed' => ! $closed],
                    'attributes' => ['is_closed' => $closed],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($logName);

            return $period->refresh();
        });
    }

    /**
     * Overlap is checked inside the caller's transaction rather than before it,
     * so two concurrent creations cannot both pass (FR-015, data-model.md P-2).
     *
     * Two ranges overlap unless one ends before the other starts, which is why
     * this is a single pair of inclusive comparisons rather than four cases.
     */
    private function guardAgainstOverlap(CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $exceptId): void
    {
        $overlapping = FiscalPeriod::query()
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->whereDate('starts_at', '<=', $endsAt->toDateString())
            ->whereDate('ends_at', '>=', $startsAt->toDateString())
            ->lockForUpdate()
            ->first();

        if ($overlapping instanceof FiscalPeriod) {
            throw OverlappingFiscalPeriod::with((string) $overlapping->name);
        }
    }
}
