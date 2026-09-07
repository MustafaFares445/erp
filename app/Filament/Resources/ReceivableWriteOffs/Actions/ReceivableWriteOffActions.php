<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Actions;

use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Services\Accounting\ReceivableWriteOffService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class ReceivableWriteOffActions
{
    public static function approve(): Action
    {
        return Action::make('approve_write_off')
            ->label('Approve write-off')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (ReceivableWriteOff $record): bool => $record->isDraft())
            ->authorize('approve')
            ->action(function (ReceivableWriteOff $record): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(ReceivableWriteOffService::class)->approve($record, $actor);

                Notification::make()
                    ->success()
                    ->title('Receivable written off and posted.')
                    ->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_write_off')
            ->label('Cancel draft')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->schema([
                Textarea::make('reason')->label('Cancellation reason')->required(),
            ])
            ->visible(fn (ReceivableWriteOff $record): bool => $record->isDraft())
            ->authorize('cancel')
            ->action(function (ReceivableWriteOff $record, array $data): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                $reason = $data['reason'] ?? null;
                app(ReceivableWriteOffService::class)->cancel(
                    $record,
                    $actor,
                    is_string($reason) ? $reason : '',
                );

                Notification::make()->success()->title('Write-off draft cancelled.')->send();
            });
    }
}
