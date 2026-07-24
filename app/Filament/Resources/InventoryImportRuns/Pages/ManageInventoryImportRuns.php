<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryImportRuns\Pages;

use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ManageInventoryImportRuns extends ManageRecords
{
    protected static string $resource = InventoryImportRunResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_template')
                ->label('Download XLSX template')
                ->action(function (): BinaryFileResponse {
                    $path = 'catalog-imports/templates/catalog-import-template.xlsx';
                    $absolutePath = Storage::disk('local')->path($path);
                    app(CatalogImportService::class)->writeTemplate($absolutePath);

                    return response()->download($absolutePath, 'catalog-import-template.xlsx');
                }),
            Action::make('upload')
                ->label('Upload catalog XLSX')
                ->form([
                    FileUpload::make('file_path')
                        ->disk('local')
                        ->directory('catalog-imports')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User || ! is_string($data['file_path'] ?? null)) {
                        return;
                    }

                    app(CatalogImportService::class)->queueStoredFile($data['file_path'], $actor);
                }),
        ];
    }
}
