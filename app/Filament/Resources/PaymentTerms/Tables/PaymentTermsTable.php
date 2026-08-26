<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTerms\Tables;

use App\Models\PaymentTerm;
use App\Services\Sales\PaymentTermService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class PaymentTermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_days')
            ->columns([
                TextColumn::make('name')->label(__('admin.sales.fields.name'))->searchable()->sortable(),
                TextColumn::make('due_days')->label(__('admin.sales.fields.due_days'))->sortable(),
                TextColumn::make('grace_days')->label(__('admin.sales.fields.grace_days'))->sortable(),
                TextColumn::make('discount_percent')->label(__('admin.sales.fields.discount_percent'))->placeholder('—'),
                IconColumn::make('is_default')->label(__('admin.sales.fields.is_default'))->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (PaymentTerm $record): bool => tap(true, fn () => app(PaymentTermService::class)->delete($record))),
                RestoreAction::make(),
            ]);
    }
}
