<?php

declare(strict_types=1);

use App\Models\CustomerVisit;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
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
            $operation->addMediaFromString('%PDF-1.4')->usingFileName('packing-list.pdf')->toMediaCollection('packing_list', 'local');

            return ['operation' => $operation, 'media' => $operation->fresh()->getFirstMedia('packing_list')];
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
]);

it('refuses a non-admin authenticated user access instead of admitting any authenticated user', function (): void {
    $customer = User::factory()->customer()->create();
    $purchaseOrder = PurchaseOrder::factory()->create();

    $this->actingAs($customer)
        ->get(route('admin.purchase-orders.print', ['purchaseOrder' => $purchaseOrder]))
        ->assertForbidden();
});
