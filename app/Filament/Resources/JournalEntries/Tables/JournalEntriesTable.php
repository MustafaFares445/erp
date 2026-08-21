<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Enums\JournalEntryStatus;
use App\Filament\Resources\JournalEntries\Actions\JournalEntryActions;
use App\Models\FiscalPeriod;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('entry_date', 'desc')
            ->columns([
                TextColumn::make('entry_number')
                    ->label(__('admin.accounting.fields.entry_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entry_date')
                    ->label(__('admin.accounting.fields.entry_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->searchable()
                    ->placeholder('—')
                    ->wrap()
                    ->limit(60),
                TextColumn::make('status')
                    ->label(__('admin.accounting.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (JournalEntryStatus $state): string => $state->label())
                    ->color(static fn (JournalEntryStatus $state): string => match ($state) {
                        JournalEntryStatus::Draft => 'gray',
                        JournalEntryStatus::Posted => 'success',
                    }),
                TextColumn::make('fiscalPeriod.name')
                    ->label(__('admin.accounting.fields.fiscal_period'))
                    // Null until the entry is posted, when the period is resolved
                    // from its date (research.md R-004).
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->label(__('admin.accounting.fields.lines'))
                    ->counts('lines')
                    ->badge(),
                TextColumn::make('reversal.entry_number')
                    ->label(__('admin.accounting.fields.reversed_by'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.accounting.fields.status'))
                    ->options(static fn (): array => self::statusOptions()),
                SelectFilter::make('fiscal_period_id')
                    ->label(__('admin.accounting.fields.fiscal_period'))
                    ->options(fn (): array => FiscalPeriod::query()
                        ->orderByDesc('starts_at')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                // Edit and Delete are hidden for a posted entry by
                // JournalEntryPolicy, which refuses both outright rather than by
                // permission (FR-025, permissions.md R-1).
                EditAction::make(),
                JournalEntryActions::post(),
                JournalEntryActions::reverse(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (JournalEntryStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
