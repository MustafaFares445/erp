<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Tables;

use App\Enums\WriteOffStatus;
use App\Filament\Resources\ReceivableWriteOffs\Actions\ReceivableWriteOffActions;
use App\Filament\Tables\TableFit;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReceivableWriteOffsTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('write_off_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable()->wrap(),
                TextColumn::make('invoice.invoice_number')->label(__('Invoice'))->searchable(),
                TextColumn::make('amount_minor')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => sprintf(
                        '%d.%02d',
                        intdiv($state, 100),
                        $state % 100,
                    ))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WriteOffStatus $state): string => $state->label())
                    ->color(fn (WriteOffStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('recordedBy.name')->label(__('Recorded by'))->wrap(),
                TextColumn::make('approvedBy.name')->label(__('Approved by'))->placeholder(__('—'))->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(WriteOffStatus::cases())
                        ->mapWithKeys(fn (WriteOffStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),
            ])
            ->recordActions(TableFit::rowActions([
                ReceivableWriteOffActions::approve(),
                ActionGroup::make([
                    ViewAction::make(),
                    ReceivableWriteOffActions::cancel(),
                ])->icon(Heroicon::EllipsisVertical),
            ]));
    }
}
