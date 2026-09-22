<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\SalesPermission;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Assigning a fixed dashboard role with no permissions on it strips a
 * `user_type=Admin` user of the blanket sales-module bypass ({@see
 * \App\Policies\Concerns\ChecksSalesPermissions}), so `$user->can(...)`
 * is what actually gets exercised instead of the admin shortcut.
 */
function unprivilegedAdmin(): User
{
    $user = User::factory()->admin()->create();
    Role::findOrCreate(DashboardRole::SupportAgent->value, 'web');
    $user->assignRole(DashboardRole::SupportAgent->value);

    return $user;
}

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
});

it('previews and downloads invoice media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::InvoiceView->value);

    $invoice = Invoice::factory()->create();
    $invoice->addMediaFromString('%PDF-1.4')->usingFileName('invoice.pdf')->toMediaCollection('invoice-pdf', 'local');
    $media = $invoice->fresh()->getFirstMedia('invoice-pdf');

    $this->actingAs($viewer)
        ->get(route('admin.invoices.media.preview', ['invoice' => $invoice, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.invoices.media.download', ['invoice' => $invoice, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses invoice media to a user lacking invoice-view permission', function (): void {
    $stranger = unprivilegedAdmin();

    $invoice = Invoice::factory()->create();
    $invoice->addMediaFromString('%PDF-1.4')->usingFileName('invoice.pdf')->toMediaCollection('invoice-pdf', 'local');
    $media = $invoice->fresh()->getFirstMedia('invoice-pdf');

    $this->actingAs($stranger)
        ->get(route('admin.invoices.media.preview', ['invoice' => $invoice, 'media' => $media]))
        ->assertForbidden();
});

it('refuses to serve invoice media that does not belong to the requested invoice', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::InvoiceView->value);

    $invoice = Invoice::factory()->create();
    $otherInvoice = Invoice::factory()->create();
    $otherInvoice->addMediaFromString('%PDF-1.4')->usingFileName('invoice.pdf')->toMediaCollection('invoice-pdf', 'local');
    $media = $otherInvoice->fresh()->getFirstMedia('invoice-pdf');

    $this->actingAs($viewer)
        ->get(route('admin.invoices.media.preview', ['invoice' => $invoice, 'media' => $media]))
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('admin.invoices.media.download', ['invoice' => $invoice, 'media' => $media]))
        ->assertNotFound();
});

it('previews and downloads payment media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::PaymentView->value);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-BATCH36-001',
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'payment_date' => now()->toDateString(),
    ]);
    $payment->addMediaFromString('fake-file-bytes')->usingFileName('payment-proof.pdf')->toMediaCollection('payment-proof', 'local');
    $media = $payment->fresh()->getFirstMedia('payment-proof');

    $this->actingAs($viewer)
        ->get(route('admin.payments.media.preview', ['payment' => $payment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.payments.media.download', ['payment' => $payment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses payment media to a user lacking payment-view permission', function (): void {
    $stranger = unprivilegedAdmin();

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-BATCH36-002',
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'payment_date' => now()->toDateString(),
    ]);
    $payment->addMediaFromString('fake-file-bytes')->usingFileName('payment-proof.pdf')->toMediaCollection('payment-proof', 'local');
    $media = $payment->fresh()->getFirstMedia('payment-proof');

    $this->actingAs($stranger)
        ->get(route('admin.payments.media.download', ['payment' => $payment, 'media' => $media]))
        ->assertForbidden();
});

it('refuses to serve payment media that does not belong to the requested payment', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::PaymentView->value);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-BATCH36-003',
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'payment_date' => now()->toDateString(),
    ]);
    $otherPayment = Payment::factory()->create([
        'payment_number' => 'PAY-BATCH36-004',
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '75.00',
        'payment_date' => now()->toDateString(),
    ]);
    $otherPayment->addMediaFromString('fake-file-bytes')->usingFileName('payment-proof.pdf')->toMediaCollection('payment-proof', 'local');
    $media = $otherPayment->fresh()->getFirstMedia('payment-proof');

    $this->actingAs($viewer)
        ->get(route('admin.payments.media.preview', ['payment' => $payment, 'media' => $media]))
        ->assertNotFound();
});

