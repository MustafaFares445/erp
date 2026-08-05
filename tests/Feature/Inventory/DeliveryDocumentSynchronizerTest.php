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
