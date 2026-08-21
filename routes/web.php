<?php

declare(strict_types=1);

use App\Http\Controllers\InventoryOperationMediaController;
use App\Http\Controllers\JoinUsController;
use App\Http\Controllers\ShipmentMediaController;
use App\Http\Controllers\TicketMediaController;
use App\Http\Controllers\VisitMediaController;
use App\Http\Controllers\VoiceNoteMediaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/join-us', [JoinUsController::class, 'create'])->name('join-us.create');
Route::post('/join-us', [JoinUsController::class, 'store'])->name('join-us.store');
Route::get('/join-us/thank-you', [JoinUsController::class, 'show'])->name('join-us.thank-you');

Route::middleware('auth')->group(function (): void {
    Route::get('/admin/shipments/{shipment}/media/{media}/preview', [ShipmentMediaController::class, 'preview'])
        ->name('admin.shipments.media.preview');
    Route::get('/admin/shipments/{shipment}/media/{media}/download', [ShipmentMediaController::class, 'download'])
        ->name('admin.shipments.media.download');

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
