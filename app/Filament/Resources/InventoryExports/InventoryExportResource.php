<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryExports;

use App\Filament\Resources\InventoryExports\Pages\ManageInventoryExports;
use App\Models\InventoryExport;
use App\Models\User;
use App\Services\Inventory\InventoryExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class InventoryExportResource extends Resource
{
    protected static ?string $model = InventoryExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('createdBy.name')->label('Created by')->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('failure_reason')->limit(60)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('type')->options(['stock_levels' => 'Stock levels', 'movements' => 'Movements']),
            SelectFilter::make('status')->options(['queued' => 'Queued', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed']),
        ])->recordActions([
            Action::make('download')
                ->visible(fn (InventoryExport $record): bool => $record->status === 'completed')
                ->action(function (InventoryExport $record): ?BinaryFileResponse {
                    $actor = auth()->user();

                    return $actor instanceof User
                        ? app(InventoryExportService::class)->download($record, $actor)
                        : null;
                }),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryExports::route('/')];
    }
}
