<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Schemas;

use App\Enums\InvoiceStatus;
use App\Enums\WriteOffReason;
use App\Models\Invoice;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class ReceivableWriteOffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'company_name')
                ->default(request()->integer('customer_id') ?: null)
                ->searchable()
                ->preload()
                ->required(),
            Select::make('invoice_id')
                ->label('Invoice')
                ->default(request()->integer('invoice_id') ?: null)
                ->options(fn (): array => Invoice::query()
                    ->whereNotIn('status', [
                        InvoiceStatus::Cancelled->value,
                        InvoiceStatus::WrittenOff->value,
                    ])
                    ->whereNotNull('issued_at')
                    ->orderByDesc('invoice_date')
                    ->get()
                    ->filter(fn (Invoice $invoice): bool => $invoice->outstandingMinor() > 0)
                    ->mapWithKeys(fn (Invoice $invoice): array => [
                        (int) $invoice->getKey() => sprintf(
                            '%s — outstanding %.2f',
                            $invoice->invoice_number,
                            $invoice->outstandingAmount(),
                        ),
                    ])
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('amount')
                ->label('Write-off amount')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->default(function (): ?string {
                    $invoiceId = request()->integer('invoice_id');
                    if ($invoiceId <= 0) {
                        return null;
                    }

                    $invoice = Invoice::query()->find($invoiceId);

                    return $invoice instanceof Invoice
                        ? number_format($invoice->outstandingAmount(), 2, '.', '')
                        : null;
                })
                ->required(),
            Select::make('reason_category')
                ->label('Reason category')
                ->options(
                    collect(WriteOffReason::cases())
                        ->mapWithKeys(fn (WriteOffReason $reason): array => [$reason->value => $reason->label()])
                        ->all(),
                )
                ->required(),
            Textarea::make('reason')
                ->label('Reason')
                ->rows(4)
                ->required()
                ->columnSpanFull(),
        ])->columns(2);
    }
}
