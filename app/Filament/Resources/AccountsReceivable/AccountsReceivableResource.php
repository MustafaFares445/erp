<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsReceivable;

use App\Filament\Resources\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class AccountsReceivableResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 204;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.accounts_receivable');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable()->sortable(),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('amount_paid')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'issued' => 'Issued',
                    'partially_paid' => 'Partially paid',
                    'paid' => 'Paid',
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListAccountsReceivable::route('/')];
    }
}
