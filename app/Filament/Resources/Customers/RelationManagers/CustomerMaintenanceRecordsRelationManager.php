<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only (WP-3.1, GAP-UI-03, MT-08) — the link out is the only action.
 */
final class CustomerMaintenanceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceRecords';

    protected static ?string $title = 'Maintenance records';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Job #'),
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('status')->badge(),
                TextColumn::make('billing_type')->label('Billing')->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
