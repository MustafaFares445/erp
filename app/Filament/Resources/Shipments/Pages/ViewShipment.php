<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->label('Confirm arrival')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (Shipment $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->authorize(fn (Shipment $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->action(function (Shipment $record): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(ShipmentService::class)->confirmByAdmin($record, $user);
                    Notification::make()->success()->title('Shipment arrival confirmed.')->send();
                }),
        ];
    }
}
