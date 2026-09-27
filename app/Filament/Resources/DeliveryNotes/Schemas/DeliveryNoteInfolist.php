<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Filament\Resources\InventoryOperations\Schemas\DeliveryRelatedDocuments;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Unit;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

final class DeliveryNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextEntry::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number')),
                    TextEntry::make('stage')->badge()->formatStateUsing(fn (mixed $state, InventoryOperation $record): string => $record->stageLabel()),
                    TextEntry::make('customer.company_name')->label(__('admin.inventory.operation.fields.customer')),
                    TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse')),
                    TextEntry::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime(),
                    TextEntry::make('notes')->label(__('admin.inventory.operation.fields.notes'))->columnSpanFull(),
                ]),
                Section::make(__('admin.sections.operations'))->gridContainer()->schema([
                    RepeatableEntry::make('lines')->label('')->columns([
                        'default' => 1,
                        '@sm' => 2,
                        '@lg' => 4,
                    ])->schema([
                        TextEntry::make('productVariant.sku')
                            ->label(__('admin.inventory.operation.fields.product'))
                            ->tooltip(fn (?string $state): ?string => $state)
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(2),
                        TextEntry::make('quantity')
                            ->label(__('admin.inventory.operation.fields.quantity'))
                            ->state(static function (InventoryOperationLine $record): string {
                                $unit = $record->unit;

                                return mb_trim(QuantityFormatter::display($record->quantity).' '.($unit instanceof Unit ? $unit->name : ''));
                            })
                            ->columnSpan(1),
                        TextEntry::make('is_picked')
                            ->label(__('admin.inventory.operation.fields.warehouse_preparation'))
                            ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.operation.help.picked'))
                            ->formatStateUsing(fn (bool $state): string => $state
                                ? __('admin.inventory.operation.values.prepared')
                                : __('admin.inventory.operation.values.not_prepared'))
                            ->badge()
                            ->color(fn (InventoryOperationLine $record): string => $record->is_picked ? 'success' : 'gray')
                            ->columnSpan(1),
                    ]),
                ]),
                Section::make(__('admin.inventory.operation.sections.related_documents'))
                    ->schema(DeliveryRelatedDocuments::make())
                    ->gridContainer()
                    ->columns([
                        'default' => 1,
                        '@lg' => 2,
                    ]),
            ]);
    }
}
