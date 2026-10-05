<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Customer\CustomerAuthController;
use App\Http\Controllers\Api\Customer\CustomerEquipmentController;
use App\Http\Controllers\Api\Customer\CustomerKnowledgeController;
use App\Http\Controllers\Api\Customer\CustomerMaintenanceController;
use App\Http\Controllers\Api\Customer\CustomerSupportPaymentController;
use App\Http\Controllers\Api\Customer\CustomerSupportSatisfactionController;
use App\Http\Controllers\Api\Customer\CustomerTicketMediaController;
use App\Http\Controllers\Api\Customer\ProductQualityComplaintController;
use App\Http\Controllers\Api\Customer\SupportTicketController;
use App\Http\Controllers\Api\Customer\SupportTicketMessageController;
use App\Http\Middleware\EnsureCustomerApiUser;
use App\Http\Middleware\EnsureCustomerSupportApiEnabled;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::prefix('customer')->group(function (): void {
    Route::post('/login', [CustomerAuthController::class, 'login'])
        ->middleware('throttle:customer-login')
        ->name('api.customer.login');

    Route::middleware(['auth:sanctum', CheckAbilities::class.':customer:*', EnsureCustomerApiUser::class, 'throttle:customer-api'])->group(function (): void {
        Route::post('/logout', [CustomerAuthController::class, 'logout'])
            ->name('api.customer.logout');

        Route::middleware(EnsureCustomerSupportApiEnabled::class)->group(function (): void {
            Route::get('/support/tickets', [SupportTicketController::class, 'index'])
                ->name('api.customer.support.tickets.index');
            Route::post('/support/tickets', [SupportTicketController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('api.customer.support.tickets.store');
            Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])
                ->name('api.customer.support.tickets.show');
            Route::post('/support/product-quality-complaints', [ProductQualityComplaintController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('api.customer.support.product-quality-complaints.store');

            Route::get('/support/tickets/{ticket}/messages', [SupportTicketMessageController::class, 'index'])
                ->name('api.customer.support.messages.index');
            Route::post('/support/tickets/{ticket}/messages', [SupportTicketMessageController::class, 'store'])
                ->middleware('throttle:60,1')
                ->name('api.customer.support.messages.store');

            Route::get('/support/tickets/{ticket}/attachments/{media}', [CustomerTicketMediaController::class, 'download'])
                ->name('api.customer.support.attachments.download');

            Route::post('/support/tickets/{ticket}/payment-session', [CustomerSupportPaymentController::class, 'createSession'])
                ->middleware('throttle:10,1')
                ->name('api.customer.support.payment.session');
            Route::get('/support/tickets/{ticket}/payment-status', [CustomerSupportPaymentController::class, 'status'])
                ->name('api.customer.support.payment.status');
            Route::post('/support/tickets/{ticket}/satisfaction', [CustomerSupportSatisfactionController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('api.customer.support.satisfaction.store');

            Route::get('/equipment', [CustomerEquipmentController::class, 'index'])
                ->name('api.customer.equipment.index');
            Route::get('/equipment/{equipment}', [CustomerEquipmentController::class, 'show'])
                ->name('api.customer.equipment.show');

            Route::get('/maintenance', [CustomerMaintenanceController::class, 'index'])
                ->name('api.customer.maintenance.index');
            Route::get('/maintenance/{maintenance}', [CustomerMaintenanceController::class, 'show'])
                ->name('api.customer.maintenance.show');

            Route::get('/support/knowledge', [CustomerKnowledgeController::class, 'index'])
                ->name('api.customer.support.knowledge.index');
            Route::get('/support/knowledge/suggestions', [CustomerKnowledgeController::class, 'suggestions'])
                ->name('api.customer.support.knowledge.suggestions');
            Route::get('/support/knowledge/{article}', [CustomerKnowledgeController::class, 'show'])
                ->name('api.customer.support.knowledge.show');
        });
    });
});
