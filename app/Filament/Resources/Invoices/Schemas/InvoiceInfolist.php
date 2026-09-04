<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(3)
                ->schema([
                    TextEntry::make('invoice_number')->label(__('admin.sales.fields.invoice_number')),
                    TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                        ->color(fn (InvoiceStatus $state): string => $state->color()),
                    TextEntry::make('invoice_date')->date(),
                    TextEntry::make('due_date')->date()->placeholder('—'),
                    TextEntry::make('subtotal')->money(),
                    TextEntry::make('tax_total')->money(),
                    TextEntry::make('total_amount')->money(),
                    TextEntry::make('amount_paid')->money(),
                    TextEntry::make('credited_amount')->money(),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Line price evidence')
                ->description('Frozen at document creation; later pricing-policy changes do not rewrite these values.')
                ->schema([
                    RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                        TextEntry::make('productVariant.sku')->label('Product')->placeholder('Service'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('unit_price')->label('Actual price')->money(),
                        TextEntry::make('resolved_price_source')
                            ->label('Price source')
                            ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->value)
                            ->placeholder('Service / legacy'),
                        TextEntry::make('resolvedPriceTier.name')->label('Pricing tier')->placeholder('—'),
                        TextEntry::make('list_price_minor')
                            ->label('List price snapshot')
                            ->state(static fn ($record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                            ->money()
                            ->placeholder('—'),
                        TextEntry::make('floor_price_minor')
                            ->label('Floor snapshot')
                            ->state(static fn ($record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                            ->money()
                            ->placeholder('—'),
                        TextEntry::make('priceFloorOverride.approvedBy.name')->label('Floor override approved by')->placeholder('—'),
                        TextEntry::make('tax_amount')->label('Tax')->money(),
                        TextEntry::make('line_total')->label('Line total')->money(),
                    ]),
                ]),
            Section::make('Receipt confirmation')
                ->columns(3)
                ->schema([
                    TextEntry::make('received_confirmation_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn (?InvoiceConfirmationType $state): ?string => $state?->label())
                        ->placeholder('Not confirmed'),
                    TextEntry::make('received_confirmed_at')
                        ->label('Confirmed at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('receivedConfirmedBy.name')
                        ->label('Confirmed by')
                        ->placeholder('—'),
                    TextEntry::make('receipt_signature')
                        ->label('Signature evidence')
                        ->state(fn (Invoice $record): string => $record->confirmations()
                            ->orderByDesc('confirmed_at')
                            ->orderByDesc('id')
                            ->first()?->getFirstMedia('invoice-confirmation-signature')?->file_name ?? 'No signature attached')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
