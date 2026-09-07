<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Actions;

use App\Filament\Concerns\InteractsWithSalesServices;
use App\Jobs\GenerateCreditNoteDocument;
use App\Models\CreditNote;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CreditNoteActions
{
    use InteractsWithSalesServices;

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label(__('admin.sales.actions.confirm'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.actions.confirm_confirm'))
            ->visible(fn (CreditNote $record): bool => self::can('confirm', $record))
            ->authorize(fn (CreditNote $record): bool => self::can('confirm', $record))
            ->action(function (CreditNote $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): CreditNote => app(CreditNoteService::class)->confirm($actor, $record),
                );

                Notification::make()->success()->title(__('admin.sales.notifications.credit_note_confirmed', ['number' => $record->credit_note_number]))->send();
            });
    }

    public static function reverse(): Action
    {
        return Action::make('reverse')
            ->label(__('admin.sales.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.actions.reverse_confirm'))
            ->visible(fn (CreditNote $record): bool => self::can('reverse', $record))
            ->authorize(fn (CreditNote $record): bool => self::can('reverse', $record))
            ->action(function (CreditNote $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): CreditNote => app(CreditNoteService::class)->reverse($actor, $record),
                );

                Notification::make()->success()->title(__('admin.sales.notifications.credit_note_reversed', ['number' => $record->credit_note_number]))->send();
            });
    }

    public static function generatePdf(): Action
    {
        return Action::make('generate_pdf')
            ->label(fn (CreditNote $record): string => $record->getFirstMedia('credit-note-pdf') instanceof Media
                ? __('admin.sales.actions.regenerate_pdf')
                : 'Generate PDF')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn (CreditNote $record): bool => $record->isConfirmed() && self::can('view', $record))
            ->authorize(fn (CreditNote $record): bool => self::can('view', $record))
            ->action(function (CreditNote $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                GenerateCreditNoteDocument::dispatch(self::integerKey($record), self::integerKey($actor));

                Notification::make()->success()->title('Credit note PDF generation queued.')->send();
            });
    }

    private static function can(string $ability, CreditNote $creditNote): bool
    {
        return self::salesActor()?->can($ability, $creditNote) ?? false;
    }

    private static function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException('Sales records must use integer identifiers.');
        }

        return $key;
    }
}
