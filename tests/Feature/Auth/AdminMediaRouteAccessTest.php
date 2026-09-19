<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WP §8.1 (UAT F42) — routes/web.php:20 used to wrap these ten routes in
 * Laravel's `auth` alias, which redirects to an undefined `login` route
 * (a 500) and admits any authenticated user regardless of `user_type`.
 * Filament\Http\Middleware\Authenticate fixes both: it redirects guests to
 * the Filament login and enforces `canAccessPanel()`.
 */
it('redirects a guest to the Filament login instead of erroring', function (string $routeName, callable $paramsFor): void {
    $params = $paramsFor();

    $this->get(route($routeName, $params))
        ->assertRedirect(route('filament.admin.auth.login'));
})->with([
    'purchase order print' => [
        'admin.purchase-orders.print',
        fn (): array => ['purchaseOrder' => PurchaseOrder::factory()->create()],
    ],
    'purchase order media preview' => [
        'admin.purchase-orders.media.preview',
        function (): array {
            $purchaseOrder = PurchaseOrder::factory()->create();
            $purchaseOrder->addMediaFromString('%PDF-1.4')->usingFileName('customs-payment.pdf')->toMediaCollection('customs_payment', 'local');

            return ['purchaseOrder' => $purchaseOrder, 'media' => $purchaseOrder->fresh()->getFirstMedia('customs_payment')];
        },
    ],
    'shipment media preview' => [
        'admin.shipments.media.preview',
        function (): array {
            $shipment = Shipment::factory()->create();
            $shipment->addMediaFromString('fake-file-bytes')->usingFileName('proof.pdf')->toMediaCollection('attachments', 'local');

            return ['shipment' => $shipment, 'media' => $shipment->fresh()->getFirstMedia('attachments')];
        },
    ],
    'visit media preview' => [
        'admin.visits.media.preview',
        function (): array {
            $visit = CustomerVisit::factory()->create();
            $visit->addMediaFromString('fake-image-bytes')->usingFileName('visit-photo.jpg')->toMediaCollection('visit-attachments', 'local');

            return ['visit' => $visit, 'media' => $visit->fresh()->getFirstMedia('visit-attachments')];
        },
    ],
    'inventory operation media preview' => [
        'admin.inventory-operations.media.preview',
        function (): array {
            $operation = InventoryOperation::factory()->receipt()->create();
            $operation->addMediaFromString('%PDF-1.4')->usingFileName('packing-list.pdf')->toMediaCollection('packing-list-pdf', 'local');

            return ['operation' => $operation, 'media' => $operation->fresh()->getFirstMedia('packing-list-pdf')];
        },
    ],
    'ticket media preview' => [
        'admin.tickets.media.preview',
        function (): array {
            $ticket = Ticket::factory()->create();
            $ticket->addMediaFromString('fake-file-bytes')->usingFileName('ticket-attachment.pdf')->toMediaCollection('ticket-attachments', 'local');

            return ['ticket' => $ticket, 'media' => $ticket->fresh()->getFirstMedia('ticket-attachments')];
        },
    ],
    'invoice media preview' => [
        'admin.invoices.media.preview',
        function (): array {
            $invoice = Invoice::factory()->create();
            $invoice->addMediaFromString('%PDF-1.4')->usingFileName('invoice.pdf')->toMediaCollection('invoice-pdf', 'local');

            return ['invoice' => $invoice, 'media' => $invoice->fresh()->getFirstMedia('invoice-pdf')];
        },
    ],
    'quotation media preview' => [
        'admin.quotations.media.preview',
        function (): array {
            $quotation = Quotation::factory()->create();
            $quotation->addMediaFromString('%PDF-1.4')->usingFileName('quotation.pdf')->toMediaCollection('quotation-pdf', 'local');

            return ['quotation' => $quotation, 'media' => $quotation->fresh()->getFirstMedia('quotation-pdf')];
        },
    ],
    'payment media preview' => [
        'admin.payments.media.preview',
        function (): array {
            $payment = Payment::factory()->create([
                'payment_number' => 'PAY-TEST-001',
                'customer_id' => CustomerProfile::factory(),
                'payment_method_id' => PaymentMethod::factory(),
                'amount' => '50.00',
                'payment_date' => now()->toDateString(),
            ]);
            $payment->addMediaFromString('fake-file-bytes')->usingFileName('payment-proof.pdf')->toMediaCollection('payment-proof', 'local');

            return ['payment' => $payment, 'media' => $payment->fresh()->getFirstMedia('payment-proof')];
        },
    ],
]);

it('refuses a non-admin authenticated user access instead of admitting any authenticated user', function (): void {
    $customer = User::factory()->customer()->create();
    $purchaseOrder = PurchaseOrder::factory()->create();

    $this->actingAs($customer)
        ->get(route('admin.purchase-orders.print', ['purchaseOrder' => $purchaseOrder]))
        ->assertForbidden();
});
