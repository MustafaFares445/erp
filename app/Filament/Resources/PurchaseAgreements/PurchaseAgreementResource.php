<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements;

use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchasePermission;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\PurchaseAgreements\Pages\CreatePurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\Pages\ListPurchaseAgreements;
use App\Filament\Resources\PurchaseAgreements\Pages\ViewPurchaseAgreement;
use App\Filament\Support\CurrencySelect;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\ProductVariant;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseAgreementLine;
use App\Models\Supplier;
use App\Models\Unit;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PurchaseAgreementResource extends Resource
{
    protected static ?string $model = PurchaseAgreement::class;

    protected static ?string $recordTitleAttribute = 'agreement_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    protected static ?int $navigationSort = 102;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchase_agreements');
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PurchasePermission::AgreementView->value) ?? false;
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return auth()->user()?->can(PurchasePermission::AgreementManage->value) ?? false;
    }

    #[\Override]
    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Agreement details'))->schema([
                Select::make('supplier_id')->required()->searchable()->preload()
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                CurrencySelect::make('currency_code')->required(),
                DatePicker::make('starts_on')->required()->default(today()),
                DatePicker::make('ends_on')->afterOrEqual('starts_on'),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Agreement pricing'))->schema([
                Repeater::make('lines')->minItems(1)->defaultItems(1)->schema([
                    Select::make('product_variant_id')->label(__('Product variant'))->required()->searchable()
                        ->options(fn (): array => ProductVariant::query()->where('is_active', true)->orderBy('sku')->pluck('sku', 'id')->all()),
                    Select::make('unit_id')->label(__('Unit'))->required()->searchable()
                        ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('unit_price')->numeric()->minValue(0)->required(),
                    TextInput::make('minimum_order_quantity')->numeric()->minValue(0),
                    TextInput::make('lead_time_days')->numeric()->minValue(0),
                ])->columns(2),
            ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Agreement summary'))->columns(3)->schema([
                TextEntry::make('agreement_number')->label(__('Agreement')),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('status')->badge(),
                TextEntry::make('currency_code')->label(__('Currency')),
                TextEntry::make('starts_on')->date(),
                TextEntry::make('ends_on')->date()->placeholder('—'),
                TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Agreement lines'))->schema([
                RepeatableEntry::make('lines')->schema([
                    TextEntry::make('productVariant.sku')->label(__('SKU')),
                    TextEntry::make('productVariant.name')->label(__('Variant')),
                    TextEntry::make('unit.name')->label(__('Unit')),
                    TextEntry::make('unit_price')->money(static function (PurchaseAgreementLine $record): string {
                        $agreement = $record->agreement;

                        return $agreement instanceof PurchaseAgreement ? $agreement->currency_code : 'AED';
                    }),
                    TextEntry::make('minimum_order_quantity')->label(__('MOQ'))->placeholder('—'),
                    TextEntry::make('lead_time_days')->label(__('Lead days'))->placeholder('—'),
                ])->columns(3),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            FavoriteColumn::make(),
            TextColumn::make('agreement_number')->label(__('Agreement'))->searchable()->sortable(),
            TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('currency_code')->label(__('Currency')),
            TextColumn::make('starts_on')->date()->sortable(),
            TextColumn::make('ends_on')->date()->placeholder('—')->sortable(),
        ])->groups([
            Group::make('status')->label(__('Status')),
            Group::make('supplier.name')->label(__('Supplier')),
            Group::make('currency_code')->label(__('Currency')),
            Group::make('starts_on')->label(__('Starts on'))->date(),
        ])->filters([
            TableQueryBuilder::make([
                SelectConstraint::make('status')
                    ->label(__('Status'))
                    ->options(PurchaseAgreementStatus::class)
                    ->multiple(),
                TextConstraint::make('agreement_number')->label(__('Agreement')),
                RelationshipConstraint::make('supplier')
                    ->label(__('Supplier'))
                    ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                TextConstraint::make('currency_code')->label(__('Currency')),
                DateConstraint::make('starts_on')->label(__('Starts on')),
                DateConstraint::make('ends_on')->label(__('Ends on')),
            ]),
        ]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['agreement_number', 'supplier.name', 'notes'];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseAgreements::route('/'),
            'create' => CreatePurchaseAgreement::route('/create'),
            'view' => ViewPurchaseAgreement::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'supplier:id,name',
            'creator:id,name',
            'lines.productVariant:id,sku,name',
            'lines.unit:id,name',
        ]);
    }
}
