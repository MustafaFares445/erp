<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryImportRuns\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ManageInventoryImportRuns extends ManageRecords
{
    use RequestsInventoryExports;

    protected static string $resource = InventoryImportRunResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->inventoryExportAction(InventoryExportType::ImportResults),
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
                    app(CatalogImportService::class)->queueStoredFile(
                        $this->storedPath($data),
                        $this->actor(),
                    );
                }),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated import actor is required.');
        }

        return $actor;
    }

    /** @param array<mixed> $data */
    private function storedPath(array $data): string
    {
        $path = $data['file_path'] ?? null;

        if (! is_string($path)) {
            throw new LogicException('An uploaded catalog file is required.');
        }

        return $path;
    }
}
