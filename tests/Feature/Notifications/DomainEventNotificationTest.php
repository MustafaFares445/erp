<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\UserType;
use App\Events\InvoiceIssued;
use App\Events\StockLow;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
});

it('adapts an issued invoice event into an in-app customer notification', function (): void {
    $user = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->create(['user_id' => $user->getKey()]);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'total_amount' => '125.50',
    ]);

    InvoiceIssued::dispatch($invoice->load('customer.user'));

    expect(NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::InvoiceIssued->value)
        ->where('channel', NotificationChannel::Database->value)
        ->where('notifiable_id', $user->getKey())
        ->where('subject_document_id', $invoice->getKey())
        ->exists())->toBeTrue();
});

it('fans a newly activated low-stock event out to administrator mail and database channels', function (): void {
    $admin = User::factory()->create(['user_type' => UserType::Admin]);
    $stock = InventoryStock::factory()->create(['available_quantity' => '2.000']);

    StockLow::dispatch($stock);

    expect(NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::StockLow->value)
        ->where('notifiable_id', $admin->getKey())
        ->count())->toBe(2)
        ->and(NotificationDelivery::query()
            ->where('template_key', NotificationEventKey::StockLow->value)
            ->pluck('channel')
            ->map(fn ($channel): string => $channel->value)
            ->sort()
            ->values()
            ->all())->toBe(['database', 'mail']);
});
