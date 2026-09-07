<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts;

use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryCounts\Pages\CreateInventoryCount;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Filament\Resources\InventoryCounts\RelationManagers\InventoryCountLinesRelationManager;
use App\Filament\Resources\InventoryCounts\Schemas\InventoryCountInfolist;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCount;
use App\Models\ProductCategory;
use App\Services\Inventory\InventoryCountService;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The physical count worksheet document (WP-3.5, GAP-MW-06). Confirming a
 * count produces at most one {@see InventoryAdjustment} through
 * {@see InventoryCountService} — this resource never
 * posts stock directly.
 */
final class InventoryCountResource extends Resource
{
    protected static ?string $model = InventoryCount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 307;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_counts');
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(InventoryPermission::CountView->value) ?? false;
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'warehouse:id,code,name',
            'productCategory:id,name',
            'lot:id,lot_number',
            'counter:id,name',
            'confirmedBy:id,name',
            'createdBy:id,name',
        ]);
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scope')
                ->columns(2)
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Warehouse')
                        ->relationship('warehouse', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('scope_type')
                        ->label('Scope')
                        ->options(collect(CountScope::cases())
                            ->mapWithKeys(fn (CountScope $scope): array => [$scope->value => str($scope->value)->headline()->toString()])
                            ->all())
                        ->default(CountScope::Warehouse->value)
                        ->live()
                        ->required(),
                    Select::make('product_category_id')
                        ->label('Product category')
                        ->options(fn (): array => ProductCategory::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => $get('scope_type') === CountScope::Category->value)
                        ->required(fn (Get $get): bool => $get('scope_type') === CountScope::Category->value),
                    Select::make('inventory_lot_id')
                        ->label('Lot')
                        ->relationship('lot', 'lot_number')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => $get('scope_type') === CountScope::Lot->value)
                        ->required(fn (Get $get): bool => $get('scope_type') === CountScope::Lot->value),
                    CheckboxList::make('conditions')
                        ->label('Stock conditions in scope')
                        ->options(collect(StockCondition::cases())
                            ->filter(fn (StockCondition $condition): bool => $condition->isMaterialized())
                            ->mapWithKeys(fn (StockCondition $condition): array => [$condition->value => str($condition->value)->headline()->toString()])
                            ->all())
                        ->helperText('Leave every box unchecked to count every materialized condition.')
                        ->columnSpanFull(),
                    TextInput::make('materiality_threshold_minor')
                        ->label('Materiality threshold (minor units)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('A variance above this value auto-flags its line for recount. Leave blank to disable the escalation.'),
                ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return InventoryCountInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('count_number')->label('Count')->searchable()->sortable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable(),
                TextColumn::make('scope_type')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('lines_count')->label('Lines')->counts('lines'),
                TextColumn::make('counter.name')->label('Counted by')->placeholder('—'),
                TextColumn::make('confirmedBy.name')->label('Confirmed by')->placeholder('—'),
                TextColumn::make('opened_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InventoryCountStatus::cases())
                        ->mapWithKeys(fn (InventoryCountStatus $status): array => [$status->value => str($status->value)->headline()->toString()])
                        ->all()),
                SelectFilter::make('scope_type')
                    ->label('Scope')
                    ->options(collect(CountScope::cases())
                        ->mapWithKeys(fn (CountScope $scope): array => [$scope->value => str($scope->value)->headline()->toString()])
                        ->all()),
            ])
            ->recordUrl(fn (InventoryCount $record): string => self::getUrl('view', ['record' => $record]));
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [InventoryCountLinesRelationManager::class];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryCounts::route('/'),
            'create' => CreateInventoryCount::route('/create'),
            'view' => ViewInventoryCount::route('/{record}'),
        ];
    }
}
