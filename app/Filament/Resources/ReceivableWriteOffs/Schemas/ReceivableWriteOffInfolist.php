<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Schemas;

use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Models\ReceivableWriteOff;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ReceivableWriteOffInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Write-off')
                ->columns(3)
                ->schema([
                    TextEntry::make('write_off_number'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (WriteOffStatus $state): string => $state->label())
                        ->color(fn (WriteOffStatus $state): string => $state->color()),
                    TextEntry::make('customer.company_name')->label('Customer'),
                    TextEntry::make('invoice.invoice_number')->label('Invoice'),
                    TextEntry::make('amount')
                        ->label('Write-off amount')
                        ->state(fn (ReceivableWriteOff $record): string => number_format($record->amount_minor / 100, 2, '.', '')),
                    TextEntry::make('tax_amount')
                        ->label('Deferred tax released')
                        ->state(fn (ReceivableWriteOff $record): string => number_format($record->tax_amount_minor / 100, 2, '.', '')),
                    TextEntry::make('reason_category')
                        ->formatStateUsing(fn (WriteOffReason $state): string => $state->label()),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('recordedBy.name')->label('Recorded by'),
                    TextEntry::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                    TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                    TextEntry::make('fiscalPeriod.name')->label('Fiscal period'),
                    TextEntry::make('journalEntry.entry_number')->label('Journal entry')->placeholder('—'),
                ]),
        ]);
    }
}
