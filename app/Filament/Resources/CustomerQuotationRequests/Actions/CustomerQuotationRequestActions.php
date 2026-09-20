<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Actions;

use App\Models\CustomerQuotationRequest;
use App\Models\User;
use App\Services\Crm\CustomerQuotationRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Thin adapters over {@see CustomerQuotationRequestService}, shared between
 * the table and the view page.
 */
final class CustomerQuotationRequestActions
{
    public static function startReview(): Action
    {
        return Action::make('startReview')
            ->label('Start Review')
            ->icon(Heroicon::MagnifyingGlass)
            ->color('info')
            ->visible(fn (CustomerQuotationRequest $record): bool => $record->status->value === 'submitted')
            ->authorize('review')
            ->action(function (CustomerQuotationRequest $record): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerQuotationRequestService::class)->startReview($actor, $record);

                Notification::make()->success()->title('Request is now under review')->send();
            });
    }

    public static function convert(): Action
    {
        return Action::make('convertToQuotation')
            ->label('Convert to Quotation')
            ->icon(Heroicon::DocumentText)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (CustomerQuotationRequest $record): bool => $record->isOpen())
            ->authorize('review')
            ->action(function (CustomerQuotationRequest $record): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                $quotation = app(CustomerQuotationRequestService::class)->convertToQuotation($actor, $record);

                Notification::make()
                    ->success()
                    ->title(sprintf('Converted to quotation %s', (string) $quotation->quotation_number))
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectQuotationRequest')
            ->label('Reject')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')->label('Reason')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerQuotationRequest $record): bool => $record->isOpen())
            ->authorize('review')
            ->action(function (CustomerQuotationRequest $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                $reason = $data['reason'] ?? null;

                app(CustomerQuotationRequestService::class)->reject(
                    $actor,
                    $record,
                    is_string($reason) ? $reason : '',
                );

                Notification::make()->danger()->title('Request rejected')->send();
            });
    }

    private static function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
