<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class MaintenanceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceRecords';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label(__('Maintenance request #'))->sortable(),
                TextColumn::make('serializedInventoryUnit.productVariant.name')->label(__('Equipment'))->placeholder(__('External / unlinked')),
                TextColumn::make('serial_number')->label(__('Serial'))->placeholder(__('—'))->searchable(),
                TextColumn::make('warranty_status')->label(__('Warranty'))->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('billing_type')->label(__('Billing'))->badge(),
                TextColumn::make('created_at')->label(__('Raised'))->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(static fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
