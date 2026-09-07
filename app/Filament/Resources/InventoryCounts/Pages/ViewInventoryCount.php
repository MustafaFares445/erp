<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Enums\InventoryPermission;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\User;
use App\Services\Inventory\InventoryCountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ViewInventoryCount extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryCountResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('download_count_sheet')
                ->label('Download count sheet')
                ->color('gray')
                ->visible(fn (InventoryCount $record): bool => ($record->isDraft() || $record->isCounting())
                    && (auth()->user()?->can(InventoryPermission::CountRecord->value) ?? false))
                ->action(fn (InventoryCount $record): StreamedResponse => $this->downloadCountSheet($record)),
            Action::make('upload_counts')
                ->label('Upload counts')
                ->color('gray')
                ->visible(fn (InventoryCount $record): bool => $record->isCounting()
                    && (auth()->user()?->can(InventoryPermission::CountRecord->value) ?? false))
                ->schema([
                    FileUpload::make('sheet')
                        ->label('Count sheet (CSV)')
                        ->disk('local')
                        ->directory('inventory-count-uploads')
                        ->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (InventoryCount $record, array $data): void {
                    $path = $data['sheet'] ?? null;

                    if (! is_string($path)) {
                        throw new LogicException('A count sheet file is required.');
                    }

                    $this->uploadCounts($record, $path);
                }),
            Action::make('submit')
                ->label('Submit for review')
                ->color('warning')
                ->visible(fn (InventoryCount $record): bool => $record->isCounting()
                    && (auth()->user()?->can(InventoryPermission::CountRecord->value) ?? false))
                ->schema([
                    Checkbox::make('partial')
                        ->label('Submit as partial (some lines are still uncounted)'),
                ])
                ->action(function (InventoryCount $record, array $data): void {
                    $partial = (bool) ($data['partial'] ?? false);

                    $this->runCountAction(
                        fn (InventoryCountService $service, User $actor): InventoryCount => $service->submitForReview($record, $actor, $partial),
                        'Count submitted for review.',
                    );
                }),
            Action::make('confirm')
                ->label('Confirm count')
                ->color('success')
                ->visible(fn (InventoryCount $record): bool => $record->isPendingReview()
                    && (auth()->user()?->can(InventoryPermission::CountConfirm->value) ?? false))
                ->requiresConfirmation()
                ->modalDescription('Confirming creates one inventory adjustment for every line whose count differs from the system quantity. This cannot be undone.')
                ->action(fn (InventoryCount $record) => $this->runCountAction(
                    fn (InventoryCountService $service, User $actor): InventoryCount => $service->confirm($record, $actor),
                    'Count confirmed.',
                )),
            Action::make('cancel')
                ->color('danger')
                ->visible(fn (InventoryCount $record): bool => ! $record->status->isTerminal()
                    && (auth()->user()?->can(InventoryPermission::CountConfirm->value) ?? false))
                ->schema([
                    Textarea::make('reason')->required()->maxLength(2_000),
                ])
                ->action(function (InventoryCount $record, array $data): void {
                    $reason = $data['reason'] ?? null;

                    if (! is_string($reason)) {
                        throw new LogicException('A cancellation reason is required.');
                    }

                    $this->runCountAction(
                        fn (InventoryCountService $service, User $actor): InventoryCount => $service->cancel($record, $actor, $reason),
                        'Count cancelled.',
                    );
                }),
        ];
    }

    private function runCountAction(callable $operation, string $successMessage): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory count actor is required.');
        }

        $this->runInventoryOperation(
            fn (): mixed => $operation(app(InventoryCountService::class), $actor),
            $successMessage,
        );
    }

    /**
     * A clipboard count sheet (WP-3.5, GAP-MW-06) — one row per generated
     * line, counted quantity left blank for the operator to fill in by
     * hand or barcode scan, then re-imported via {@see self::uploadCounts()}.
     */
    private function downloadCountSheet(InventoryCount $record): StreamedResponse
    {
        $lines = $record->lines()->with(['productVariant', 'lot', 'serializedUnit'])->orderBy('id')->get();

        return response()->streamDownload(static function () use ($lines): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['line_id', 'sku', 'variant', 'lot', 'serial', 'condition', 'system_quantity', 'counted_quantity'], escape: '\\');

            foreach ($lines as $line) {
                fputcsv($handle, [
                    $line->id,
                    $line->productVariant?->sku,
                    $line->productVariant?->name,
                    $line->lot?->lot_number,
                    $line->serializedUnit?->serial_number,
                    $line->stock_condition->value,
                    (string) $line->system_base_quantity,
                    $line->counted_base_quantity === null ? '' : (string) $line->counted_base_quantity,
                ], escape: '\\');
            }

            fclose($handle);
        }, sprintf('%s-count-sheet.csv', Str::slug($record->count_number)));
    }

    /**
     * Re-imports a downloaded, hand-filled count sheet (WP-3.5, GAP-MW-06).
     * Every row must name an existing line of THIS count by `line_id` and
     * carry a `counted_quantity` — a row naming an unknown line, or missing
     * a quantity, is rejected rather than silently skipped, since a
     * mis-scanned sheet must not quietly under-report what was counted.
     */
    private function uploadCounts(InventoryCount $record, string $path): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory count actor is required.');
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new LogicException('The uploaded count sheet could not be read.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new LogicException('The uploaded count sheet could not be opened.');
        }

        $lineIds = $record->lines()->pluck('id')->all();
        $recorded = 0;
        $rejected = [];
        fgetcsv($handle, escape: '\\');

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $lineId = $row[0] ?? null;
            $quantity = $row[7] ?? null;

            if (! is_numeric($lineId) || ! in_array((int) $lineId, $lineIds, true)) {
                $rejected[] = sprintf('line %s does not belong to this count', $lineId ?? '—');

                continue;
            }

            if (! is_string($quantity) || mb_trim($quantity) === '') {
                $rejected[] = sprintf('line %d has no counted quantity', (int) $lineId);

                continue;
            }

            $line = InventoryCountLine::query()->findOrFail((int) $lineId);

            app(InventoryCountService::class)->recordCount($line, mb_trim($quantity), $actor);
            $recorded++;
        }

        fclose($handle);
        Storage::disk('local')->delete($path);

        if ($rejected !== []) {
            Notification::make()
                ->warning()
                ->title(sprintf('%d row(s) recorded, %d rejected.', $recorded, count($rejected)))
                ->body(implode("\n", array_slice($rejected, 0, 10)))
                ->send();

            return;
        }

        Notification::make()->success()->title(sprintf('%d row(s) recorded.', $recorded))->send();
    }
}
