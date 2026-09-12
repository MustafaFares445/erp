<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CustomerChannelController;
use App\Http\Controllers\Api\V1\EmployeeChannelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/token', [AuthTokenController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/token', [AuthTokenController::class, 'destroy']);

        Route::prefix('customer')->group(function (): void {
            Route::get('/catalog', [CustomerChannelController::class, 'catalog']);
            Route::get('/quotations', [CustomerChannelController::class, 'quotations']);
            Route::post('/quotations', [CustomerChannelController::class, 'requestQuotation']);
            Route::get('/orders', [CustomerChannelController::class, 'orders']);
            Route::get('/orders/{order}', [CustomerChannelController::class, 'order']);
            Route::get('/invoices', [CustomerChannelController::class, 'invoices']);
            Route::get('/invoices/{invoice}', [CustomerChannelController::class, 'invoice']);
            Route::get('/documents/{type}/{document}', [CustomerChannelController::class, 'downloadDocument']);
            Route::get('/statement', [CustomerChannelController::class, 'statement']);
            Route::get('/tickets', [CustomerChannelController::class, 'tickets']);
            Route::get('/tickets/{ticket}', [CustomerChannelController::class, 'ticket']);
            Route::post('/tickets', [CustomerChannelController::class, 'createTicket']);
        });

        Route::prefix('employee')->group(function (): void {
            Route::get('/tasks', [EmployeeChannelController::class, 'tasks']);
            Route::get('/visits', [EmployeeChannelController::class, 'visits']);
            Route::post('/visits/{visit}/check-in', [EmployeeChannelController::class, 'checkIn']);
            Route::post('/visits/{visit}/check-out', [EmployeeChannelController::class, 'checkOut']);
            Route::post('/visits/{visit}/voice-notes', [EmployeeChannelController::class, 'voiceNote']);
            Route::post('/leads', [EmployeeChannelController::class, 'createLead']);
            Route::post('/interactions', [EmployeeChannelController::class, 'createInteraction']);
            Route::post('/opportunities', [EmployeeChannelController::class, 'createOpportunity']);
            Route::post('/van-sales', [EmployeeChannelController::class, 'createVanSale']);
        });
    });
});
