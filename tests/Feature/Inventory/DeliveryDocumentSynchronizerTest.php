<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Models\InventoryOperation;
use App\Services\Inventory\DeliveryDocumentSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('does nothing when no delivery document path is supplied', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $synchronizer = app(DeliveryDocumentSynchronizer::class);

    $synchronizer->sync($delivery, DeliveryDocument::PaymentReceipt->value, null);
    $synchronizer->sync($delivery, DeliveryDocument::PaymentReceipt->value, '');

    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->toBeNull();
});

it('does nothing when the document is already in the target collection', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $delivery->addMediaFromString('existing document')
        ->usingFileName('existing.pdf')
        ->toMediaCollection(DeliveryDocument::PaymentReceipt->value, 'local');
    $path = $delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value)?->getPathRelativeToRoot();

    if (! is_string($path)) {
        throw new RuntimeException('The existing delivery document could not be resolved.');
    }

    app(DeliveryDocumentSynchronizer::class)->sync(
        $delivery,
        DeliveryDocument::PaymentReceipt->value,
        $path,
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

    app(DeliveryDocumentSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path);

    $delivery->refresh();

    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('rejects a delivery document outside the upload directory', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = UploadedFile::fake()->create('delivery-receipt.pdf', 200, 'application/pdf')->store('elsewhere', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake delivery document could not be stored.');
    }

    app(DeliveryDocumentSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path);
})->throws(ValidationException::class);

it('rejects an oversized delivery document', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = 'delivery-documents/payment_receipt/oversized.pdf';
    Storage::disk('local')->put($path, str_repeat('x', 5 * 1024 * 1024 + 1));

    app(DeliveryDocumentSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path);
})->throws(ValidationException::class);

it('rejects an unsupported delivery document type', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $path = UploadedFile::fake()->create('document.txt', 200, 'text/plain')->store('delivery-documents/payment_receipt', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake delivery document could not be stored.');
    }

    app(DeliveryDocumentSynchronizer::class)->sync($delivery, DeliveryDocument::PaymentReceipt->value, $path);
})->throws(ValidationException::class);
