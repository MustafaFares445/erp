<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions;

use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Resources\PaymentTransactions\Pages\ViewPaymentTransaction;
use App\Filament\Resources\PaymentTransactions\Schemas\PaymentTransactionInfolist;
use App\Filament\Resources\PaymentTransactions\Tables\PaymentTransactionsTable;
use App\Models\PaymentTransaction;
use App\Services\Payments\ProviderPaymentSettlementService;
use App\Services\Payments\StripePaymentReconciliationService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only reconciliation view over provider transactions — no create/edit
 * pages, and no action here ever force-sets a Succeeded/settled state (§17
 * of the Customer App V1 plan). Every action is a thin adapter over
 * {@see StripePaymentReconciliationService} or
 * {@see ProviderPaymentSettlementService}.
 */
final class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.sales';

    protected static ?int $navigationSort = 780;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.payment_transactions');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PaymentTransactionInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PaymentTransactionsTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'payment', 'purpose']);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPaymentTransactions::route('/'),
            'view' => ViewPaymentTransaction::route('/{record}'),
        ];
    }
}
