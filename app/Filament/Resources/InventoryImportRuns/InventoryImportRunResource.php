<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryImportRuns;

use App\Filament\Resources\InventoryImportRuns\Pages\ManageInventoryImportRuns;
use App\Models\InventoryImportRun;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\Factory;

final class InventoryImportRunResource extends Resource
{
    protected static ?string $model = InventoryImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('file_path')->disk('local')->directory('catalog-imports')->acceptedFileTypes([
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->required(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Run')->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('total_rows')->sortable(),
            TextColumn::make('valid_rows')->sortable(),
            TextColumn::make('failed_rows')->sortable(),
            TextColumn::make('createdBy.name')->label('Created by')->sortable(),
            TextColumn::make('confirmed_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options([
                'queued' => 'Queued', 'parsing' => 'Parsing', 'ready' => 'Ready', 'invalid' => 'Invalid', 'confirmed' => 'Confirmed', 'failed' => 'Failed',
            ]),
        ])->recordActions([
            Action::make('preview')
                ->modalHeading('Import row results')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (InventoryImportRun $record): Factory|\Illuminate\Contracts\View\View => view('filament.inventory-import-preview', ['items' => $record->items()->orderBy('row_number')->get()])),
            Action::make('confirm')
                ->color('success')
                ->visible(fn (InventoryImportRun $record): bool => $record->status === 'ready' && (auth()->user()?->can('update', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (InventoryImportRun $record): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(CatalogImportService::class)->confirm($record, $actor);
                    }
                }),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryImportRuns::route('/')];
    }
}
