<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\PurchaseOrderDocument;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Services\Documents\DocumentUploadSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('does nothing when no document path is supplied', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $synchronizer = app(DocumentUploadSynchronizer::class);

    $synchronizer->sync($delivery, DeliveryDocument::PaymentReceipt->value, null, 'delivery-documents/');
    $synchronizer->sync($delivery, DeliveryDocument::PaymentReceipt->value, '', 'delivery-documents/');

    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->toBeNull();
});

it('does nothing when the document is already in the target collection', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $delivery->addMediaFromString('existing document')
        ->usingFileName('existing.pdf')
        ->toMediaCollection(DeliveryDocument::PaymentReceipt->value, 'local');
    $path = $delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value)?->getPathRelativeToRoot();

    if (! is_string($path)) {
        throw new RuntimeException('The existing document could not be resolved.');
    }

    app(DocumentUploadSynchronizer::class)->sync(
        $delivery,
        DeliveryDocument::PaymentReceipt->value,
        $path,
        'delivery-documents/',
    );

    $delivery->refresh();

    expect($delivery->getMedia(DeliveryDocument::PaymentReceipt->value))->toHaveCount(1)
        ->and($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value)?->getPathRelativeToRoot())->toBe($path);
});

it('moves an uploaded delivery document into its collection', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = UploadedFile::fake()->create('delivery-receipt.pdf', 200, 'application/pdf')->store('delivery-documents/payment_receipt', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake delivery document could not be stored.');
    }

    app(DocumentUploadSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path, 'delivery-documents/');

    $delivery->refresh();

    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('moves an uploaded purchase order document into its collection', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create();
    $path = UploadedFile::fake()->create('customs-payment.pdf', 200, 'application/pdf')->store('purchase-order-documents/customs_payment', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake purchase order document could not be stored.');
    }

    app(DocumentUploadSynchronizer::class)->sync($purchaseOrder, PurchaseOrderDocument::CustomsPayment->value, $path, 'purchase-order-documents/');

    $purchaseOrder->refresh();

    expect($purchaseOrder->getFirstMedia(PurchaseOrderDocument::CustomsPayment->value))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('rejects a document outside the allowed upload directory', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = UploadedFile::fake()->create('delivery-receipt.pdf', 200, 'application/pdf')->store('elsewhere', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake delivery document could not be stored.');
    }

    app(DocumentUploadSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path, 'delivery-documents/');
})->throws(ValidationException::class);

it('rejects an oversized document', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = 'delivery-documents/payment_receipt/oversized.pdf';
    Storage::disk('local')->put($path, str_repeat('x', 5 * 1024 * 1024 + 1));

    app(DocumentUploadSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path, 'delivery-documents/');
})->throws(ValidationException::class);

it('rejects an unsupported document type', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = UploadedFile::fake()->create('document.txt', 200, 'text/plain')->store('delivery-documents/payment_receipt', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake delivery document could not be stored.');
    }

    app(DocumentUploadSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path, 'delivery-documents/');
})->throws(ValidationException::class);
