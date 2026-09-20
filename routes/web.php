<?php

declare(strict_types=1);

use App\Http\Controllers\InventoryOperationMediaController;
use App\Http\Controllers\InvoiceMediaController;
use App\Http\Controllers\JoinUsController;
use App\Http\Controllers\PaymentMediaController;
use App\Http\Controllers\PurchaseOrderMediaController;
use App\Http\Controllers\PurchaseOrderPrintController;
use App\Http\Controllers\QuotationMediaController;
use App\Http\Controllers\ShipmentArrivalConfirmationMediaController;
use App\Http\Controllers\ShipmentMediaController;
use App\Http\Controllers\TicketMediaController;
use App\Http\Controllers\VisitMediaController;
use App\Http\Controllers\VoiceNoteMediaController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/join-us', [JoinUsController::class, 'create'])->name('join-us.create');
Route::post('/join-us', [JoinUsController::class, 'store'])->name('join-us.store');
Route::get('/join-us/thank-you', [JoinUsController::class, 'show'])->name('join-us.thank-you');

Route::middleware(Authenticate::class)->group(function (): void {
    Route::get('/admin/purchase-orders/{purchaseOrder}/print', PurchaseOrderPrintController::class)
        ->name('admin.purchase-orders.print');

    Route::get('/admin/purchase-orders/{purchaseOrder}/media/{media}/preview', [PurchaseOrderMediaController::class, 'preview'])
        ->name('admin.purchase-orders.media.preview');
    Route::get('/admin/purchase-orders/{purchaseOrder}/media/{media}/download', [PurchaseOrderMediaController::class, 'download'])
        ->name('admin.purchase-orders.media.download');

    Route::get('/admin/shipments/{shipment}/media/{media}/preview', [ShipmentMediaController::class, 'preview'])
        ->name('admin.shipments.media.preview');
    Route::get('/admin/shipments/{shipment}/media/{media}/download', [ShipmentMediaController::class, 'download'])
        ->name('admin.shipments.media.download');

    Route::get('/admin/shipments/{shipment}/arrival-confirmation/media/{media}/preview', [ShipmentArrivalConfirmationMediaController::class, 'preview'])
        ->name('admin.shipments.arrival-confirmation.media.preview');
    Route::get('/admin/shipments/{shipment}/arrival-confirmation/media/{media}/download', [ShipmentArrivalConfirmationMediaController::class, 'download'])
        ->name('admin.shipments.arrival-confirmation.media.download');

    Route::get('/admin/visits/{visit}/media/{media}/preview', [VisitMediaController::class, 'preview'])
        ->name('admin.visits.media.preview');
    Route::get('/admin/visits/{visit}/media/{media}/download', [VisitMediaController::class, 'download'])
        ->name('admin.visits.media.download');

    Route::get('/admin/inventory-operations/{operation}/media/{media}/preview', [InventoryOperationMediaController::class, 'preview'])
        ->name('admin.inventory-operations.media.preview');
    Route::get('/admin/inventory-operations/{operation}/media/{media}/download', [InventoryOperationMediaController::class, 'download'])
        ->name('admin.inventory-operations.media.download');

    Route::get('/admin/voice-notes/{voiceNote}/media/{media}/play', [VoiceNoteMediaController::class, 'play'])
        ->middleware('signed')
        ->name('admin.voice-notes.media.play');

    Route::get('/admin/tickets/{ticket}/media/{media}/preview', [TicketMediaController::class, 'preview'])
        ->name('admin.tickets.media.preview');
    Route::get('/admin/tickets/{ticket}/media/{media}/download', [TicketMediaController::class, 'download'])
        ->name('admin.tickets.media.download');

    Route::get('/admin/invoices/{invoice}/media/{media}/preview', [InvoiceMediaController::class, 'preview'])
        ->name('admin.invoices.media.preview');
    Route::get('/admin/invoices/{invoice}/media/{media}/download', [InvoiceMediaController::class, 'download'])
        ->name('admin.invoices.media.download');

    Route::get('/admin/quotations/{quotation}/media/{media}/preview', [QuotationMediaController::class, 'preview'])
        ->name('admin.quotations.media.preview');
    Route::get('/admin/quotations/{quotation}/media/{media}/download', [QuotationMediaController::class, 'download'])
        ->name('admin.quotations.media.download');

    Route::get('/admin/payments/{payment}/media/{media}/preview', [PaymentMediaController::class, 'preview'])
        ->name('admin.payments.media.preview');
    Route::get('/admin/payments/{payment}/media/{media}/download', [PaymentMediaController::class, 'download'])
        ->name('admin.payments.media.download');
});

/**
 * Pre-consolidation Inventory URLs (spec 012), each merged into one tab of
 * the new admin/catalog-setup page. Kept so bookmarks and links made before
 * the merge keep working.
 */
Route::redirect('/admin/product-categories', '/admin/catalog-setup?tab=categories');
Route::redirect('/admin/brands', '/admin/catalog-setup?tab=brands');
Route::redirect('/admin/product-attributes', '/admin/catalog-setup?tab=attributes');
Route::redirect('/admin/units', '/admin/catalog-setup?tab=units');
