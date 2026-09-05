<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Tables;

use App\Enums\WriteOffStatus;
use App\Filament\Resources\ReceivableWriteOffs\Actions\ReceivableWriteOffActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReceivableWriteOffsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('write_off_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('invoice.invoice_number')->label('Invoice')->searchable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
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
                TextColumn::make('recordedBy.name')->label('Recorded by'),
                TextColumn::make('approvedBy.name')->label('Approved by')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(WriteOffStatus::cases())
                        ->mapWithKeys(fn (WriteOffStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),
            ])
            ->recordActions([
                ViewAction::make(),
                ReceivableWriteOffActions::approve(),
                ReceivableWriteOffActions::cancel(),
            ]);
    }
}
