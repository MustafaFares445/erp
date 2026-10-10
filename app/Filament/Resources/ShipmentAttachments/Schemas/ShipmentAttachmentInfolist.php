<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments\Schemas;

use App\Enums\ShipmentConfirmationSource;
use App\Enums\ShipmentStatus;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Filament\Support\OperationalRouteData;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use App\Services\Inventory\Diagnostics\ShipmentDiagnoser;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ShipmentAttachmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(
                static fn (Shipment $record): array => app(ShipmentDiagnoser::class)->keyFigures($record),
            ),
            Section::make(__('inventory_view_ux.ui.shipment_details'))->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                TextEntry::make('tracking_number')->label(__('admin.shipment.fields.tracking_number')),
                TextEntry::make('order.customer.company_name')->label(__('admin.shipment.fields.customer')),
                TextEntry::make('warehouse.name')->label(__('admin.shipment.fields.warehouse')),
                TextEntry::make('status')
                    ->label(__('admin.shipment.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ShipmentStatus $state): string => $state->label()),
                TextEntry::make('confirmed_by')
                    ->label(__('admin.shipment.fields.confirmed_by'))
                    ->state(fn (Shipment $record): ?string => $record->confirmedByLabel())
                    ->placeholder(__('-')),
                TextEntry::make('confirmed_at')->label(__('admin.shipment.fields.confirmed_at'))->dateTime(),
            ]),
            Section::make(__('Shipment delivery route'))
                ->schema([
                    View::make('filament.partials.operational-route-map')
                        ->viewData(static fn (Shipment $record): array => OperationalRouteData::shipment($record)),
                ]),
            Section::make(__('admin.shipment.fields.confirmation_evidence'))
                ->visible(fn (Shipment $record): bool => $record->arrivalConfirmation !== null)
                ->schema([
                    TextEntry::make('arrivalConfirmation.confirmed_by_type')
                        ->label(__('admin.shipment.fields.confirmed_by'))
                        ->formatStateUsing(fn (?ShipmentConfirmationSource $state): string => $state?->label() ?? '—'),
                    TextEntry::make('arrivalConfirmation.confirmed_at')->label(__('admin.shipment.fields.confirmed_at'))->dateTime(),
                    TextEntry::make('arrivalConfirmation.source_channel')
                        ->label(__('admin.shipment.fields.source_channel'))
                        ->formatStateUsing(static fn (?string $state): string => self::sourceChannelLabel($state)),
                    TextEntry::make('arrivalConfirmation.note')->label(__('admin.shipment.fields.note'))->placeholder(__('—')),
                    RepeatableEntry::make('arrivalConfirmation.deliveryPhotos')
                        ->label(__('admin.shipment.fields.delivery_photos'))
                        ->state(fn (Shipment $record): array => self::deliveryPhotoRows($record))
                        ->schema([
                            TextEntry::make('file_name')->label(__('admin.shipment.fields.photo')),
                            TextEntry::make('preview_url')
                                ->label(__('admin.shipment.actions.preview'))
                                ->formatStateUsing(fn (): string => __('admin.shipment.actions.preview'))
                                ->url(fn (string $state): string => $state)
                                ->openUrlInNewTab(),
                            TextEntry::make('download_url')
                                ->label(__('admin.shipment.actions.download'))
                                ->formatStateUsing(fn (): string => __('admin.shipment.actions.download'))
                                ->url(fn (string $state): string => $state)
                                ->openUrlInNewTab(),
                        ])
                        ->columns(3)
                        ->placeholder(__('admin.shipment.no_delivery_photos')),
                ])
                ->columns(2),
            Section::make(__('admin.shipment.fields.attachments'))->schema([
                RepeatableEntry::make('attachments')
                    ->state(fn (Shipment $record): array => $record->getMedia('attachments')
                        ->map(fn (Media $media): array => [
                            'file_name' => $media->file_name,
                            'preview_url' => route('admin.shipments.media.preview', ['shipment' => $record, 'media' => $media]),
                            'download_url' => route('admin.shipments.media.download', ['shipment' => $record, 'media' => $media]),
                        ])
                        ->all())
                    ->schema([
                        TextEntry::make('file_name')->label(__('admin.shipment.fields.attachments')),
                        TextEntry::make('preview_url')
                            ->label(__('admin.shipment.actions.preview'))
                            ->formatStateUsing(fn (): string => __('admin.shipment.actions.preview'))
                            ->url(fn (string $state): string => $state)
                            ->openUrlInNewTab(),
                        TextEntry::make('download_url')
                            ->label(__('admin.shipment.actions.download'))
                            ->formatStateUsing(fn (): string => __('admin.shipment.actions.download'))
                            ->url(fn (string $state): string => $state)
                            ->openUrlInNewTab(),
                    ])
                    ->columns(3)
                    ->placeholder(__('admin.inventory.operation.attachments_empty')),
            ]),
        ]);
    }

    private static function sourceChannelLabel(?string $channel): string
    {
        $key = 'admin.shipment.source_channels.'.$channel;
        $label = __($key);

        return $label === $key ? str((string) $channel)->headline()->toString() : $label;
    }

    /** @return list<array{file_name: string, preview_url: string, download_url: string}> */
    private static function deliveryPhotoRows(Shipment $record): array
    {
        $confirmation = $record->arrivalConfirmation;

        if (! $confirmation instanceof ShipmentArrivalConfirmation) {
            return [];
        }

        return array_values($confirmation->getMedia('delivery-confirmation-photos')
            ->map(fn (Media $media): array => [
                'file_name' => $media->file_name,
                'preview_url' => route('admin.shipments.arrival-confirmation.media.preview', ['shipment' => $record, 'media' => $media]),
                'download_url' => route('admin.shipments.arrival-confirmation.media.download', ['shipment' => $record, 'media' => $media]),
            ])
            ->all());
    }
}
