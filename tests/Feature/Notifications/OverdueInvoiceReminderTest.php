<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-03 12:00:00');
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function wp210OverdueInvoice(
    CustomerProfile $customer,
    int $daysOverdue,
    array $overrides = [],
): Invoice {
    return Invoice::factory()->for($customer, 'customer')->create(array_merge([
        'invoice_date' => now()->subDays($daysOverdue + 30)->toDateString(),
        'due_date' => now()->subDays($daysOverdue)->toDateString(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'status' => InvoiceStatus::Sent,
        'issued_at' => now()->subDays($daysOverdue + 30),
        'sent_at' => now()->subDays($daysOverdue + 29),
    ], $overrides));
}

it('fires each 7 30 and 60 day reminder once and never daily re-nags', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = wp210OverdueInvoice($customer, 61);

    $this->artisan('notifications:overdue-invoices')->assertSuccessful();

    expect(NotificationDelivery::query()
        ->where('subject_document_type', $invoice->getMorphClass())
        ->where('subject_document_id', $invoice->getKey())
        ->count())->toBe(3)
        ->and(NotificationDelivery::query()->pluck('status')
            ->map(fn (NotificationDeliveryStatus $status): string => $status->value)
            ->unique()->values()->all())
        ->toBe([NotificationDeliveryStatus::Queued->value])
        ->and(NotificationDelivery::query()
            ->where('template_key', NotificationEventKey::InvoiceOverdue7->value)
            ->count())->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('template_key', NotificationEventKey::InvoiceOverdue30->value)
            ->count())->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('template_key', NotificationEventKey::InvoiceOverdue60->value)
            ->count())->toBe(1);

    $this->artisan('notifications:overdue-invoices')->assertSuccessful();

    expect(NotificationDelivery::query()
        ->where('subject_document_type', $invoice->getMorphClass())
        ->where('subject_document_id', $invoice->getKey())
        ->count())->toBe(3);
});

it('only sends thresholds actually reached', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = wp210OverdueInvoice($customer, 8);

    $this->artisan('notifications:overdue-invoices')->assertSuccessful();

    expect(NotificationDelivery::query()
        ->where('subject_document_type', $invoice->getMorphClass())
        ->where('subject_document_id', $invoice->getKey())
        ->pluck('template_key')
        ->all())->toBe([NotificationEventKey::InvoiceOverdue7->value]);
});

it('stops chasing paid and written off invoices', function (): void {
    $customer = CustomerProfile::factory()->create();

    $paid = wp210OverdueInvoice($customer, 61, [
        'amount_paid' => '100.00',
    ]);
    $writtenOff = wp210OverdueInvoice($customer, 61, [
        'status' => InvoiceStatus::WrittenOff,
    ]);
    $cancelled = wp210OverdueInvoice($customer, 61, [
        'status' => InvoiceStatus::Cancelled,
    ]);

    $this->artisan('notifications:overdue-invoices')->assertSuccessful();

    expect(NotificationDelivery::query()
        ->whereIn('subject_document_id', [$paid->getKey(), $writtenOff->getKey(), $cancelled->getKey()])
        ->count())->toBe(0);
});

it('seeds localized business notification templates idempotently', function (): void {
    $before = NotificationTemplate::query()->count();
    (new NotificationTemplateSeeder)->run();

    expect(NotificationTemplate::query()->count())->toBe($before)
        ->and($before)->toBeGreaterThan(8)
        ->and(NotificationTemplate::query()
            ->where('key', NotificationEventKey::InvoiceOverdue7->value)
            ->where('locale', 'ar')
            ->exists())->toBeTrue()
        ->and(NotificationTemplate::query()
            ->where('key', NotificationEventKey::InvoiceIssued->value)
            ->where('locale', 'en')
            ->exists())->toBeTrue()
        ->and(NotificationTemplate::query()
            ->where('key', NotificationEventKey::StockLow->value)
            ->where('locale', 'ar')
            ->where('channel', 'database')
            ->exists())->toBeTrue();
});
