<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsPayable;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\AccountsPayable\Pages\ListAccountsPayable;
use App\Models\Bill;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AccountsPayableResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 205;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.accounts_payable');
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

        return $user->can(AccountingPermission::PayableView->value);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('status', ['approved', 'partially_paid']);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('bill_number')->searchable()->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('amount_paid')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'name')->searchable()->preload(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListAccountsPayable::route('/')];
    }
}
