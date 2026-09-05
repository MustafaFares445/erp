<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Models\Quotation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('quotation_number')->label(__('admin.sales.fields.quotation_number'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('status')->label(__('admin.sales.fields.status'))->badge(),
                TextColumn::make('reservation_coverage')
                    ->label('Stock coverage')
                    ->state(fn (Quotation $record): ?string => $record->hasLapsedReservations() ? 'Lapsed' : null)
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date()->sortable(),
                TextColumn::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date()->sortable(),
                TextColumn::make('grand_total')->label(__('admin.sales.fields.grand_total'))->money()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->options(array_combine(
                        array_map(fn (QuotationStatus $status): string => $status->value, QuotationStatus::cases()),
                        array_map(fn (QuotationStatus $status): string => $status->label(), QuotationStatus::cases()),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                QuotationActions::send(),
                QuotationActions::recordDecision(),
                QuotationActions::convert(),
                QuotationActions::requote(),
            ]);
    }
}
