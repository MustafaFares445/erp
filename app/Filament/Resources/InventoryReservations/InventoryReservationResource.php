<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Filament\Resources\InventoryReservations\Tables\InventoryReservationsTable;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Quotation;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
            Section::make(__('admin.inventory.reservation.sections.reservation'))
                ->columns(3)
                ->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.reservation.fields.sku')),
                    TextEntry::make('productVariant.name')->label(__('admin.inventory.reservation.fields.variant')),
                    TextEntry::make('warehouse.name')->label(__('admin.inventory.reservation.fields.warehouse')),
                    TextEntry::make('salesOrder.order_number')
                        ->label(__('Sales Order'))
                        ->placeholder(__('Not a customer-order reservation'))
                        ->url(fn (InventoryReservation $record): ?string => $record->salesOrder !== null
                            ? OrderResource::getUrl('view', ['record' => $record->salesOrder])
                            : null),
                    TextEntry::make('salesOrderLine.id')->label(__('Sales Order line'))->placeholder(__('—')),
                    TextEntry::make('base_quantity')->label(__('admin.inventory.reservation.fields.base_quantity'))->numeric(decimalPlaces: 6),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('expires_at')->dateTime()->placeholder(__('admin.inventory.reservation.no_expiry')),
                    TextEntry::make('source_document')
                        ->label(__('admin.inventory.reservation.fields.source_document'))
                        ->state(fn (InventoryReservation $record): string => self::sourceDocumentLabel($record))
                        ->url(fn (InventoryReservation $record): ?string => self::sourceDocumentUrl($record)),
                    TextEntry::make('releasedBy.name')->label(__('admin.inventory.reservation.fields.released_by'))->placeholder(__('—')),
                    TextEntry::make('released_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('release_reason')->label(__('admin.inventory.reservation.release_reason'))->placeholder(__('—'))->columnSpanFull(),
                ]),
            Section::make(__('admin.inventory.reservation.sections.allocations'))
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('lot.lot_number')->label(__('admin.inventory.reservation.fields.lot'))->placeholder(__('—')),
                            TextEntry::make('serializedUnit.serial_number')->label(__('admin.inventory.reservation.fields.serial'))->placeholder(__('—')),
                            TextEntry::make('base_quantity')->label(__('admin.inventory.reservation.fields.base_quantity'))->numeric(decimalPlaces: 6),
                        ]),
                ]),
            Section::make(__('admin.inventory.reservation.sections.lifecycle'))
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label(__('admin.inventory.reservation.fields.reserved_at'))->dateTime(),
                    TextEntry::make('consumed_at')->label(__('admin.inventory.reservation.fields.consumed_at'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('released_at')->label(__('admin.inventory.reservation.fields.released_or_expired_at'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('createdBy.name')->label(__('admin.inventory.reservation.fields.created_by'))->placeholder(__('admin.inventory.reservation.system')),
                    TextEntry::make('updatedBy.name')->label(__('admin.inventory.reservation.fields.updated_by'))->placeholder(__('admin.inventory.reservation.system')),
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
                'salesOrder',
                'salesOrderLine',
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
            $document instanceof InventoryOperation => (string) ($document->operation_number ?? 'Operation #'.$document->id),
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
