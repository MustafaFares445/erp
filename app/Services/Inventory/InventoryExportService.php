<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Jobs\GenerateInventoryExport;
use App\Models\InventoryExport;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final readonly class InventoryExportService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $filters @throws DomainException */
    public function request(string $type, array $filters, User $actor): InventoryExport
    {
        $this->assertCanExport($actor);

        if (! in_array($type, ['stock_levels', 'movements'], true)) {
            throw new DomainException(__('admin.inventory.export.errors.invalid_type'));
        }

        $export = InventoryExport::query()->create([
            'type' => $type,
            'filters' => $filters,
            'status' => 'queued',
            'created_by' => $actor->getKey(),
        ]);

        $this->auditLogger->log(
            action: 'inventory.export.requested',
            entity: $export,
            newValues: ['type' => $type, 'filters' => $filters],
            actor: $actor,
            sourceChannel: 'dashboard',
        );
        GenerateInventoryExport::dispatch($this->exportId($export));

        return $export;
    }

    public function generate(InventoryExport $export): void
    {
        $export->forceFill(['status' => 'processing', 'failure_reason' => null])->save();

        try {
            $path = sprintf('inventory-exports/%d.xlsx', $this->exportId($export));
            $absolutePath = Storage::disk('local')->path($path);
            $directory = dirname($absolutePath);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $writer = new Writer;
            $writer->openToFile($absolutePath);

            if ($export->type === 'stock_levels') {
                $this->writeStockLevels($writer, $this->filters($export));
            } else {
                $this->writeMovements($writer, $this->filters($export));
            }

            $writer->close();
            $export->forceFill(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()])->save();
        } catch (Throwable $throwable) {
            $export->forceFill(['status' => 'failed', 'failure_reason' => $throwable->getMessage()])->save();
            throw $throwable;
        }
    }

    /** @throws DomainException */
    public function download(InventoryExport $export, User $actor): BinaryFileResponse
    {
        $this->assertCanExport($actor);

        if ($export->status !== 'completed' || $export->file_path === null || ! Storage::disk('local')->exists($export->file_path)) {
            throw new DomainException(__('admin.inventory.export.errors.not_ready'));
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            sprintf('%s-%d.xlsx', $export->type, $this->exportId($export)),
        );
    }

    /** @param array<string, mixed> $filters */
    private function writeStockLevels(Writer $writer, array $filters): void
    {
        $writer->addRow(Row::fromValues(['SKU', 'Variant', 'Warehouse', 'On hand', 'Reserved', 'Available', 'Reorder level', 'In transit']));
        $query = InventoryStock::query()->with(['productVariant:id,sku,name', 'warehouse:id,name']);

        if (is_numeric($filters['warehouse_id'] ?? null)) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        $query->orderBy('id')->chunkById(500, function ($stocks) use ($writer): void {
            foreach ($stocks as $stock) {
                $this->writeStockLevelRow($writer, $stock);
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function writeMovements(Writer $writer, array $filters): void
    {
        $writer->addRow(Row::fromValues(['Date', 'SKU', 'Variant', 'Warehouse', 'Type', 'Quantity', 'Source type', 'Source ID']));
        $query = InventoryMovement::query()->with(['productVariant:id,sku,name', 'warehouse:id,name'])->latest();

        if (is_numeric($filters['warehouse_id'] ?? null)) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (is_string($filters['movement_type'] ?? null)) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (is_string($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (is_string($filters['until'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['until']);
        }

        $query->chunkById(500, function ($movements) use ($writer): void {
            foreach ($movements as $movement) {
                $this->writeMovementRow($writer, $movement);
            }
        }, 'id');
    }

    private function writeStockLevelRow(Writer $writer, InventoryStock $stock): void
    {
        $variant = $stock->productVariant;
        $warehouse = $stock->warehouse;

        if ($variant === null || $warehouse === null) {
            return;
        }

        $writer->addRow(Row::fromValues([
            $variant->sku,
            $variant->name,
            $warehouse->name,
            (float) $stock->on_hand_quantity,
            (float) $stock->reserved_quantity,
            (float) $stock->available_quantity,
            $stock->reorder_level === null ? null : (float) $stock->reorder_level,
            $stock->inTransitQuantity(),
        ]));
    }

    private function writeMovementRow(Writer $writer, InventoryMovement $movement): void
    {
        $createdAt = $movement->created_at;
        $variant = $movement->productVariant;
        $warehouse = $movement->warehouse;

        if (! $createdAt instanceof DateTimeInterface || $variant === null || $warehouse === null) {
            return;
        }

        $writer->addRow(Row::fromValues([
            $createdAt->format('Y-m-d H:i:s'),
            $variant->sku,
            $variant->name,
            $warehouse->name,
            $movement->movement_type->value,
            (float) $movement->quantity,
            $movement->source_type,
            $movement->source_id,
        ]));
    }

    /** @throws DomainException */
    private function assertCanExport(User $actor): void
    {
        if (! $actor->can(InventoryPermission::Export->value)) {
            throw new DomainException(__('admin.inventory.export.errors.unauthorized'));
        }
    }

    private function exportId(InventoryExport $export): int
    {
        $key = $export->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory exports must use integer identifiers.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function filters(InventoryExport $export): array
    {
        if (! is_array($export->filters)) {
            return [];
        }

        $filters = [];

        foreach ($export->filters as $key => $value) {
            if (is_string($key)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
