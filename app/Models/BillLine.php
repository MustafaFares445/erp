<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use Database\Factories\BillLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $purchase_order_line_id
 * @property int|null $product_variant_id
 * @property int|null $chart_account_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_amount
 * @property string $line_total
 */
#[Fillable([
    'bill_id', 'purchase_order_line_id', 'product_variant_id', 'chart_account_id',
    'description', 'quantity', 'unit_price', 'tax_amount', 'line_total', 'sort_order',
])]
final class BillLine extends Model
{
    /** @use HasFactory<BillLineFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (self $line): void {
            $status = Bill::query()->whereKey($line->bill_id)->value('status');
            if (in_array($status, ['approved', 'partially_paid', 'paid'], true)) {
                throw new DomainException('Lines on an approved or paid bill cannot be changed.');
            }
        });

        self::deleting(function (self $line): void {
            $status = Bill::query()->whereKey($line->bill_id)->value('status');
            if (in_array($status, ['approved', 'partially_paid', 'paid'], true)) {
                throw new DomainException('Lines on an approved or paid bill cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    /**
     * Reads completed receipt operations for the referenced PO line. The PO
     * line's cumulative field is an optimization for Purchasing; Accounting's
     * match remains derived from the receipt evidence itself.
     */
    public function receivedQuantity(): float
    {
        $line = $this->purchaseOrderLine;

        if (! $line instanceof PurchaseOrderLine) {
            return 0.0;
        }

        return (float) InventoryOperationLine::query()
            ->where('product_variant_id', $line->product_variant_id)
            ->where('unit_id', $line->unit_id)
            ->whereHas('operation', function (Builder $query) use ($line): void {
                $query->where('operation_type', OperationType::Receipt->value)
                    ->where('stage', OperationStage::Done->value)
                    ->where('source_document_type', PurchaseOrder::class)
                    ->where('source_document_id', $line->purchase_order_id);
            })
            ->sum('quantity');
    }

    public function cumulativeBilledQuantity(): float
    {
        if (! is_numeric($this->purchase_order_line_id)) {
            return 0.0;
        }

        return (float) self::query()
            ->where('purchase_order_line_id', $this->purchase_order_line_id)
            ->whereHas('bill', fn (Builder $query): Builder => $query->whereNotIn('status', ['cancelled']))
            ->sum('quantity');
    }

    public function hasQuantityVariance(): bool
    {
        if (! is_numeric($this->purchase_order_line_id)) {
            return false;
        }

        return abs($this->cumulativeBilledQuantity() - $this->receivedQuantity()) > 0.0005;
    }

    public function hasUnitPriceVariance(): bool
    {
        $purchaseOrderLine = $this->purchaseOrderLine;

        return $purchaseOrderLine instanceof PurchaseOrderLine
            && abs((float) $this->unit_price - (float) $purchaseOrderLine->unit_cost) > 0.005;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
