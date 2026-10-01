<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\WarrantyEntitlement;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CustomerOwnedEquipmentRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedEquipment';

    protected static ?string $title = 'Equipment & Warranty';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.name')->label('Equipment')->searchable(),
                TextColumn::make('serial_number')->label('Serial')->searchable(),
                TextColumn::make('warranty_policy')
                    ->label('Warranty policy')
                    ->getStateUsing(static fn (SerializedInventoryUnit $record): string => self::latestEntitlement($record)->policy_name ?? 'Legacy / none'),
                TextColumn::make('warranty_state')
                    ->label('Warranty')
                    ->badge()
                    ->getStateUsing(static fn (SerializedInventoryUnit $record): string => self::warrantyState($record))
                    ->color(static fn (SerializedInventoryUnit $record): string => self::warrantyColor($record)),
                TextColumn::make('warranty_expires_on')
                    ->label('Expires')
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('last_service')
                    ->label('Last service')
                    ->getStateUsing(static fn (SerializedInventoryUnit $record): ?string => MaintenanceRecord::query()
                        ->where('serialized_inventory_unit_id', $record->getKey())
                        ->latest('created_at')
                        ->first()?->created_at?->format('Y-m-d'))
                    ->placeholder('No service yet'),
            ])
            ->defaultSort('warranty_expires_on', 'desc')
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->label('Open equipment')
                    ->url(static fn (SerializedInventoryUnit $record): string => SerializedInventoryUnitResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    private static function latestEntitlement(SerializedInventoryUnit $record): ?WarrantyEntitlement
    {
        $entitlement = $record->warrantyEntitlements()->first();

        return $entitlement instanceof WarrantyEntitlement ? $entitlement : null;
    }

    private static function warrantyState(SerializedInventoryUnit $record): string
    {
        $entitlement = self::latestEntitlement($record);

        if ($entitlement instanceof WarrantyEntitlement) {
            if ($entitlement->isActiveAt()) {
                return 'Active';
            }

            return $entitlement->state->label();
        }

        if ($record->warranty_expires_on === null) {
            return 'No warranty / needs verification';
        }

        return today()->lte($record->warranty_expires_on) ? 'Active' : 'Expired';
    }

    private static function warrantyColor(SerializedInventoryUnit $record): string
    {
        $state = self::warrantyState($record);

        return match ($state) {
            'Active' => 'success',
            'Pending Activation' => 'warning',
            'No warranty / needs verification' => 'warning',
            default => 'gray',
        };
    }
}
