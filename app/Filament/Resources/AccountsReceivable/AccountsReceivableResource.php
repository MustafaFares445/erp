<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsReceivable;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Models\Invoice;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::ReceivableView->value);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('issued_at')
            ->where('status', '!=', InvoiceStatus::Cancelled->value);
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
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer')->relationship('customer', 'company_name')->searchable()->preload(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListAccountsReceivable::route('/')];
    }
}