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
            Section::make(__('Write-off'))
                ->columns(3)
                ->schema([
                    TextEntry::make('write_off_number'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (WriteOffStatus $state): string => $state->label())
                        ->color(fn (WriteOffStatus $state): string => $state->color()),
                    TextEntry::make('customer.company_name')->label(__('Customer')),
                    TextEntry::make('invoice.invoice_number')->label(__('Invoice')),
                    TextEntry::make('amount')
                        ->label(__('Write-off amount'))
                        ->state(fn (ReceivableWriteOff $record): string => sprintf(
                            '%d.%02d',
                            intdiv($record->amount_minor, 100),
                            $record->amount_minor % 100,
                        )),
                    TextEntry::make('tax_amount')
                        ->label(__('Deferred tax released'))
                        ->state(fn (ReceivableWriteOff $record): string => sprintf(
                            '%d.%02d',
                            intdiv($record->tax_amount_minor, 100),
                            $record->tax_amount_minor % 100,
                        )),
                    TextEntry::make('reason_category')
                        ->formatStateUsing(fn (WriteOffReason $state): string => $state->label()),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('recordedBy.name')->label(__('Recorded by')),
                    TextEntry::make('approvedBy.name')->label(__('Approved by'))->placeholder(__('—')),
                    TextEntry::make('approved_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('fiscalPeriod.name')->label(__('Fiscal period')),
                    TextEntry::make('journalEntry.entry_number')->label(__('Journal entry'))->placeholder(__('—')),
                ]),
        ]);
    }
}
