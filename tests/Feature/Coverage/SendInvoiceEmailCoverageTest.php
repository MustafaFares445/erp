<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendInvoiceEmail;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\BusinessNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sales\InvoiceBalanceService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('emails an issued invoice with its generated PDF and marks it sent', function (): void {
    Notification::fake();
    Storage::fake('local');
    (new NotificationTemplateSeeder)->run();

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create(['email' => 'billing@example.test']);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'sent_at' => null,
        'total_amount' => '125.50',
    ]);
    Storage::disk('local')->put('coverage-invoice.pdf', "%PDF-1.4\ncoverage\n%%EOF");
    $invoice->addMediaFromDisk('coverage-invoice.pdf', 'local')
        ->usingFileName('coverage-invoice.pdf')
        ->toMediaCollection('invoice-pdf', 'local');

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());
    $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    );

    $invoice->refresh();
    $delivery = NotificationDelivery::query()
        ->where('subject_document_type', $invoice->getMorphClass())
        ->where('subject_document_id', $invoice->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($delivery->status)->toBeIn([
            NotificationDeliveryStatus::Queued,
            NotificationDeliveryStatus::Sent,
        ]);

    Notification::assertSentTo(
        Notification::route('mail', 'billing@example.test'),
        BusinessNotification::class,
    );
});