it('previews and downloads quotation media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::QuotationView->value);

    $quotation = Quotation::factory()->create();
    $quotation->addMediaFromString('%PDF-1.4')->usingFileName('quotation.pdf')->toMediaCollection('quotation-pdf', 'local');
    $media = $quotation->fresh()->getFirstMedia('quotation-pdf');

    $this->actingAs($viewer)
        ->get(route('admin.quotations.media.preview', ['quotation' => $quotation, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.quotations.media.download', ['quotation' => $quotation, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses quotation media to a user lacking quotation-view permission', function (): void {
    $stranger = unprivilegedAdmin();

    $quotation = Quotation::factory()->create();
    $quotation->addMediaFromString('%PDF-1.4')->usingFileName('quotation.pdf')->toMediaCollection('quotation-pdf', 'local');
    $media = $quotation->fresh()->getFirstMedia('quotation-pdf');

    $this->actingAs($stranger)
        ->get(route('admin.quotations.media.preview', ['quotation' => $quotation, 'media' => $media]))
        ->assertForbidden();
});

it('refuses to serve quotation media that does not belong to the requested quotation', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::QuotationView->value);

    $quotation = Quotation::factory()->create();
    $otherQuotation = Quotation::factory()->create();
    $otherQuotation->addMediaFromString('%PDF-1.4')->usingFileName('quotation.pdf')->toMediaCollection('quotation-pdf', 'local');
    $media = $otherQuotation->fresh()->getFirstMedia('quotation-pdf');

    $this->actingAs($viewer)
        ->get(route('admin.quotations.media.preview', ['quotation' => $quotation, 'media' => $media]))
        ->assertNotFound();
});

it('previews and downloads shipment arrival confirmation media only for authorized users', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ShipmentView->value);

    $shipment = Shipment::factory()->create();
    $confirmation = ShipmentArrivalConfirmation::factory()->create(['shipment_id' => $shipment->id]);
    $confirmation->addMediaFromString('fake-image-bytes')->usingFileName('arrival.jpg')->toMediaCollection('delivery-confirmation-photos', 'local');
    $media = $confirmation->fresh()->getFirstMedia('delivery-confirmation-photos');

    $this->actingAs($viewer)
        ->get(route('admin.shipments.arrival-confirmation.media.preview', ['shipment' => $shipment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.shipments.arrival-confirmation.media.download', ['shipment' => $shipment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses shipment arrival confirmation media to a user lacking shipment-view permission', function (): void {
    $stranger = User::factory()->admin()->create();

    $shipment = Shipment::factory()->create();
    $confirmation = ShipmentArrivalConfirmation::factory()->create(['shipment_id' => $shipment->id]);
    $confirmation->addMediaFromString('fake-image-bytes')->usingFileName('arrival.jpg')->toMediaCollection('delivery-confirmation-photos', 'local');
    $media = $confirmation->fresh()->getFirstMedia('delivery-confirmation-photos');

    $this->actingAs($stranger)
        ->get(route('admin.shipments.arrival-confirmation.media.preview', ['shipment' => $shipment, 'media' => $media]))
        ->assertForbidden();
});

it('refuses shipment arrival confirmation media when the shipment has no confirmation on file', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ShipmentView->value);

    $shipmentWithoutConfirmation = Shipment::factory()->create();

    $otherShipment = Shipment::factory()->create();
    $confirmation = ShipmentArrivalConfirmation::factory()->create(['shipment_id' => $otherShipment->id]);
    $confirmation->addMediaFromString('fake-image-bytes')->usingFileName('arrival.jpg')->toMediaCollection('delivery-confirmation-photos', 'local');
    $media = $confirmation->fresh()->getFirstMedia('delivery-confirmation-photos');

    $this->actingAs($viewer)
        ->get(route('admin.shipments.arrival-confirmation.media.preview', ['shipment' => $shipmentWithoutConfirmation, 'media' => $media]))
        ->assertNotFound();
});

it('refuses shipment arrival confirmation media outside the delivery-confirmation-photos collection', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ShipmentView->value);

    $shipment = Shipment::factory()->create();
    $confirmation = ShipmentArrivalConfirmation::factory()->create(['shipment_id' => $shipment->id]);
    $confirmation->addMediaFromString('fake-image-bytes')->usingFileName('other.jpg')->toMediaCollection('other-collection', 'local');
    $media = $confirmation->fresh()->getFirstMedia('other-collection');

    $this->actingAs($viewer)
        ->get(route('admin.shipments.arrival-confirmation.media.preview', ['shipment' => $shipment, 'media' => $media]))
        ->assertNotFound();
});

it('refuses a product images path that does not belong to a persisted product record', function (): void {
    $schema = ProductForm::configure(Schema::make());

    /** @var FileUpload $component */
    $component = collect($schema->getComponents(withHidden: true))
        ->sole(fn (mixed $candidate): bool => $candidate instanceof FileUpload && $candidate->getName() === 'images');

    $property = new ReflectionProperty($component, 'allowFilePathUsing');
    $allowFilePathUsing = $property->getValue($component);

    expect($allowFilePathUsing(null, 'anything.png'))->toBeFalse()
        ->and($allowFilePathUsing(new Product, 'anything.png'))->toBeFalse();
});
