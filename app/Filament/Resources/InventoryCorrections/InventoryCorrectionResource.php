<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrections;

use App\Enums\InventoryCorrectionStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryCorrections\Pages\ManageInventoryCorrections;
use App\Filament\Resources\InventoryCorrections\Pages\ViewInventoryCorrection;
use App\Filament\Resources\InventoryCorrections\RelationManagers\CorrectionLinesRelationManager;
use App\Models\InventoryCorrection;
use App\Models\InventoryOperation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class InventoryCorrectionResource extends Resource
{
    protected static ?string $model = InventoryCorrection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.corrections');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('original_inventory_operation_id')
                ->label(__('admin.inventory.correction.original_receipt'))
                ->options(fn (): array => InventoryOperation::query()
                    ->where('operation_type', OperationType::Receipt->value)
                    ->where('stage', OperationStage::Done->value)
                    ->orderByDesc('id')
                    ->limit(200)
                    ->pluck('operation_number', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            Textarea::make('reason')
                ->label(__('admin.inventory.correction.reason'))
                ->required()
                ->maxLength(2_000)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label(__('admin.inventory.correction.notes'))
                ->maxLength(2_000)
                ->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.inventory.correction.details'))->columns(3)->schema([
                TextEntry::make('correction_number')
                    ->label(__('admin.inventory.correction.number')),
                TextEntry::make('correction_type')
                    ->label(__('admin.inventory.correction.type'))
                    ->badge(),
                TextEntry::make('status')
                    ->label(__('admin.inventory.correction.status'))
                    ->badge(),
                TextEntry::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.correction.original_receipt')),
                TextEntry::make('createdBy.name')
                    ->label(__('admin.inventory.correction.created_by'))
                    ->placeholder('—'),
                TextEntry::make('posted_at')
                    ->label(__('admin.inventory.correction.posted_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('cancelled_at')
                    ->label(__('admin.inventory.correction.cancelled_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('reason')
                    ->label(__('admin.inventory.correction.reason'))
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->label(__('admin.inventory.correction.notes'))
                    ->columnSpanFull()
                    ->placeholder('—'),
                TextEntry::make('cancellation_reason')
                    ->label(__('admin.inventory.correction.cancellation_reason'))
                    ->columnSpanFull()
                    ->placeholder('—'),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('correction_number')
                    ->label(__('admin.inventory.correction.number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.inventory.correction.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.correction.original_receipt'))
                    ->searchable(),
                TextColumn::make('lines_count')
                    ->label(__('admin.inventory.correction.lines_count'))
                    ->counts('lines'),
                TextColumn::make('reason')
                    ->label(__('admin.inventory.correction.reason'))
                    ->limit(60),
                TextColumn::make('posted_at')
                    ->label(__('admin.inventory.correction.posted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.inventory.correction.status'))
                    ->options(collect(InventoryCorrectionStatus::cases())
                        ->mapWithKeys(fn (InventoryCorrectionStatus $status): array => [
                            $status->value => $status->name,
                        ])
                        ->all()),
            ])
            ->recordUrl(
                fn (InventoryCorrection $record): string => self::getUrl('view', ['record' => $record]),
            );
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            CorrectionLinesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageInventoryCorrections::route('/'),
            'view' => ViewInventoryCorrection::route('/{record}'),
        ];
    }
}
