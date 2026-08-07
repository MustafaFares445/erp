<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class InventoryOperationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('operation')
                ->tabs([
                    Tab::make(__('admin.inventory.operation.fields.operation_number'))
                        ->schema([
                            Section::make()
                                ->description(fn (): string => self::referenceDescription())
                                ->columns(2)
                                ->schema([
                                    Select::make('operation_type')->options([
                                        'receipt' => __('admin.inventory.operation.types.receipt'),
                                        'delivery' => __('admin.inventory.operation.types.delivery'),
                                        'internal_transfer' => __('admin.inventory.operation.types.internal_transfer'),
                                    ])->required()
                                        ->default(self::forcedOperationType()?->value)
                                        ->live()
                                        ->disabled(fn (?InventoryOperation $record): bool => $record instanceof InventoryOperation || self::forcedOperationType() instanceof OperationType)
                                        ->dehydrated()
                                        ->placeholder(__('admin.inventory.operation.placeholders.operation_type'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.operation_type')),
                                    Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload()
                                        ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Receipt))
                                        ->placeholder(__('admin.inventory.operation.placeholders.supplier'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.supplier')),
                                    Select::make('customer_id')->relationship('customer', 'company_name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))->searchable()->preload()
                                        ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery))
                                        ->required(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery))
                                        ->placeholder(__('admin.inventory.operation.placeholders.customer'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.customer')),
                                    Select::make('delivery_type')
                                        ->label(__('admin.inventory.operation.fields.delivery_type'))
                                        ->options(collect(DeliveryType::cases())->mapWithKeys(fn (DeliveryType $type): array => [$type->value => $type->label()])->all())
                                        ->default(DeliveryType::Inner->value)
                                        ->required(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery))
                                        ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery))
                                        ->placeholder(__('admin.inventory.operation.placeholders.delivery_type'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.delivery_type')),
                                    Select::make('source_warehouse_id')->relationship('sourceWarehouse', 'name')->searchable()->preload()
                                        ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery) || self::isType($get('operation_type'), OperationType::InternalTransfer))
                                        ->required(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery) || self::isType($get('operation_type'), OperationType::InternalTransfer))
                                        ->placeholder(__('admin.inventory.operation.placeholders.source_warehouse'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.source_warehouse')),
                                    Select::make('destination_warehouse_id')->relationship('destinationWarehouse', 'name')->searchable()->preload()
                                        ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Receipt) || self::isType($get('operation_type'), OperationType::InternalTransfer))
                                        ->required(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Receipt) || self::isType($get('operation_type'), OperationType::InternalTransfer))
                                        ->placeholder(__('admin.inventory.operation.placeholders.destination_warehouse'))
                                        ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.destination_warehouse')),
                                ]),
                        ]),
                    Tab::make(__('admin.sections.operations'))
                        ->schema([
                            Section::make()
                                ->description(fn (): string => self::operationsDescription())
                                ->schema([
                                    OperationStageBar::make(),
                                    OperationLinesRepeater::make(),
                                ]),
                        ]),
                    Tab::make(__('admin.inventory.operation.fields.scheduled_at'))
                        ->schema([
                            Section::make()
                                ->description(__('admin.inventory.operation.descriptions.scheduled_at'))
                                ->schema([
                                    DateTimePicker::make('scheduled_at')->placeholder(__('admin.inventory.operation.placeholders.scheduled_at')),
                                    Select::make('responsible_id')->relationship('responsible', 'name')->searchable()->preload()->placeholder(__('admin.inventory.operation.placeholders.responsible')),
                                    TextInput::make('supplier_reference')->maxLength(100)->placeholder(__('admin.inventory.operation.placeholders.supplier_reference')),
                                ]),
                        ]),
                    Tab::make(__('admin.inventory.operation.fields.notes'))
                        ->schema([
                            Section::make()
                                ->description(__('admin.inventory.operation.descriptions.notes'))
                                ->schema([
                                    Textarea::make('notes')->columnSpanFull()->maxLength(5000)->placeholder(__('admin.inventory.operation.placeholders.notes')),
                                ]),
                        ]),
                    Tab::make(__('admin.inventory.operation.fields.delivery_documents'))
                        ->schema([
                            Section::make(__('admin.inventory.operation.sections.delivery_documents'))
                                ->description(__('admin.inventory.operation.descriptions.delivery_documents'))
                                ->columns(2)
                                ->visible(fn (Get $get): bool => self::isType($get('operation_type'), OperationType::Delivery))
                                ->schema(array_map(
                                    self::deliveryDocumentUpload(...),
                                    DeliveryDocument::cases(),
                                )),
                        ]),
                ])
                ->columnSpanFull(),
        ])->disabled(fn (?InventoryOperation $record): bool => $record?->isDraft() === false);
    }

    private static function forcedOperationType(): ?OperationType
    {
        $value = request()->query('operation_type');

        return is_string($value) ? OperationType::tryFrom($value) : null;
    }

    private static function isType(mixed $value, OperationType $type): bool
    {
        return $value === $type || $value === $type->value;
    }

    private static function referenceDescription(): string
    {
        return match (self::forcedOperationType()) {
            OperationType::Receipt => __('admin.inventory.operation.descriptions.receipt_reference'),
            OperationType::Delivery => __('admin.inventory.operation.descriptions.delivery_reference'),
            OperationType::InternalTransfer => __('admin.inventory.operation.descriptions.transfer_reference'),
            null => __('admin.inventory.operation.descriptions.reference'),
        };
    }

    private static function operationsDescription(): string
    {
        return match (self::forcedOperationType()) {
            OperationType::Receipt => __('admin.inventory.operation.descriptions.receipt_operations'),
            OperationType::Delivery => __('admin.inventory.operation.descriptions.delivery_operations'),
            OperationType::InternalTransfer => __('admin.inventory.operation.descriptions.transfer_operations'),
            null => __('admin.inventory.operation.descriptions.operations'),
        };
    }

    private static function deliveryDocumentUpload(DeliveryDocument $document): FileUpload
    {
        return FileUpload::make($document->value)
            ->label($document->label())
            ->disk('local')
            ->directory('delivery-documents/'.$document->value)
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->preventFilePathTampering(
                allowFilePathUsing: static function (?InventoryOperation $record, string $file) use ($document): bool {
                    if (! $record instanceof InventoryOperation) {
                        return false;
                    }

                    return $record->getFirstMedia($document->value)?->getPathRelativeToRoot() === $file;
                },
            )
            ->afterStateHydrated(static function (FileUpload $component, ?InventoryOperation $record) use ($document): void {
                if (! $record instanceof InventoryOperation) {
                    return;
                }

                $media = $record->getFirstMedia($document->value);

                $component->state($media instanceof Media ? [$media->getPathRelativeToRoot()] : []);
            });
    }
}
