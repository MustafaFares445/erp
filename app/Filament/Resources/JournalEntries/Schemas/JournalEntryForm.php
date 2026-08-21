<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\JournalEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.resources.journal_entries'))
                    ->schema([
                        TextInput::make('entry_number')
                            ->label(__('admin.accounting.fields.entry_number'))
                            // Allocated by JournalEntry::nextEntryNumber() on
                            // create, so it is shown but never submitted.
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?JournalEntry $record): bool => $record instanceof JournalEntry),
                        DatePicker::make('entry_date')
                            ->label(__('admin.accounting.fields.entry_date'))
                            ->required()
                            ->default(today()),
                        Textarea::make('description')
                            ->label(__('admin.accounting.fields.description'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('admin.accounting.fields.lines'))
                    ->description(fn (?JournalEntry $record): string => $record?->isPosted() === true
                        ? __('admin.accounting.hints.posted_readonly')
                        : __('admin.accounting.hints.draft_unbalanced'))
                    ->schema([
                        JournalEntryLinesRepeater::make(),
                        JournalEntryLinesRepeater::totals(),
                    ]),
            ])
            // Belt and braces over the policy, which already refuses `update` on
            // a posted entry, and the model's own `booted()` guard: three layers,
            // because a silently-editable posted entry is the one failure the
            // ledger cannot recover from (FR-025).
            ->disabled(fn (?JournalEntry $record): bool => $record?->isPosted() ?? false);
    }
}
