<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Tables;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\FiscalPeriods\Actions\FiscalPeriodActions;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class FiscalPeriodsTable
{
    use InteractsWithAccountingServices;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.accounting.fields.period_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('admin.accounting.fields.starts_at'))
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('admin.accounting.fields.ends_at'))
                    ->date()
                    ->sortable(),
                IconColumn::make('is_closed')
                    ->label(__('admin.accounting.fields.is_closed'))
                    ->boolean()
                    ->trueIcon(Heroicon::LockClosed)
                    ->falseIcon(Heroicon::LockOpen)
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('journal_entries_count')
                    ->label(__('admin.accounting.fields.lines'))
                    ->counts('journalEntries')
                    ->badge(),
                TextColumn::make('updatedBy.name')
                    ->label('Last updated by')
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('is_closed')
                    ->label(__('admin.accounting.fields.is_closed')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (FiscalPeriod $record): bool => ! $record->is_closed),
                FiscalPeriodActions::close(),
                FiscalPeriodActions::reopen(),
                DeleteAction::make()
                    // Routed through the service so a period holding journal
                    // entries is refused with a message rather than a foreign-key
                    // error (FR-017).
                    ->using(function (FiscalPeriod $record): bool {
                        $actor = self::accountingActor();

                        if (! $actor instanceof User) {
                            return false;
                        }

                        self::runAccountingOperation(
                            fn () => app(FiscalPeriodService::class)->delete($actor, $record),
                        );

                        return true;
                    }),
            ]);
    }
}
