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
            ->label(__('admin.payments.transaction_actions.refresh_status'))
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

                Notification::make()->success()->title(__('admin.payments.transaction_notifications.status_refreshed'))->send();
            });
    }

    public static function retrySettlement(): Action
    {
        return Action::make('retry_settlement')
            ->label(__('admin.payments.transaction_actions.complete_settlement'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.payments.transaction_actions.complete_settlement_confirm'))
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

                Notification::make()->success()->title(__('admin.payments.transaction_notifications.settlement_retried'))->send();
            });
    }
}
