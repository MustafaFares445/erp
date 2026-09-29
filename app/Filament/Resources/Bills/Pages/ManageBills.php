<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;
use LogicException;

final class ManageBills extends ManageRecords
{
    protected static string $resource = BillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()
            ->fillForm(fn (array $arguments): array => self::createDefaults($arguments))
            ->createAnother(fn (array $arguments): bool => ! isset($arguments['purchase_order_id']))
            ->using(function (array $data, AccountingDocumentService $documents): Bill {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                $lines = Arr::pull($data, 'lines', []);
                $normalizedLines = [];
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        if (is_array($line)) {
                            $normalizedLines[] = self::normalizeData($line);
                        }
                    }
                }

                return $documents->recordBill($actor, self::normalizeData($data), $normalizedLines);
            })];
    }

    /** @param array<mixed> $arguments
     * @return array<string, mixed>
     */
    private static function createDefaults(array $arguments): array
    {
        $defaults = ['bill_date' => today()->toDateString()];
        $purchaseOrderId = $arguments['purchase_order_id'] ?? null;

        if (! is_numeric($purchaseOrderId) || (int) $purchaseOrderId <= 0) {
            return $defaults;
        }

        $purchaseOrder = PurchaseOrder::query()
            ->with('lines.productVariant')
            ->find((int) $purchaseOrderId);

        if (! $purchaseOrder instanceof PurchaseOrder) {
            return $defaults;
        }

        $defaults['purchase_order_id'] = $purchaseOrder->id;
        $defaults['supplier_id'] = $purchaseOrder->supplier_id;
        $defaults['description'] = 'Supplier bill for '.$purchaseOrder->purchase_order_number;
        $defaults['subtotal'] = $purchaseOrder->total_amount;
        $defaults['tax_total'] = '0.00';
        $defaults['total_amount'] = $purchaseOrder->total_amount;
        $defaults['lines'] = $purchaseOrder->lines
            ->map(static fn (PurchaseOrderLine $line): array => [
                'purchase_order_line_id' => $line->id,
                'description' => mb_trim($line->productVariant->name.' · '.$line->productVariant->sku, ' ·'),
                'quantity' => $line->quantity_ordered,
                'unit_price' => $line->unit_cost,
                'tax_amount' => '0.00',
                'line_total' => $line->line_total,
            ])
            ->all();

        return $defaults;
    }

    /** @return array<string, mixed> */
    private static function normalizeData(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
