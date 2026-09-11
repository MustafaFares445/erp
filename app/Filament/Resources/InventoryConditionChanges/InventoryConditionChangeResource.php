<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges;

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\QuarantineDisposition;
use App\Filament\Resources\InventoryConditionChanges\Pages\CreateInventoryConditionChange;
use App\Filament\Resources\InventoryConditionChanges\Pages\ListInventoryConditionChanges;
use App\Filament\Resources\InventoryConditionChanges\Pages\ViewInventoryConditionChange;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use UnitEnum;

final class InventoryConditionChangeResource extends Resource
{
    protected static ?string $model = InventoryConditionChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 306;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_condition_changes');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'productVariant:id,sku,name',
            'warehouse:id,code,name',
            'lot:id,lot_number',
            'serializedUnit:id,serial_number',
            'inspector:id,name',
            'postedBy:id,name',
            'createdBy:id,name',
            'reversesConditionChange:id,document_number',
            'authorisedBy:id,name',
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    public static function recoveryDamageDocumentOptions(?int $productVariantId = null, ?int $warehouseId = null): array
    {
        return InventoryConditionChange::query()
            ->where('type', InventoryConditionChangeType::Damage)
            ->where('status', InventoryConditionChangeStatus::Posted)
            ->when(
                $productVariantId !== null,
                fn (Builder $query): Builder => $query->where('product_variant_id', $productVariantId),
            )
            ->when(
                $warehouseId !== null,
                fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId),
            )
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('document_number', 'id')
            ->all();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Document type')
                ->options([
                    InventoryConditionChangeType::QuarantineDisposition->value => 'Quarantine disposition',
                    InventoryConditionChangeType::Damage->value => 'Damage',
                    InventoryConditionChangeType::DamageRecovery->value => 'Damage recovery',
                    InventoryConditionChangeType::Disposal->value => 'Disposal',
                ])
                ->default(function (): string {
                    $type = InventoryConditionChangeType::tryFrom((string) request()->query('type'));

                    return $type instanceof InventoryConditionChangeType
                        ? $type->value
                        : InventoryConditionChangeType::QuarantineDisposition->value;
                })
                ->live()
                ->required(),
            Section::make('Stock identity')
                ->description('Identify the affected stock. A damage recovery inherits its identity from the damage document it reverses.')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('type') !== InventoryConditionChangeType::DamageRecovery->value)
                ->schema([
                    Select::make('product_variant_id')
                        ->label('Product variant')
                        ->relationship('productVariant', 'name')
                        ->searchable(['name', 'sku'])
                        ->preload()
                        ->default(fn (): ?int => request()->integer('product_variant_id') ?: null)
                        ->required(),
                    Select::make('warehouse_id')
                        ->label('Warehouse')
                        ->relationship('warehouse', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn (): ?int => request()->integer('warehouse_id') ?: null)
                        ->required(),
                    Select::make('inventory_lot_id')
                        ->label('Lot')
                        ->options(fn (): array => InventoryLot::query()
                            ->canonical()
                            ->orderBy('lot_number')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn (InventoryLot $lot): array => [
                                self::lotKey($lot) => $lot->lot_number ?? 'Lot #'.self::lotKey($lot),
                            ])
                            ->all())
                        ->searchable()
                        ->preload(),
                    Select::make('serialized_inventory_unit_id')
                        ->label('Serialized unit')
                        ->options(fn (): array => SerializedInventoryUnit::query()
                            ->orderBy('serial_number')
                            ->limit(500)
                            ->pluck('serial_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                ]),
            Section::make('Recovery reversal')
                ->description('A recovery must name the posted damage document it reverses; its quantity is capped at what remains unrecovered.')
                ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::DamageRecovery->value)
                ->schema([
                    Select::make('reverses_condition_change_id')
                        ->label('Damage document')
                        ->options(fn (): array => self::recoveryDamageDocumentOptions(
                            request()->integer('product_variant_id') ?: null,
                            request()->integer('warehouse_id') ?: null,
                        ))
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::DamageRecovery->value),
                ]),
            Section::make('Disposal authorisation')
                ->description('Disposal requires an authoriser distinct from the document creator; attach evidence after the draft is created.')
                ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::Disposal->value)
                ->schema([
                    Select::make('authorised_by')
                        ->label('Authorised by')
                        ->options(fn (): array => User::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::Disposal->value),
                ]),
            Section::make('Quantity and reason')
                ->columns(2)
                ->schema([
                    TextInput::make('base_quantity')
                        ->label('Base quantity')
                        ->default(fn (): ?string => request()->query('base_quantity'))
                        ->numeric()
                        ->minValue(0.000001)
                        ->required(),
                    Select::make('disposition')
                        ->options([
                            QuarantineDisposition::ReleaseToSaleable->value => 'Release to saleable',
                            QuarantineDisposition::DowngradeToDamaged->value => 'Downgrade to damaged',
                            QuarantineDisposition::Dispose->value => 'Dispose',
                            QuarantineDisposition::ReturnToSupplier->value => 'Return to supplier',
                        ])
                        ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::QuarantineDisposition->value)
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::QuarantineDisposition->value),
                    Select::make('reason_category')
                        ->label('Reason category')
                        ->options(collect(ConditionChangeReason::cases())
                            ->mapWithKeys(fn (ConditionChangeReason $reason): array => [
                                $reason->value => str($reason->name)->headline()->toString(),
                            ])
                            ->all())
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2_000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Condition change')
                ->columns(3)
                ->schema([
                    TextEntry::make('document_number')->label('Document'),
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('disposition')->badge()->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.name')->label('Variant'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('lot.lot_number')->label('Lot')->placeholder('—'),
                    TextEntry::make('serializedUnit.serial_number')->label('Serial')->placeholder('—'),
                    TextEntry::make('base_quantity')->label('Quantity')->numeric(decimalPlaces: 6),
                    TextEntry::make('condition_from')->label('From')->badge(),
                    TextEntry::make('condition_to')->label('To')->badge(),
                    TextEntry::make('reason_category')->label('Reason category')->badge(),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('inspector.name')->label('Inspected by')->placeholder('—'),
                    TextEntry::make('inspected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('postedBy.name')->label('Posted by')->placeholder('—'),
                    TextEntry::make('posted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('inventory_movement_id')->label('Movement')->placeholder('—'),
                    TextEntry::make('supplier_return_id')->label('Supplier return')->placeholder('—'),
                    TextEntry::make('reversesConditionChange.document_number')
                        ->label('Reverses damage document')
                        ->placeholder('—'),
                    TextEntry::make('authorisedBy.name')->label('Authorised by')->placeholder('—'),
                    TextEntry::make('authorised_at')->dateTime()->placeholder('—'),
                ]),
            Section::make('Recoveries against this damage')
                ->visible(fn (InventoryConditionChange $record): bool => $record->type === InventoryConditionChangeType::Damage)
                ->schema([
                    RepeatableEntry::make('reversals')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('document_number')->label('Document'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('base_quantity')->label('Quantity')->numeric(decimalPlaces: 6),
                        ])
                        ->columns(3)
                        ->placeholder('No recoveries have been drafted against this damage document yet.'),
                ]),
            Section::make('Disposal evidence')
                ->visible(fn (InventoryConditionChange $record): bool => $record->type === InventoryConditionChangeType::Disposal)
                ->schema([
                    RepeatableEntry::make('evidence')
                        ->hiddenLabel()
                        ->state(static fn (InventoryConditionChange $record): array => $record->getMedia('disposal-evidence')
                            ->map(static fn (Media $media): array => [
                                'file_name' => $media->file_name,
                                'size' => round($media->size / 1024, 1).' KB',
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('file_name')->label('File'),
                            TextEntry::make('size')->label('Size'),
                        ])
                        ->columns(2)
                        ->placeholder('No evidence has been attached yet.'),
                ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('document_number')->label('Document')->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable(),
                TextColumn::make('base_quantity')->label('Quantity')->numeric(decimalPlaces: 6),
                TextColumn::make('disposition')->badge()->placeholder('—'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(InventoryConditionChangeType::cases())
                        ->mapWithKeys(fn (InventoryConditionChangeType $type): array => [
                            $type->value => str($type->name)->headline()->toString(),
                        ])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(InventoryConditionChangeStatus::cases())
                        ->mapWithKeys(fn (InventoryConditionChangeStatus $status): array => [
                            $status->value => str($status->name)->headline()->toString(),
                        ])
                        ->all()),
                SelectFilter::make('disposition')
                    ->options(collect(QuarantineDisposition::cases())
                        ->mapWithKeys(fn (QuarantineDisposition $disposition): array => [
                            $disposition->value => str($disposition->name)->headline()->toString(),
                        ])
                        ->all()),
            ])
            ->recordUrl(fn (InventoryConditionChange $record): string => self::getUrl('view', ['record' => $record]));
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryConditionChanges::route('/'),
            'create' => CreateInventoryConditionChange::route('/create'),
            'view' => ViewInventoryConditionChange::route('/{record}'),
        ];
    }

    private static function lotKey(InventoryLot $lot): int
    {
        $key = $lot->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory lot identifiers must be integers.');
        }

        return $key;
    }
}
