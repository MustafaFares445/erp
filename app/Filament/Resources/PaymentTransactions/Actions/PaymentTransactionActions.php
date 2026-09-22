<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Actions;

use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use App\Services\Payments\ProviderPaymentSettlementService;
use App\Services\Payments\StripePaymentReconciliationService;
use App\Services\Support\TicketProviderSettlementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Safe reconciliation actions only — refreshing from Stripe or retrying a
 * settlement that Stripe already verified. Neither ever writes a Succeeded
 * status by hand; that only ever comes from Stripe's own PaymentIntent.
 */
final class PaymentTransactionActions
{
    public static function refreshStatus(): Action
    {
        return Action::make('refresh_status')
            ->label('Refresh from Stripe')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (PaymentTransaction $record): bool => ! $record->isSettled()
                && Auth::user()?->can('reconcile', $record) === true)
            ->authorize('reconcile')
            ->action(function (PaymentTransaction $record): void {
                try {
                    app(StripePaymentReconciliationService::class)->reconcile($record);
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title($domainException->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Status refreshed from Stripe.')->send();
            });
    }

    public static function retrySettlement(): Action
    {
        return Action::make('retry_settlement')
            ->label('Retry settlement')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Creates the ERP Payment for this already-verified Stripe transaction. This does nothing if it is already settled.')
            ->visible(fn (PaymentTransaction $record): bool => $record->status === PaymentTransactionStatus::Succeeded
                && ! $record->isSettled()
                && Auth::user()?->can('reconcile', $record) === true)
            ->authorize('reconcile')
            ->action(function (PaymentTransaction $record): void {
                try {
                    if ($record->purpose instanceof TicketPaymentLink) {
                        app(TicketProviderSettlementService::class)->settle($record);
                    } else {
                        app(ProviderPaymentSettlementService::class)->settle($record);
                    }
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title($domainException->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Settlement retried.')->send();
            });
    }
}
