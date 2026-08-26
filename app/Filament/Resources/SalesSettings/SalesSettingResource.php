<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesSettings;

use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\SalesSettings\Pages\ManageSalesSettings;
use App\Models\ChartAccount;
use App\Models\SalesSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The default tax rate, quotation validity, and four posting accounts,
 * mirroring the {@see PurchaseSettingResource}
 * singleton shape.
 *
 * Reachable by System Admin alone (`sales.setting.manage`). The posting
 * accounts decide what invoicing and collection touch in the ledger, which is
 * an accounting decision wearing a sales label, not something a Sales or
 * Billing role should move on their own.
 *
 * @see /specs/019-sales-lifecycle-payments-credits/data-model.md §1
 */
final class SalesSettingResource extends Resource
{
    protected static ?string $model = SalesSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.sales_settings');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.sales_settings');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return parent::canCreate() && SalesSetting::query()->doesntExist();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('default_tax_percent')
                ->label(__('admin.sales.fields.default_tax_percent'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->required(),
            TextInput::make('default_quotation_validity_days')
                ->label(__('admin.sales.fields.default_quotation_validity_days'))
                ->numeric()
                ->minValue(1)
                ->required()
                ->default(30),
            Select::make('receivable_account_id')
                ->label(__('admin.sales.fields.receivable_account'))
                ->options(self::postableAccountOptions(...))
                ->searchable(),
            Select::make('revenue_account_id')
                ->label(__('admin.sales.fields.revenue_account'))
                ->options(self::postableAccountOptions(...))
                ->searchable(),
            Select::make('deferred_tax_account_id')
                ->label(__('admin.sales.fields.deferred_tax_account'))
                ->options(self::postableAccountOptions(...))
                ->searchable(),
            Select::make('tax_payable_account_id')
                ->label(__('admin.sales.fields.tax_payable_account'))
                ->options(self::postableAccountOptions(...))
                ->searchable(),
            Select::make('customer_deposits_account_id')
                ->label(__('admin.sales.fields.customer_deposits_account'))
                ->options(self::postableAccountOptions(...))
                ->searchable(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('default_tax_percent')
                ->label(__('admin.sales.fields.default_tax_percent'))
                ->numeric(decimalPlaces: 2)
                ->suffix('%'),
            TextColumn::make('default_quotation_validity_days')
                ->label(__('admin.sales.fields.default_quotation_validity_days')),
            TextColumn::make('receivableAccount.name')
                ->label(__('admin.sales.fields.receivable_account'))
                ->placeholder('—'),
            TextColumn::make('revenueAccount.name')
                ->label(__('admin.sales.fields.revenue_account'))
                ->placeholder('—'),
            TextColumn::make('deferredTaxAccount.name')
                ->label(__('admin.sales.fields.deferred_tax_account'))
                ->placeholder('—'),
            TextColumn::make('taxPayableAccount.name')
                ->label(__('admin.sales.fields.tax_payable_account'))
                ->placeholder('—'),
            TextColumn::make('customerDepositsAccount.name')
                ->label(__('admin.sales.fields.customer_deposits_account'))
                ->placeholder('—'),
        ])->recordActions([EditAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSalesSettings::route('/')];
    }

    /**
     * The only accounts posting would accept — postable leaves that are still
     * active. Offering any other account would guarantee a rejection later
     * (FR-007), mirroring `JournalEntryLinesRepeater::postableAccountOptions()`.
     *
     * @return array<int, string>
     */
    private static function postableAccountOptions(): array
    {
        $options = [];

        $accounts = ChartAccount::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $options[$account->id] = $account->code.' — '.$account->name;
        }

        return $options;
    }
}
