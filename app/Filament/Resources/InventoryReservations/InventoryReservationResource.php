<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations;

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Filament\Resources\InventoryReservations\Tables\InventoryReservationsTable;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\Quotation;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Models\InventoryReservation;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class InventoryReservationResource extends Resource
{
    protected static ?string $model = InventoryReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 303;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.reservations');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reservation')
                ->columns(3)
                ->schema([
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.name')->label('Variant'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('base_quantity')->label('Base quantity')->numeric(decimalPlaces: 6),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('expires_at')->dateTime()->placeholder('No expiry'),
                    TextEntry::make('source_document')
                        ->label('Source document')
                        ->state(fn (InventoryReservation $record): string => self::sourceDocumentLabel($record))
                        ->url(fn (InventoryReservation $record): ?string => self::sourceDocumentUrl($record)),
                    TextEntry::make('releasedBy.name')->label('Released by')->placeholder('—'),
                    TextEntry::make('released_at')->dateTime()->placeholder('—'),
                    TextEntry::make('release_reason')->label('Release reason')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Allocations')
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('lot.lot_number')->label('Lot')->placeholder('—'),
                            TextEntry::make('serializedUnit.serial_number')->label('Serial')->placeholder('—'),
                            TextEntry::make('base_quantity')->label('Base quantity')->numeric(decimalPlaces: 6),
                        ]),
                ]),
            Section::make('Lifecycle evidence')
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('Reserved at')->dateTime(),
                    TextEntry::make('consumed_at')->label('Consumed at')->dateTime()->placeholder('—'),
                    TextEntry::make('released_at')->label('Released / expired at')->dateTime()->placeholder('—'),
                    TextEntry::make('createdBy.name')->label('Created by')->placeholder('System'),
                    TextEntry::make('updatedBy.name')->label('Last updated by')->placeholder('System'),
                ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InventoryReservationsTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'productVariant',
                'warehouse',
                'releasedBy',
                'createdBy',
                'updatedBy',
                'sourceOperation.sourceDocument',
                'allocations.lot',
                'allocations.serializedUnit',
            ])
            ->withCount('allocations')
            ->latest();
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryReservations::route('/'),
            'view' => ViewInventoryReservation::route('/{record}'),
        ];
    }

    public static function sourceDocumentLabel(InventoryReservation $reservation): string
    {
        $document = $reservation->resolvedSourceDocument();

        return match (true) {
            $document instanceof Order => (string) $document->order_number,
            $document instanceof Quotation => (string) $document->quotation_number,
            $document instanceof InventoryOperation => (string) ($document->operation_number ?? 'Operation #'.$document->getKey()),
            default => sprintf('%s #%d', $reservation->source_type, $reservation->source_id),
        };
    }

    public static function sourceDocumentUrl(InventoryReservation $reservation): ?string
    {
        $document = $reservation->resolvedSourceDocument();

        return match (true) {
            $document instanceof Order => OrderResource::getUrl('view', ['record' => $document]),
            $document instanceof Quotation => QuotationResource::getUrl('view', ['record' => $document]),
            $document instanceof InventoryOperation => InventoryOperationResource::getUrl('view', ['record' => $document]),
            default => null,
        };
    }
}
