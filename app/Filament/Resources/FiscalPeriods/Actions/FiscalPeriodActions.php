<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Actions;

use App\Data\Accounting\PeriodCloseResult;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\JournalEntries\Actions\JournalEntryActions;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\PeriodCloseChecklistService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The fiscal-period lifecycle actions, defined once and mounted on the table
 * and the view page — mirroring {@see JournalEntryActions}.
 *
 * `close()` runs the checklist before the confirmation modal even opens
 * (WP-2.5, GAP-MW-18): the modal renders whatever it finds, and only offers
 * an override reason field once a mandatory check is failing and the actor
 * holds the separate override permission. The actual enforcement always
 * happens again, fresh, inside {@see FiscalPeriodService::close()} — the
 * modal's contents are a courtesy, never the authority.
 */
final class FiscalPeriodActions
{
    use InteractsWithAccountingServices;

    public static function runChecklist(): Action
    {
        return Action::make('run_checklist')
            ->label(__('admin.accounting.actions.run_checklist'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->visible(fn (FiscalPeriod $record): bool => self::accountingActor()?->can('view', $record) ?? false)
            ->action(function (FiscalPeriod $record): void {
                $actor = self::accountingActor();

                if (! $actor instanceof User) {
                    return;
                }

                /** @var Collection<int, PeriodCloseResult> $results */
                $results = self::runAccountingOperation(
                    fn (): Collection => app(PeriodCloseChecklistService::class)->run($record, $actor),
                );

                $failingMandatory = $results->filter(
                    fn (PeriodCloseResult $result): bool => $result->isMandatoryFailure()
                );

                Notification::make()
                    ->title($failingMandatory->isEmpty()
                        ? __('admin.accounting.notifications.checklist_passed')
                        : __('admin.accounting.notifications.checklist_failed'))
                    ->body($failingMandatory->isEmpty()
                        ? null
                        : implode(', ', $failingMandatory->map(
                            fn (PeriodCloseResult $result): string => $result->check->label()
                        )->all()))
                    ->status($failingMandatory->isEmpty() ? 'success' : 'warning')
                    ->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('admin.accounting.actions.close'))
            ->icon(Heroicon::LockClosed)
            ->color('danger')
            ->modalDescription(__('admin.accounting.actions.close_confirm'))
            ->visible(fn (FiscalPeriod $record): bool => ! $record->is_closed
                && (self::accountingActor()?->can('close', $record) ?? false))
            ->disabled(fn (FiscalPeriod $record): bool => self::closeDisabled($record))
            ->schema(fn (FiscalPeriod $record): array => [
                Placeholder::make('close_checklist_summary')
                    ->label(__('admin.accounting.fields.close_checklist'))
                    ->content(self::checklistSummary($record)),
                Textarea::make('override_reason')
                    ->label(__('admin.accounting.fields.override_reason'))
                    ->rows(3)
                    ->required()
                    ->visible(fn (): bool => app(PeriodCloseChecklistService::class)->hasUnresolvedMandatoryFailure($record)
                        && (self::accountingActor()?->can('closeOverride', $record) ?? false)),
            ])
            ->action(function (FiscalPeriod $record, array $data): void {
                $actor = self::accountingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): FiscalPeriod => app(FiscalPeriodService::class)->close(
                        $actor,
                        $record,
                        self::nullableStringFrom($data['override_reason'] ?? null),
                    ),
                    'admin.accounting.notifications.closed',
                    ['period' => (string) $record->name],
                );
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label(__('admin.accounting.actions.reopen'))
            ->icon(Heroicon::LockOpen)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (FiscalPeriod $record): bool => $record->is_closed
                && (self::accountingActor()?->can('reopen', $record) ?? false))
            ->action(function (FiscalPeriod $record): void {
                $actor = self::accountingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): FiscalPeriod => app(FiscalPeriodService::class)->reopen($actor, $record),
                    'admin.accounting.notifications.reopened',
                    ['period' => (string) $record->name],
                );
            });
    }

    /**
     * A human-readable rendering of the most recently persisted checklist
     * snapshot, shown read-only inside the close modal — the same source
     * {@see self::closeDisabled()} reads, kept in sync by {@see self::runChecklist()}.
     */
    private static function checklistSummary(FiscalPeriod $record): string
    {
        $rows = app(PeriodCloseChecklistService::class)->statusRows($record);

        $lines = array_map(static function (array $row): string {
            $status = match ($row['passed']) {
                true => 'PASS',
                false => 'FAIL',
                null => 'not yet run',
            };

            $label = $row['check']->label();

            if (! $row['mandatory']) {
                $label .= ' (advisory)';
            }

            return sprintf('%s: %s', $label, $status);
        }, $rows);

        return implode("\n", $lines);
    }

    /**
     * Read-only signal for the trigger button: is the *last known* checklist
     * snapshot showing a mandatory failure the actor cannot override?
     *
     * Deliberately reads the persisted snapshot rather than running the
     * checklist fresh on every render — {@see self::runChecklist()} is the
     * explicit, idempotent way to refresh it, and {@see FiscalPeriodService::close()}
     * re-runs it fresh and authoritatively at the moment of closing regardless
     * of what this button shows.
     */
    private static function closeDisabled(FiscalPeriod $record): bool
    {
        $actor = self::accountingActor();

        if (! $actor instanceof User) {
            return true;
        }

        $hasFailure = app(PeriodCloseChecklistService::class)->hasUnresolvedMandatoryFailure($record);

        if (! $hasFailure) {
            return false;
        }

        return ! $actor->can('closeOverride', $record);
    }
}
