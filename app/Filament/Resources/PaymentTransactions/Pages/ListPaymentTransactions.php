<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Pages;

use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Models\PaymentTransaction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPaymentTransactions extends ListRecords
{
    protected static string $resource = PaymentTransactionResource::class;

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('admin.payments.transaction_tabs.all')),
            'pending' => Tab::make(__('admin.payments.transaction_tabs.pending'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentTransactionStatus::Pending->value)),
            'requires_action' => Tab::make(__('admin.payments.transaction_tabs.requires_action'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentTransactionStatus::RequiresAction->value)),
            'succeeded' => Tab::make(__('admin.payments.transaction_tabs.succeeded'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentTransactionStatus::Succeeded->value)),
            'requires_attention' => Tab::make(__('admin.payments.transaction_tabs.requires_attention'))
                ->badge(PaymentTransaction::query()->requiresSettlementAttention()->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn(
                    'payment_transactions.id',
                    PaymentTransaction::query()->requiresSettlementAttention()->select('payment_transactions.id'),
                )),
            'failed' => Tab::make(__('admin.payments.transaction_tabs.failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentTransactionStatus::Failed->value)),
            'cancelled' => Tab::make(__('admin.payments.transaction_tabs.cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentTransactionStatus::Cancelled->value)),
        ];
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [];
    }
}
