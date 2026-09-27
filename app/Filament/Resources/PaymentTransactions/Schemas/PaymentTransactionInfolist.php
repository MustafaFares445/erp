<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Schemas;

use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionSettlementState;
use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

final class PaymentTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::hero(),
            self::statusCallout(),
            Grid::make(12)->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        self::purpose(),
                        self::failureDetails(),
                        self::technicalIdentifiers(),
                    ]),
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
                        self::settlement(),
                        self::timestamps(),
                    ]),
            ]),
        ]);
    }

    private static function hero(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.title'))
            ->schema([
                TextEntry::make('provider')
                    ->label(__('admin.payments.transaction_ui.provider'))
                    ->formatStateUsing(fn (?PaymentProvider $state): string => $state?->label() ?? '—'),
                TextEntry::make('amount')
                    ->label(__('admin.payments.transaction_ui.amount'))
                    ->state(fn (PaymentTransaction $record): float => $record->amount())
                    ->money(fn (PaymentTransaction $record): string => $record->currency)
                    ->weight(FontWeight::Bold)
                    ->size(TextSize::Large),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('status')
                    ->label(__('admin.payments.transaction_ui.provider_status'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentTransactionStatus $state): string => $state->label())
                    ->color(fn (PaymentTransactionStatus $state): string => $state->color()),
            ])
            ->columns(['default' => 1, 'md' => 2, 'lg' => 4]);
    }

    private static function statusCallout(): Callout
    {
        return Callout::make(fn (PaymentTransaction $record): string => self::bannerMeta($record)['heading'])
            ->description(fn (PaymentTransaction $record): string => self::bannerMeta($record)['description'])
            ->status(fn (PaymentTransaction $record): string => self::bannerMeta($record)['status']);
    }

    /** @return array{status: string, heading: string, description: string} */
    private static function bannerMeta(PaymentTransaction $record): array
    {
        $settlement = $record->settlementState();

        if ($settlement === PaymentTransactionSettlementState::RequiresAttention) {
            $isTicket = $record->purpose instanceof TicketPaymentLink;

            return [
                'status' => 'danger',
                'heading' => __('admin.payments.transaction_ui.requires_attention_heading'),
                'description' => $isTicket
                    ? __('admin.payments.transaction_ui.ticket_settlement_pending')
                    : __('admin.payments.transaction_ui.erp_settlement_pending'),
            ];
        }

        if ($settlement === PaymentTransactionSettlementState::Settled) {
            $description = $record->payment instanceof Payment
                ? __('admin.payments.transaction_ui.erp_payment_created', [
                    'number' => $record->payment->payment_number,
                ])
                : __('admin.payments.transaction_ui.ticket_settled');

            return [
                'status' => 'success',
                'heading' => __('admin.payments.transaction_ui.settled_heading'),
                'description' => $description,
            ];
        }

        return [
            'status' => $settlement->color(),
            'heading' => self::settlementHeading($settlement),
            'description' => $record->settlementDescription(),
        ];
    }

    private static function settlementHeading(PaymentTransactionSettlementState $settlement): string
    {
        return match ($settlement) {
            PaymentTransactionSettlementState::WaitingForProvider => __('admin.payments.transaction_ui.waiting_heading'),
            PaymentTransactionSettlementState::RequiresAttention => __('admin.payments.transaction_ui.requires_attention_heading'),
            PaymentTransactionSettlementState::Settled => __('admin.payments.transaction_ui.settled_heading'),
            PaymentTransactionSettlementState::Failed => __('admin.payments.transaction_ui.failed_heading'),
            PaymentTransactionSettlementState::Cancelled => __('admin.payments.transaction_ui.cancelled_heading'),
        };
    }

    private static function purpose(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.purpose_heading'))
            ->description(__('admin.payments.transaction_ui.purpose_description'))
            ->schema([
                TextEntry::make('purpose_display')
                    ->label(__('admin.payments.transaction_ui.purpose'))
                    ->state(fn (PaymentTransaction $record): string => $record->purposeLabel())
                    ->url(fn (PaymentTransaction $record): ?string => self::purposeUrl($record)),
                TextEntry::make('purpose_amount')
                    ->label(__('admin.payments.transaction_ui.amount'))
                    ->state(fn (PaymentTransaction $record): float => $record->amount())
                    ->money(fn (PaymentTransaction $record): string => $record->currency),
            ])
            ->columns(2);
    }

    private static function purposeUrl(PaymentTransaction $record): ?string
    {
        return match (true) {
            $record->purpose instanceof Invoice => InvoiceResource::getUrl('view', ['record' => $record->purpose_id]),
            $record->purpose instanceof Order => OrderResource::getUrl('view', ['record' => $record->purpose_id]),
            $record->purpose instanceof TicketPaymentLink && $record->purpose->ticket !== null => TicketResource::getUrl(
                'view',
                ['record' => $record->purpose->ticket->getKey()],
            ),
            default => null,
        };
    }

    private static function failureDetails(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.failure_details'))
            ->schema([
                TextEntry::make('failure_code')->label(__('admin.payments.transaction_ui.failure_code'))->placeholder('—'),
                TextEntry::make('failure_message')->label(__('admin.payments.transaction_ui.failure_reason'))->columnSpanFull()->placeholder('—'),
            ])
            ->columns(2)
            ->visible(fn (PaymentTransaction $record): bool => $record->status === PaymentTransactionStatus::Failed);
    }

    private static function technicalIdentifiers(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.technical_details'))
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('checkout_session_id')->label(__('admin.sales.payment_ui.checkout_session'))
                    ->visible(fn (PaymentTransaction $record): bool => filled($record->checkout_session_id)),
                TextEntry::make('payment_intent_id')->label(__('admin.sales.payment_ui.payment_intent'))
                    ->visible(fn (PaymentTransaction $record): bool => filled($record->payment_intent_id)),
                TextEntry::make('provider_charge_id')->label(__('admin.payments.transaction_ui.provider_charge'))
                    ->visible(fn (PaymentTransaction $record): bool => filled($record->provider_charge_id)),
                TextEntry::make('last_provider_event_id')->label(__('admin.payments.transaction_ui.last_event'))
                    ->visible(fn (PaymentTransaction $record): bool => filled($record->last_provider_event_id)),
                TextEntry::make('idempotency_key')->label(__('admin.payments.transaction_ui.idempotency_key'))
                    ->visible(fn (PaymentTransaction $record): bool => filled($record->idempotency_key)),
            ])
            ->columns(2)
            ->visible(fn (PaymentTransaction $record): bool => filled($record->checkout_session_id)
                || filled($record->payment_intent_id)
                || filled($record->provider_charge_id)
                || filled($record->last_provider_event_id)
                || filled($record->idempotency_key));
    }

    private static function settlement(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.erp_settlement'))
            ->schema([
                TextEntry::make('settlement_state')
                    ->label(__('admin.payments.transaction_ui.settlement_state'))
                    ->state(fn (PaymentTransaction $record): string => $record->settlementState()->label())
                    ->badge()
                    ->color(fn (PaymentTransaction $record): string => $record->settlementState()->color()),
                TextEntry::make('settlement_description')
                    ->state(fn (PaymentTransaction $record): string => $record->settlementDescription())
                    ->columnSpanFull(),
                TextEntry::make('payment.payment_number')
                    ->label(__('admin.payments.transaction_ui.erp_payment'))
                    ->url(fn (PaymentTransaction $record): ?string => $record->payment instanceof Payment
                        ? PaymentResource::getUrl('view', ['record' => $record->payment->getKey()])
                        : null)
                    ->placeholder(fn (PaymentTransaction $record): string => $record->payment_id !== null
                        ? __('admin.payments.transaction_ui.payment_record_missing')
                        : __('admin.payments.transaction_ui.payment_not_created'))
                    ->visible(fn (PaymentTransaction $record): bool => ! ($record->purpose instanceof TicketPaymentLink)),
                TextEntry::make('payment_status')
                    ->label(__('admin.payments.transaction_ui.erp_payment_status'))
                    ->state(fn (PaymentTransaction $record): string => $record->payment?->status->label() ?? '—')
                    ->visible(fn (PaymentTransaction $record): bool => $record->payment !== null),
                TextEntry::make('allocated_amount')
                    ->label(__('admin.sales.payment_ui.applied_amount'))
                    ->state(fn (PaymentTransaction $record): float => ($record->payment?->allocatedAmountMinor() ?? 0) / 100)
                    ->money(fn (PaymentTransaction $record): string => $record->currency)
                    ->visible(fn (PaymentTransaction $record): bool => $record->payment !== null),
                TextEntry::make('customer_deposit')
                    ->label(__('admin.sales.payment_ui.customer_deposit'))
                    ->state(fn (PaymentTransaction $record): float => ($record->payment?->customerDepositMinor() ?? 0) / 100)
                    ->money(fn (PaymentTransaction $record): string => $record->currency)
                    ->visible(fn (PaymentTransaction $record): bool => $record->payment !== null),
            ])
            ->columns(2);
    }

    private static function timestamps(): Section
    {
        return Section::make(__('admin.payments.transaction_ui.timestamps'))
            ->schema([
                TextEntry::make('created_at')->label(__('admin.payments.transaction_ui.created_at'))->dateTime(),
                TextEntry::make('succeeded_at')->label(__('admin.payments.transaction_ui.succeeded_at'))->dateTime()
                    ->visible(fn (PaymentTransaction $record): bool => $record->succeeded_at !== null),
                TextEntry::make('cancelled_at')->label(__('admin.payments.transaction_ui.cancelled_at'))->dateTime()
                    ->visible(fn (PaymentTransaction $record): bool => $record->cancelled_at !== null),
                TextEntry::make('refunded_at')->label(__('admin.payments.transaction_ui.refunded_at'))->dateTime()
                    ->visible(fn (PaymentTransaction $record): bool => $record->refunded_at !== null),
            ]);
    }
}
