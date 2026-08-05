<?php

declare(strict_types=1);

use App\Http\Controllers\JoinUsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/join-us', [JoinUsController::class, 'create'])->name('join-us.create');
Route::post('/join-us', [JoinUsController::class, 'store'])->name('join-us.store');
Route::get('/join-us/thank-you', [JoinUsController::class, 'show'])->name('join-us.thank-you');

/**
 * Pre-consolidation Inventory URLs (spec 012), each merged into one tab of
 * the new admin/catalog-setup page. Kept so bookmarks and links made before
 * the merge keep working.
 */
Route::redirect('/admin/product-categories', '/admin/catalog-setup?tab=categories');
Route::redirect('/admin/brands', '/admin/catalog-setup?tab=brands');
Route::redirect('/admin/product-attributes', '/admin/catalog-setup?tab=attributes');
Route::redirect('/admin/units', '/admin/catalog-setup?tab=units');
