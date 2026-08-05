<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments\Schemas;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ShipmentAttachmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
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
                    ->placeholder('-'),
                TextEntry::make('confirmed_at')->label(__('admin.shipment.fields.confirmed_at'))->dateTime(),
            ]),
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
                            ->label('Preview')
                            ->formatStateUsing(fn (): string => 'Preview')
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
}
