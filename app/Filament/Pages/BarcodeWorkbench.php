<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Data\Inventory\BarcodeResolution;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\User;
use App\Services\Inventory\BarcodeResolver;
use App\Services\Inventory\BarcodeWorkflowService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class BarcodeWorkbench extends Page
{
    use InteractsWithInventoryServices;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $slug = 'inventory/barcode';

    protected string $view = 'filament.pages.barcode-workbench';

    public string $mode = 'receipt';

    public ?int $operationId = null;

    public ?int $countId = null;

    public ?int $countLineId = null;

    public string $scanCode = '';

    public string $countQuantity = '1';

    /** @var array<string, mixed>|null */
    public ?array $resolution = null;

    /** @var list<array<string, mixed>> */
    public array $matches = [];

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(InventoryPermission::BarcodeUse->value) ?? false;
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Barcode workbench');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('Barcode workbench');
    }

    public function updatedMode(): void
    {
        $this->operationId = null;
        $this->countId = null;
        $this->clearScan();
    }

    /** @return array<string, string> */
    public function modeOptions(): array
    {
        return [
            'receipt' => __('Receipt'),
            'delivery' => __('Delivery'),
            'transfer' => __('Internal transfer'),
            'count' => __('Physical count'),
        ];
    }

    /** @return array<int, string> */
    public function operationOptions(): array
    {
        $type = $this->operationType();
        if (! $type instanceof OperationType) {
            return [];
        }

        return InventoryOperation::query()
            ->with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name'])
            ->where('operation_type', $type->value)
            ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value])
            ->latest('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(function (InventoryOperation $operation): array {
                $warehouse = $operation->operation_type === OperationType::Receipt
                    ? $operation->destinationWarehouse?->name
                    : $operation->sourceWarehouse?->name;

                return [$operation->id => ($operation->operation_number ?: '#'.$operation->id).' · '.($warehouse ?? '—').' · '.$operation->stage->label()];
            })
            ->all();
    }

    /** @return array<int, string> */
    public function countOptions(): array
    {
        return InventoryCount::query()
            ->with('warehouse:id,name')
            ->whereIn('status', [InventoryCountStatus::Draft->value, InventoryCountStatus::Counting->value])
            ->latest('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(function (InventoryCount $count): array {
                $warehouseName = data_get($count, 'warehouse.name');

                return [
                    $count->id => $count->count_number.' · '.(is_string($warehouseName) ? $warehouseName : '—').' · '.str($count->status->value)->headline()->toString(),
                ];
            })
            ->all();
    }

    public function scan(): void
    {
        $this->resolution = null;
        $this->matches = [];
        $this->countLineId = null;

        try {
            $resolution = app(BarcodeResolver::class)->resolve($this->scanCode);
            $this->resolution = $this->resolutionArray($resolution);

            if ($this->mode === 'count') {
                $count = $this->selectedCount();
                $matches = app(BarcodeWorkflowService::class)->countMatches($count, $resolution);
                if ($matches->isEmpty()) {
                    throw new DomainException('The scanned item is not part of the selected inventory count.');
                }

                $this->matches = array_values($matches->map(fn (InventoryCountLine $line): array => $this->countLineArray($line))->values()->all());
                if ($matches->count() === 1) {
                    $this->countLineId = $matches->firstOrFail()->id;
                }
            } else {
                $operation = $this->selectedOperation();
                $matches = app(BarcodeWorkflowService::class)->operationMatches($operation, $resolution);
                if ($matches->isEmpty()) {
                    throw new DomainException('The scanned item is not part of the selected inventory operation.');
                }

                $this->matches = array_values($matches->map(fn (InventoryOperationLine $line): array => $this->operationLineArray($line))->values()->all());
            }

            Notification::make()->success()->title(__('Scan matched'))->send();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title(__('Scan rejected'))->body($exception->getMessage())->send();
        } finally {
            $this->scanCode = '';
            $this->dispatch('barcode-focus');
        }
    }

    public function recordCount(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->can(InventoryPermission::CountRecord->value)) {
            throw new DomainException('You are not allowed to record inventory counts.');
        }
        if ($this->countLineId === null || $this->resolution === null) {
            throw new DomainException('Scan an item and select a count line first.');
        }

        $resolutionCode = $this->resolution['code'] ?? null;
        if (! is_string($resolutionCode)) {
            throw new DomainException('The latest scan could not be resolved.');
        }
        $resolution = app(BarcodeResolver::class)->resolve($resolutionCode);
        $count = $this->selectedCount();
        $line = $count->lines()->whereKey($this->countLineId)->firstOrFail();

        $this->runInventoryOperation(
            fn (): InventoryCountLine => app(BarcodeWorkflowService::class)->recordCount(
                $actor,
                $count,
                $line,
                $resolution,
                $this->countQuantity,
            ),
            'admin.inventory.count_ui.messages.recorded',
        );

        $this->matches = array_values(app(BarcodeWorkflowService::class)
            ->countMatches($count->refresh(), $resolution)
            ->map(fn (InventoryCountLine $match): array => $this->countLineArray($match))
            ->values()
            ->all());
    }

    public function targetUrl(): ?string
    {
        if ($this->mode === 'count' && $this->countId !== null) {
            return InventoryCountResource::getUrl('view', ['record' => $this->countId]);
        }

        if ($this->mode !== 'count' && $this->operationId !== null) {
            return InventoryOperationResource::getUrl('view', ['record' => $this->operationId]);
        }

        return null;
    }

    private function selectedOperation(): InventoryOperation
    {
        if ($this->operationId === null) {
            throw new DomainException('Select an inventory operation before scanning.');
        }

        /** @var InventoryOperation $operation */
        $operation = InventoryOperation::query()->findOrFail($this->operationId);
        $expected = $this->operationType();
        if (! $expected instanceof OperationType || $operation->operation_type !== $expected || $operation->isTerminal()) {
            throw new DomainException('The selected inventory operation is not open for this barcode mode.');
        }

        return $operation;
    }

    private function selectedCount(): InventoryCount
    {
        if ($this->countId === null) {
            throw new DomainException('Select an inventory count before scanning.');
        }

        /** @var InventoryCount $count */
        $count = InventoryCount::query()->findOrFail($this->countId);
        if (! in_array($count->status, [InventoryCountStatus::Draft, InventoryCountStatus::Counting], true)) {
            throw new DomainException('The selected inventory count is no longer open for counting.');
        }

        return $count;
    }

    private function operationType(): ?OperationType
    {
        return match ($this->mode) {
            'receipt' => OperationType::Receipt,
            'delivery' => OperationType::Delivery,
            'transfer' => OperationType::InternalTransfer,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function resolutionArray(BarcodeResolution $resolution): array
    {
        return [
            'code' => $resolution->code,
            'kind' => $resolution->kind,
            'variant_id' => $resolution->productVariantId,
            'sku' => $resolution->sku,
            'barcode' => $resolution->barcode,
            'variant_name' => $resolution->variantName,
            'serial_id' => $resolution->serializedInventoryUnitId,
            'serial_number' => $resolution->serialNumber,
        ];
    }

    /** @return array<string, mixed> */
    private function operationLineArray(InventoryOperationLine $line): array
    {
        $sku = data_get($line, 'productVariant.sku');
        $variantName = data_get($line, 'productVariant.name');

        return [
            'id' => $line->id,
            'sku' => is_string($sku) ? $sku : (string) $line->product_variant_id,
            'variant' => is_string($variantName) ? $variantName : '—',
            'serial' => $line->serializedUnit?->serial_number,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) ($line->base_quantity ?? $line->quantity),
        ];
    }

    /** @return array<string, mixed> */
    private function countLineArray(InventoryCountLine $line): array
    {
        $sku = data_get($line, 'productVariant.sku');
        $variantName = data_get($line, 'productVariant.name');

        return [
            'id' => $line->id,
            'sku' => is_string($sku) ? $sku : (string) $line->product_variant_id,
            'variant' => is_string($variantName) ? $variantName : '—',
            'serial' => $line->serializedUnit?->serial_number,
            'lot' => $line->lot?->lot_number,
            'condition' => $line->stock_condition->value,
            'system' => (string) $line->system_base_quantity,
            'counted' => $line->counted_base_quantity === null ? null : (string) $line->counted_base_quantity,
        ];
    }

    private function clearScan(): void
    {
        $this->scanCode = '';
        $this->resolution = null;
        $this->matches = [];
        $this->countLineId = null;
    }
}
