<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierDebitNotes\Pages;

use App\Enums\SupplierDebitNoteStatus;
use App\Filament\Resources\SupplierDebitNotes\SupplierDebitNoteResource;
use App\Models\SupplierDebitNote;
use App\Models\User;
use App\Services\Purchasing\SupplierDebitNoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewSupplierDebitNote extends ViewRecord
{
    protected static string $resource = SupplierDebitNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->label('Confirm debit note')
                ->color('success')
                ->visible(fn (SupplierDebitNote $record): bool => $record->status === SupplierDebitNoteStatus::Draft
                    && (auth()->user()?->can('confirm', $record) ?? false))
                ->authorize(fn (SupplierDebitNote $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->requiresConfirmation()
                ->action(function (SupplierDebitNote $record): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated actor is required.');
                    }

                    app(SupplierDebitNoteService::class)->confirm($actor, $record);
                    $record->refresh();
                    Notification::make()->success()->title('Supplier debit note confirmed')->send();
                }),
            Action::make('reverse')
                ->label('Reverse debit note')
                ->color('danger')
                ->visible(fn (SupplierDebitNote $record): bool => $record->status === SupplierDebitNoteStatus::Confirmed
                    && (auth()->user()?->can('reverse', $record) ?? false))
                ->authorize(fn (SupplierDebitNote $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                ->requiresConfirmation()
                ->action(function (SupplierDebitNote $record): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated actor is required.');
                    }

                    app(SupplierDebitNoteService::class)->reverse($actor, $record);
                    $record->refresh();
                    Notification::make()->success()->title('Supplier debit note reversed')->send();
                }),
        ];
    }
}
