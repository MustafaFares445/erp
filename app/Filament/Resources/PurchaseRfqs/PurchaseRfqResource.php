<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseRfqStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\PurchaseRfqs\Pages\CreatePurchaseRfq;
use App\Filament\Resources\PurchaseRfqs\Pages\ListPurchaseRfqs;
use App\Filament\Resources\PurchaseRfqs\Pages\ViewPurchaseRfq;
use App\Filament\Support\CurrencySelect;
use App\Models\ProductVariant;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\Unit;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PurchaseRfqResource extends Resource
{
    protected static ?string $model = PurchaseRfq::class;

    protected static ?string $recordTitleAttribute = 'rfq_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    protected static ?int $navigationSort = 101;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Requests for quotation');
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PurchasePermission::RfqView->value) ?? false;
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return auth()->user()?->can(PurchasePermission::RfqManage->value) ?? false;
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
            Section::make(__('RFQ details'))->schema([
                CurrencySelect::make('currency_code')->required(),
                DatePicker::make('needed_by')->label(__('Needed by')),
                DateTimePicker::make('closes_at')->label(__('Closes at')),
                Textarea::make('notes')->columnSpanFull(),
                Select::make('supplier_ids')
                    ->label(__('Suppliers'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            ])->columns(2),
            Section::make(__('Requested items'))->schema([
                Repeater::make('lines')
                    ->minItems(1)
                    ->defaultItems(1)
                    ->schema([
                        Select::make('product_variant_id')
                            ->label(__('Product variant'))
                            ->searchable()
                            ->required()
                            ->options(fn (): array => ProductVariant::query()->where('is_active', true)->orderBy('sku')->pluck('sku', 'id')->all()),
                        Select::make('unit_id')
                            ->label(__('Unit'))
                            ->searchable()
                            ->required()
                            ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('quantity')->numeric()->minValue(0.000001)->required(),
                        Textarea::make('notes')->rows(2),
                    ])->columns(2),
            ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('RFQ summary'))->columns(3)->schema([
                TextEntry::make('rfq_number')->label(__('RFQ')),
                TextEntry::make('status')->badge(),
                TextEntry::make('currency_code')->label(__('Currency')),
                TextEntry::make('needed_by')->date()->placeholder('—'),
                TextEntry::make('closes_at')->dateTime()->placeholder('—'),
                TextEntry::make('requester.name')->label(__('Requested by')),
                TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Requested items'))->schema([
                RepeatableEntry::make('lines')->schema([
                    TextEntry::make('productVariant.sku')->label(__('SKU')),
                    TextEntry::make('productVariant.name')->label(__('Variant')),
                    TextEntry::make('quantity'),
                    TextEntry::make('unit.name')->label(__('Unit')),
                ])->columns(4),
            ]),
            Section::make(__('Supplier responses'))->schema([
                RepeatableEntry::make('suppliers')->schema([
                    TextEntry::make('supplier.name')->label(__('Supplier')),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('sent_at')->dateTime()->placeholder('—'),
                    TextEntry::make('responded_at')->dateTime()->placeholder('—'),
                    RepeatableEntry::make('responseLines')->schema([
                        TextEntry::make('rfqLine.productVariant.sku')->label(__('SKU')),
                        TextEntry::make('unit_price')->money(fn (PurchaseRfq $record): string => $record->currency_code),
                        TextEntry::make('offered_quantity')->label(__('Qty')),
                        TextEntry::make('lead_time_days')->label(__('Lead days'))->placeholder('—'),
                        TextEntry::make('minimum_order_quantity')->label(__('MOQ'))->placeholder('—'),
                    ])->columns(5),
                ])->columns(4),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('rfq_number')->label(__('RFQ'))->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('currency_code')->label(__('Currency')),
                TextColumn::make('needed_by')->date()->placeholder('—')->sortable(),
                TextColumn::make('suppliers_count')->counts('suppliers')->label(__('Suppliers'))->badge(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PurchaseRfqStatus::cases())->mapWithKeys(fn (PurchaseRfqStatus $status): array => [$status->value => str($status->value)->headline()->toString()])->all()),
            ]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['rfq_number', 'notes', 'suppliers.supplier.name'];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRfqs::route('/'),
            'create' => CreatePurchaseRfq::route('/create'),
            'view' => ViewPurchaseRfq::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'requester:id,name',
            'lines.productVariant:id,sku,name',
            'lines.unit:id,name',
            'suppliers.supplier:id,name',
            'suppliers.responseLines.rfqLine.productVariant:id,sku',
        ]);
    }
}
