<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class JournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('entry_number')->label(__('admin.accounting.fields.entry_number')),
                TextEntry::make('entry_date')->label(__('admin.accounting.fields.entry_date'))->date(),
                TextEntry::make('status')
                    ->label(__('admin.accounting.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (JournalEntryStatus $state): string => $state->label())
                    ->color(static fn (JournalEntryStatus $state): string => match ($state) {
                        JournalEntryStatus::Draft => 'gray',
                        JournalEntryStatus::Posted => 'success',
                    }),
                TextEntry::make('fiscalPeriod.name')
                    ->label(__('admin.accounting.fields.fiscal_period'))
                    ->placeholder('—'),
                // The morph carries whichever document produced the entry — or,
                // for a reversal, the entry it reverses (research.md R-003).
                TextEntry::make('source_type')
                    ->label(__('admin.accounting.fields.source'))
                    ->placeholder('—'),
                TextEntry::make('reversal.entry_number')
                    ->label(__('admin.accounting.fields.reversed_by'))
                    ->placeholder('—'),
                TextEntry::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),
            Section::make(__('admin.accounting.fields.lines'))
                ->description(fn (JournalEntry $record): string => $record->isPosted()
                    ? __('admin.accounting.hints.posted_readonly')
                    : '')
                ->schema([
                    RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                        TextEntry::make('chartAccount.code')->label(__('admin.accounting.fields.account')),
                        TextEntry::make('debit')->label(__('admin.accounting.fields.debit')),
                        TextEntry::make('credit')->label(__('admin.accounting.fields.credit')),
                        TextEntry::make('description')
                            ->label(__('admin.accounting.fields.description'))
                            ->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
