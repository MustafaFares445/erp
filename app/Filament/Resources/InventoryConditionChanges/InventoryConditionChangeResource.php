<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges;

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\QuarantineDisposition;
use App\Filament\LocalizedResource as Resource;
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

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('admin.inventory.condition_change.fields.document_type'))
                ->options([
                    InventoryConditionChangeType::QuarantineDisposition->value => __('admin.inventory.condition_change.types.quarantine_disposition'),
                    InventoryConditionChangeType::Damage->value => __('admin.inventory.condition_change.types.damage'),
                    InventoryConditionChangeType::DamageRecovery->value => __('admin.inventory.condition_change.types.damage_recovery'),
                    InventoryConditionChangeType::Disposal->value => __('admin.inventory.condition_change.types.disposal'),
                ])
                ->default(function (): string {
                    $type = InventoryConditionChangeType::tryFrom((string) request()->query('type'));

                    return $type instanceof InventoryConditionChangeType
                        ? $type->value
                        : InventoryConditionChangeType::QuarantineDisposition->value;
                })
                ->live()
                ->required(),
            Section::make(__('admin.inventory.condition_change.sections.stock_identity'))
                ->description(__('admin.inventory.condition_change.descriptions.stock_identity'))
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('type') !== InventoryConditionChangeType::DamageRecovery->value)
                ->schema([
                    Select::make('product_variant_id')
                        ->label(__('admin.inventory.condition_change.fields.product_variant'))
                        ->relationship('productVariant', 'name')
                        ->searchable(['name', 'sku'])
                        ->preload()
                        ->default(fn (): ?int => request()->integer('product_variant_id') ?: null)
                        ->required(),
                    Select::make('warehouse_id')
                        ->label(__('admin.inventory.condition_change.fields.warehouse'))
                        ->relationship('warehouse', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn (): ?int => request()->integer('warehouse_id') ?: null)
                        ->required(),
                    Select::make('inventory_lot_id')
                        ->label(__('admin.inventory.condition_change.fields.lot'))
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
                        ->label(__('admin.inventory.condition_change.fields.serialized_unit'))
                        ->options(fn (): array => SerializedInventoryUnit::query()
                            ->orderBy('serial_number')
                            ->limit(500)
                            ->pluck('serial_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                ]),
            Section::make(__('admin.inventory.condition_change.sections.recovery_reversal'))
                ->description(__('admin.inventory.condition_change.descriptions.recovery_reversal'))
                ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::DamageRecovery->value)
                ->schema([
                    Select::make('reverses_condition_change_id')
                        ->label(__('admin.inventory.condition_change.fields.damage_document'))
                        ->options(fn (): array => InventoryConditionChange::query()
                            ->where('type', InventoryConditionChangeType::Damage)
                            ->where('status', InventoryConditionChangeStatus::Posted)
                            ->orderByDesc('id')
                            ->limit(500)
                            ->pluck('document_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::DamageRecovery->value),
                ]),
            Section::make(__('admin.inventory.condition_change.sections.disposal_authorisation'))
                ->description(__('admin.inventory.condition_change.descriptions.disposal_authorisation'))
                ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::Disposal->value)
                ->schema([
                    Select::make('authorised_by')
                        ->label(__('admin.inventory.condition_change.fields.authorised_by'))
                        ->options(fn (): array => User::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::Disposal->value),
                ]),
            Section::make(__('admin.inventory.condition_change.sections.quantity_reason'))
                ->columns(2)
                ->schema([
                    TextInput::make('base_quantity')
                        ->label(__('admin.inventory.condition_change.fields.base_quantity'))
                        ->default(fn (): ?string => request()->query('base_quantity'))
                        ->numeric()
                        ->minValue(0.000001)
                        ->required(),
                    Select::make('disposition')
                        ->options([
                            QuarantineDisposition::ReleaseToSaleable->value => __('admin.inventory.condition_change.dispositions.release_to_saleable'),
                            QuarantineDisposition::DowngradeToDamaged->value => __('admin.inventory.condition_change.dispositions.downgrade_to_damaged'),
                            QuarantineDisposition::Dispose->value => __('admin.inventory.condition_change.dispositions.dispose'),
                            QuarantineDisposition::ReturnToSupplier->value => __('admin.inventory.condition_change.dispositions.return_to_supplier'),
                        ])
                        ->visible(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::QuarantineDisposition->value)
                        ->required(fn (Get $get): bool => $get('type') === InventoryConditionChangeType::QuarantineDisposition->value),
                    Select::make('reason_category')
                        ->label(__('admin.inventory.condition_change.fields.reason_category'))
                        ->options(collect(ConditionChangeReason::cases())
                            ->mapWithKeys(fn (ConditionChangeReason $reason): array => [
                                $reason->value => __('admin.inventory.condition_change.reasons.'.$reason->value),
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
            Section::make(__('admin.inventory.condition_change.sections.details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('document_number')->label(__('admin.inventory.condition_change.fields.document')),
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('disposition')->badge()->placeholder(__('—')),
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.condition_change.fields.sku')),
                    TextEntry::make('productVariant.name')->label(__('admin.inventory.condition_change.fields.variant')),
                    TextEntry::make('warehouse.name')->label(__('admin.inventory.condition_change.fields.warehouse')),
                    TextEntry::make('lot.lot_number')->label(__('admin.inventory.condition_change.fields.lot'))->placeholder(__('—')),
                    TextEntry::make('serializedUnit.serial_number')->label(__('admin.inventory.condition_change.fields.serial'))->placeholder(__('—')),
                    TextEntry::make('base_quantity')->label(__('admin.inventory.condition_change.fields.quantity'))->numeric(decimalPlaces: 6),
                    TextEntry::make('condition_from')->label(__('admin.inventory.condition_change.fields.from'))->badge(),
                    TextEntry::make('condition_to')->label(__('admin.inventory.condition_change.fields.to'))->badge(),
                    TextEntry::make('reason_category')->label(__('admin.inventory.condition_change.fields.reason_category'))->badge(),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('inspector.name')->label(__('admin.inventory.condition_change.fields.inspected_by'))->placeholder(__('—')),
                    TextEntry::make('inspected_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('postedBy.name')->label(__('admin.inventory.condition_change.fields.posted_by'))->placeholder(__('—')),
                    TextEntry::make('posted_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('inventory_movement_id')->label(__('admin.inventory.condition_change.fields.movement'))->placeholder(__('—')),
                    TextEntry::make('supplier_return_id')->label(__('admin.inventory.condition_change.fields.supplier_return'))->placeholder(__('—')),
                    TextEntry::make('reversesConditionChange.document_number')
                        ->label(__('admin.inventory.condition_change.fields.reverses_damage'))
                        ->placeholder(__('—')),
                    TextEntry::make('authorisedBy.name')->label(__('admin.inventory.condition_change.fields.authorised_by'))->placeholder(__('—')),
                    TextEntry::make('authorised_at')->dateTime()->placeholder(__('—')),
                ]),
            Section::make(__('admin.inventory.condition_change.sections.recoveries'))
                ->visible(fn (InventoryConditionChange $record): bool => $record->type === InventoryConditionChangeType::Damage)
                ->schema([
                    RepeatableEntry::make('reversals')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('document_number')->label(__('admin.inventory.condition_change.fields.document')),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('base_quantity')->label(__('admin.inventory.condition_change.fields.quantity'))->numeric(decimalPlaces: 6),
                        ])
                        ->columns(3)
                        ->placeholder(__('admin.inventory.condition_change.no_recoveries')),
                ]),
            Section::make(__('admin.inventory.condition_change.sections.evidence'))
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
                            TextEntry::make('file_name')->label(__('admin.inventory.condition_change.fields.file')),
                            TextEntry::make('size')->label(__('admin.inventory.condition_change.fields.size')),
                        ])
                        ->columns(2)
                        ->placeholder(__('admin.inventory.condition_change.no_evidence')),
                ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('document_number')->label(__('admin.inventory.condition_change.fields.document'))->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('productVariant.sku')->label(__('admin.inventory.condition_change.fields.sku'))->searchable(),
                TextColumn::make('warehouse.name')->label(__('admin.inventory.condition_change.fields.warehouse'))->searchable(),
                TextColumn::make('base_quantity')->label(__('admin.inventory.condition_change.fields.quantity'))->numeric(decimalPlaces: 6),
                TextColumn::make('disposition')->badge()->placeholder(__('—')),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(InventoryConditionChangeType::cases())
                        ->mapWithKeys(fn (InventoryConditionChangeType $type): array => [
                            $type->value => __('admin.inventory.condition_change.types.'.$type->value),
                        ])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(InventoryConditionChangeStatus::cases())
                        ->mapWithKeys(fn (InventoryConditionChangeStatus $status): array => [
                            $status->value => $status->label(),
                        ])
                        ->all()),
                SelectFilter::make('disposition')
                    ->options(collect(QuarantineDisposition::cases())
                        ->mapWithKeys(fn (QuarantineDisposition $disposition): array => [
                            $disposition->value => __('admin.inventory.condition_change.dispositions.'.$disposition->value),
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
