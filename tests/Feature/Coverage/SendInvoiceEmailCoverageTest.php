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
use Illuminate\Support\Facades\DB;
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

it('rejects invoice email when the notification delivery is suppressed', function (): void {
    Notification::fake();
    Storage::fake('local');
    (new NotificationTemplateSeeder)->run();

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create(['email' => 'suppressed@example.test']);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'sent_at' => null,
    ]);

    Storage::disk('local')->put('suppressed-invoice.pdf', "%PDF-1.4\ncoverage\n%%EOF");
    $invoice->addMediaFromDisk('suppressed-invoice.pdf', 'local')
        ->usingFileName('suppressed-invoice.pdf')
        ->toMediaCollection('invoice-pdf', 'local');

    DB::table('communication_suppressions')->insert([
        'channel' => 'mail',
        'address' => 'suppressed@example.test',
        'reason' => 'coverage',
        'suppressed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());

    expect(fn (): mixed => $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    ))->toThrow(DomainException::class, 'could not be queued: suppressed');
});

it('rejects invoice email when status changes before the locked transition', function (): void {
    Notification::fake();
    Storage::fake('local');
    (new NotificationTemplateSeeder)->run();

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create(['email' => 'race@example.test']);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'sent_at' => null,
    ]);

    Storage::disk('local')->put('race-invoice.pdf', "%PDF-1.4\ncoverage\n%%EOF");
    $invoice->addMediaFromDisk('race-invoice.pdf', 'local')
        ->usingFileName('race-invoice.pdf')
        ->toMediaCollection('invoice-pdf', 'local');

    NotificationDelivery::created(static function () use ($invoice): void {
        DB::table('invoices')
            ->where('id', $invoice->getKey())
            ->update(['status' => InvoiceStatus::Draft->value]);
    });

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());

    expect(fn (): mixed => $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    ))->toThrow(DomainException::class, 'Only an issued or sent invoice can be emailed.');
});

it('rejects invoice email before PDF lookup when invoice is not sendable', function (): void {
    $actor = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
    ]);

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());

    expect(fn (): mixed => $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    ))->toThrow(DomainException::class, 'Only an issued or sent invoice can be emailed.');
});

it('rejects invoice email when its PDF has not been generated', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create(['email' => 'missing-pdf@example.test']);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());

    expect(fn (): mixed => $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    ))->toThrow(DomainException::class, 'Generate the invoice PDF before sending it.');
});
