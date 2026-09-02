<?php

declare(strict_types=1);

use App\Data\Inventory\TransferData;
use App\Enums\FinancialReportType;
use App\Enums\OrderPaymentStatus;
use App\Enums\QuotationStatus;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\Exceptions\PaymentTermNotDeletable;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;

it('covers inventory transfer data construction and validation rules', function (): void {
    $data = new TransferData(
        from_warehouse_id: 10,
        to_warehouse_id: 20,
        notes: 'Move stock',
        items: [['product_variant_id' => 30, 'quantity' => 2.5]],
    );

    expect($data->from_warehouse_id)->toBe(10)
        ->and($data->to_warehouse_id)->toBe(20)
        ->and($data->notes)->toBe('Move stock')
        ->and($data->items)->toHaveCount(1)
        ->and(TransferData::rules())->toBe([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
});

it('covers every financial report label and value', function (): void {
    expect(FinancialReportType::values())->toBe([
        'trial_balance',
        'general_ledger',
        'profit_and_loss',
        'balance_sheet',
        'posting_register',
    ]);

    foreach (FinancialReportType::cases() as $type) {
        expect($type->label())->toBeString()->not->toBe('');
    }
});

it('covers every order payment label', function (): void {
    foreach (OrderPaymentStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBe('');
    }
});

it('covers every quotation lifecycle branch and label', function (): void {
    $terminal = [
        QuotationStatus::Rejected->value,
        QuotationStatus::Expired->value,
        QuotationStatus::ConvertedToDelivery->value,
        QuotationStatus::Cancelled->value,
    ];

    foreach (QuotationStatus::cases() as $source) {
        expect($source->isTerminal())->toBe(in_array($source->value, $terminal, true))
            ->and($source->label())->toBeString()->not->toBe('');

        foreach (QuotationStatus::cases() as $target) {
            $expected = match ($source) {
                QuotationStatus::Draft => in_array($target, [QuotationStatus::Sent, QuotationStatus::Cancelled], true),
                QuotationStatus::Sent => in_array($target, [QuotationStatus::Accepted, QuotationStatus::Rejected, QuotationStatus::Expired, QuotationStatus::Cancelled], true),
                QuotationStatus::Accepted => in_array($target, [QuotationStatus::ConvertedToDelivery, QuotationStatus::Cancelled], true),
                QuotationStatus::Rejected, QuotationStatus::Expired, QuotationStatus::ConvertedToDelivery, QuotationStatus::Cancelled => false,
            };

            expect($source->canTransitionTo($target))->toBe($expected);
        }
    }
});

it('covers named sales exception constructors', function (): void {
    expect(OpportunityNotQuotable::notApproved())->toBeInstanceOf(OpportunityNotQuotable::class)
        ->and(OpportunityNotQuotable::noCustomer())->toBeInstanceOf(OpportunityNotQuotable::class)
        ->and(OpportunityNotQuotable::alreadyQuoted('QUO-1'))->toBeInstanceOf(OpportunityNotQuotable::class)
        ->and(PaymentTermNotDeletable::referenced('Net 30'))->toBeInstanceOf(PaymentTermNotDeletable::class);
});

it('covers every invalid purchase order line constructor', function (): void {
    $variant = new ProductVariant;
    $variant->sku = 'SKU-COVERAGE';

    $supplier = new Supplier;
    $supplier->name = 'Supplier Coverage';

    $warehouse = new Warehouse;
    $warehouse->name = 'Warehouse Coverage';

    foreach ([
        InvalidPurchaseOrderLine::duplicateVariant($variant),
        InvalidPurchaseOrderLine::invalidPurchaseUnit($variant),
        InvalidPurchaseOrderLine::quantityNotPositive(),
        InvalidPurchaseOrderLine::unitCostNegative(),
        InvalidPurchaseOrderLine::inactiveSupplier($supplier),
        InvalidPurchaseOrderLine::inactiveWarehouse($warehouse),
        InvalidPurchaseOrderLine::noLines('PO-COVERAGE'),
    ] as $exception) {
        expect($exception)->toBeInstanceOf(InvalidPurchaseOrderLine::class)
            ->and($exception->getMessage())->not->toBe('');
    }
});
