<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Data\Accounting\PeriodCloseResult;
use App\Enums\PeriodCloseCheck;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\Exceptions\OverlappingFiscalPeriod;
use App\Services\Accounting\Exceptions\PeriodCloseBlocked;
use App\Services\Accounting\Exceptions\PeriodNotDeletable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 * Closing is gated on {@see PeriodCloseChecklistService} (WP-2.5, GAP-MW-18):
 * every mandatory check must pass, or a System Admin holding the separate
 * `PeriodCloseOverride` permission must supply a written reason, which is
 * itself recorded and audited under a distinct event name.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §4
 * @see /ERP_REMEDIATION_PLAN.md WP-2.5
 */
final readonly class FiscalPeriodService
{
    public function __construct(private PeriodCloseChecklistService $checklist) {}

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

    /**
     * Closes a period, gated on {@see PeriodCloseChecklistService} (WP-2.5).
     *
     * With no failing mandatory check, this is an ordinary audited close. With
     * one or more failing, a blank or missing `$overrideReason` refuses the
     * close outright (`PeriodCloseBlocked`); a non-blank reason instead
     * requires the separate `PeriodCloseOverride` permission and, once
     * authorized, records the override fields and logs a distinct audit event
     * — the exception is loud and attributed, never silent.
     */
    public function close(User $actor, FiscalPeriod $period, ?string $overrideReason = null): FiscalPeriod
    {
        Gate::forUser($actor)->authorize('close', $period);

        return DB::transaction(function () use ($actor, $period, $overrideReason): FiscalPeriod {
            $results = $this->checklist->run($period, $actor);

            $failingMandatory = $results
                ->filter(fn (PeriodCloseResult $result): bool => $result->isMandatoryFailure())
                ->values();

            $reason = $overrideReason !== null ? mb_trim($overrideReason) : '';
            $reason = $reason === '' ? null : $reason;

            if ($failingMandatory->isEmpty()) {
                return $this->finalizeClose($actor, $period);
            }

            if ($reason === null) {
                throw PeriodCloseBlocked::withFailingChecks(
                    $failingMandatory->map(fn (PeriodCloseResult $result): PeriodCloseCheck => $result->check)->all()
                );
            }

            Gate::forUser($actor)->authorize('closeOverride', $period);

            return $this->finalizeClose($actor, $period, overrideReason: $reason, failingMandatory: $failingMandatory);
        });
    }

    /**
     * Reopens a closed period. Unchanged in effect, but now also runs the
     * checklist and persists a fresh snapshot at reopen time, so the
     * before/after of whatever correction motivated the reopen is evidenced
     * (WP-2.5).
     */
    public function reopen(User $actor, FiscalPeriod $period): FiscalPeriod
    {
        Gate::forUser($actor)->authorize('reopen', $period);

        return DB::transaction(function () use ($actor, $period): FiscalPeriod {
            $this->checklist->run($period, $actor);

            $period->update([
                'is_closed' => false,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($period)
                ->causedBy($actor)
                ->withChanges(['old' => ['is_closed' => true], 'attributes' => ['is_closed' => false]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('accounting.fiscal_period.reopened');

            return $period->refresh();
        });
    }

    /**
     * @param  Collection<int, PeriodCloseResult>|null  $failingMandatory
     */
    private function finalizeClose(
        User $actor,
        FiscalPeriod $period,
        ?string $overrideReason = null,
        ?Collection $failingMandatory = null,
    ): FiscalPeriod {
        $isOverride = $overrideReason !== null;

        $period->update([
            'is_closed' => true,
            'closed_by' => $actor->getKey(),
            'closed_at' => now(),
            'close_override_reason' => $overrideReason,
            'close_override_by' => $isOverride ? $actor->getKey() : null,
            'updated_by' => $actor->getKey(),
        ]);

        $log = activity()
            ->performedOn($period)
            ->causedBy($actor)
            ->withChanges(['old' => ['is_closed' => false], 'attributes' => ['is_closed' => true]]);

        if ($isOverride) {
            $log->withProperties([
                'source_channel' => 'dashboard',
                'ip_address' => request()->ip(),
                'override_reason' => $overrideReason,
                'failing_checks' => ($failingMandatory ?? collect())
                    ->map(fn (PeriodCloseResult $result): string => $result->check->value)
                    ->all(),
            ])->log('accounting.period.closed_with_override');
        } else {
            $log->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('accounting.fiscal_period.closed');
        }

        return $period->refresh();
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
