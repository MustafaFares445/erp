<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Actions;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The two ledger-affecting actions, defined once and mounted on the table, the
 * view page, and the edit page.
 *
 * Both are visible only when the acting user holds the matching ability, which is
 * how SC-005 is observable from the UI: an Accountant sees Post and not Reverse.
 * Neither does any work itself — each is a thin adapter over
 * {@see JournalPostingService}, which owns the validation and the transaction.
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md
 */
final class JournalEntryActions
{
    use InteractsWithAccountingServices;

    public static function post(): Action
    {
        return Action::make('post')
            ->label(__('admin.accounting.actions.post'))
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.accounting.actions.post_confirm'))
            ->visible(fn (JournalEntry $record): bool => self::canAct('post', $record))
            ->authorize(fn (JournalEntry $record): bool => self::canAct('post', $record))
            ->action(function (JournalEntry $record): void {
                $actor = self::accountingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): JournalEntry => app(JournalPostingService::class)->post($actor, $record),
                    'admin.accounting.notifications.posted',
                    ['entry' => (string) $record->entry_number],
                );
            });
    }

    public static function reverse(): Action
    {
        return Action::make('reverse')
            ->label(__('admin.accounting.actions.reverse'))
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('warning')
            ->modalDescription(__('admin.accounting.actions.reverse_confirm'))
            ->schema([
                // Defaults to the original's date, which is the correction an
                // accountant usually wants; a later date is offered because the
                // original's period may since have closed (FR-029).
                DatePicker::make('reversal_date')
                    ->label(__('admin.accounting.fields.reversal_date'))
                    ->default(fn (JournalEntry $record): string => $record->entry_date->toDateString())
                    ->required(),
                Textarea::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->visible(fn (JournalEntry $record): bool => self::canAct('reverse', $record))
            ->authorize(fn (JournalEntry $record): bool => self::canAct('reverse', $record))
            ->action(function (JournalEntry $record, array $data): void {
                $actor = self::accountingActor();

                if (! $actor instanceof User) {
                    return;
                }

                $reversal = self::runAccountingOperation(
                    fn (): JournalEntry => app(JournalPostingService::class)->reverse(
                        $actor,
                        $record,
                        CarbonImmutable::parse(self::stringFrom($data['reversal_date'] ?? null)),
                        self::nullableStringFrom($data['description'] ?? null),
                    ),
                );

                // Notified here rather than through the runner's success key,
                // because the reversal's own entry number only exists once the
                // service has returned it.
                Notification::make()
                    ->success()
                    ->title(__('admin.accounting.notifications.reversed', [
                        'entry' => $record->entry_number,
                        'reversal' => $reversal->entry_number,
                    ]))
                    ->send();
            });
    }

    private static function canAct(string $ability, JournalEntry $entry): bool
    {
        return self::accountingActor()?->can($ability, $entry) ?? false;
    }
}
