<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts;

use App\Filament\Resources\ChartOfAccounts\Pages\CreateChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\Pages\EditChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use App\Filament\Resources\ChartOfAccounts\Pages\ViewChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\RelationManagers\LedgerRelationManager;
use App\Filament\Resources\ChartOfAccounts\Schemas\ChartAccountForm;
use App\Filament\Resources\ChartOfAccounts\Schemas\ChartAccountInfolist;
use App\Filament\Resources\ChartOfAccounts\Tables\ChartAccountsTable;
use App\Models\ChartAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The chart of accounts, and the only place an account's posted ledger can be
 * read (FR-038) — hence the View page, which the sibling Fiscal Periods resource
 * does not need.
 *
 * @see /specs/018-chart-of-accounts-journals/spec.md User Story 2, User Story 6
 */
final class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 201;

    protected static ?string $recordTitleAttribute = 'code';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.chart_of_accounts');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.chart_of_accounts');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ChartAccountForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ChartAccountInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ChartAccountsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            LedgerRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListChartOfAccounts::route('/'),
            'create' => CreateChartOfAccount::route('/create'),
            'view' => ViewChartOfAccount::route('/{record}'),
            'edit' => EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
