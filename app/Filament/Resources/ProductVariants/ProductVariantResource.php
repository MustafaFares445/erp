<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants;

use App\Enums\ProductStatus;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.product_variants');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('product_id')->relationship('product', 'name')->required()->searchable()->preload(),
                Select::make('unit_id')->relationship('unit', 'name')->searchable()->preload(),
                TextInput::make('sku')->required()->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('barcode')->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
                Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required(),
                Toggle::make('track_serials'),
                Toggle::make('track_expiry'),
            ]),
            Section::make('Pricing')->columns(2)->schema([
                TextInput::make('cost_price')->numeric()->minValue(0)->step(0.01),
                TextInput::make('markup_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01),
                TextInput::make('base_price')->numeric()->minValue(0)->step(0.01),
                TextInput::make('min_price')->numeric()->minValue(0)->step(0.01),
            ]),
            Repeater::make('attributeAssignments')
                ->relationship()
                ->schema([
                    Select::make('product_attribute_value_id')->relationship('attributeValue', 'value')->required()->searchable()->preload(),
                ])
                ->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('product.name')->searchable()->sortable(),
                TextColumn::make('unit.symbol')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('base_price')->money('USD')->sortable(),
                IconColumn::make('track_serials')->boolean(),
                IconColumn::make('track_expiry')->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                TernaryFilter::make('track_serials'),
                TernaryFilter::make('track_expiry'),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->name])->all();
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageProductVariants::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
