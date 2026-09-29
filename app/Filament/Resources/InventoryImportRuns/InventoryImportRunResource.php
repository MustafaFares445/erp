<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryImportRuns;

use App\Enums\InventoryImportRunStatus;
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
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryImportRunResource extends Resource
{
    protected static ?string $model = InventoryImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('file_path')->label(__('admin.inventory.import.ui.file'))->disk('local')->directory('catalog-imports')->acceptedFileTypes([
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->required(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('admin.inventory.import.ui.run'))->sortable(),
            TextColumn::make('status')->badge()->formatStateUsing(fn (InventoryImportRunStatus $state): string => __('admin.inventory.import.statuses.'.$state->value))->sortable(),
            TextColumn::make('total_rows')->label(__('admin.inventory.import.ui.total_rows'))->sortable(),
            TextColumn::make('valid_rows')->label(__('admin.inventory.import.ui.valid_rows'))->sortable(),
            TextColumn::make('failed_rows')->label(__('admin.inventory.import.ui.failed_rows'))->sortable(),
            TextColumn::make('created_rows')->label(__('admin.inventory.import.ui.created_rows'))->sortable(),
            TextColumn::make('updated_rows')->label(__('admin.inventory.import.ui.updated_rows'))->sortable(),
            TextColumn::make('applied_rows')->label(__('admin.inventory.import.ui.applied_rows'))->sortable(),
            TextColumn::make('rejected_rows')->label(__('admin.inventory.import.ui.rejected_rows'))->sortable(),
            TextColumn::make('createdBy.name')->label(__('admin.inventory.import.ui.created_by'))->sortable(),
            TextColumn::make('confirmed_at')->label(__('admin.inventory.import.ui.confirmed_at'))->dateTime()->sortable(),
            TextColumn::make('created_at')->label(__('admin.inventory.import.ui.created_at'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options([
                InventoryImportRunStatus::Queued->value => __('admin.inventory.import.statuses.queued'),
                InventoryImportRunStatus::Parsing->value => __('admin.inventory.import.statuses.parsing'),
                InventoryImportRunStatus::Ready->value => __('admin.inventory.import.statuses.ready'),
                InventoryImportRunStatus::ReadyWithErrors->value => __('admin.inventory.import.statuses.ready_with_errors'),
                InventoryImportRunStatus::Invalid->value => __('admin.inventory.import.statuses.invalid'),
                InventoryImportRunStatus::Applying->value => __('admin.inventory.import.statuses.applying'),
                InventoryImportRunStatus::Confirmed->value => __('admin.inventory.import.statuses.confirmed'),
                InventoryImportRunStatus::ConfirmedWithErrors->value => __('admin.inventory.import.statuses.confirmed_with_errors'),
                InventoryImportRunStatus::Failed->value => __('admin.inventory.import.statuses.failed'),
            ]),
        ])->recordActions([
            Action::make('preview')->label(__('admin.inventory.import.ui.preview'))
                ->modalHeading(__('admin.inventory.import.ui.preview_heading'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('admin.inventory.import.ui.close'))
                ->modalContent(fn (InventoryImportRun $record): Factory|\Illuminate\Contracts\View\View => view('filament.inventory-import-preview', ['items' => $record->items()->orderBy('row_number')->get()])),
            Action::make('confirm')
                ->label(__('admin.inventory.import.ui.confirm'))
                ->color('success')
                ->visible(fn (InventoryImportRun $record): bool => $record->status->canApply() && (auth()->user()?->can('update', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (InventoryImportRun $record): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(CatalogImportService::class)->confirm($record, $actor);
                    }
                }),
            Action::make('download_rows')
                ->label(__('admin.inventory.import.ui.download_rows'))
                ->visible(fn (InventoryImportRun $record): bool => is_string($record->result_path) && (auth()->user()?->can('view', $record) ?? false))
                ->action(fn (InventoryImportRun $record): StreamedResponse => Storage::disk('local')->download(
                    self::downloadPath($record->result_path),
                    'catalog-import-'.self::recordId($record).'-rows.csv',
                )),
            Action::make('download_summary')
                ->label(__('admin.inventory.import.ui.download_summary'))
                ->visible(fn (InventoryImportRun $record): bool => is_string($record->summary_path) && (auth()->user()?->can('view', $record) ?? false))
                ->action(fn (InventoryImportRun $record): StreamedResponse => Storage::disk('local')->download(
                    self::downloadPath($record->summary_path),
                    'catalog-import-'.self::recordId($record).'-summary.csv',
                )),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryImportRuns::route('/')];
    }

    private static function downloadPath(mixed $path): string
    {
        if (! is_string($path)) {
            throw new LogicException('The import report is not available.');
        }

        return $path;
    }

    private static function recordId(InventoryImportRun $record): int
    {
        $key = $record->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory import runs must use integer identifiers.');
        }

        return $key;
    }
}
