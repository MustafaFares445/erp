<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationDigestCadence;
use App\Enums\NotificationEventKey;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationDigestService;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function wp44Template(?int $rateLimit = null): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Invoice {{ name }}',
        'body' => 'Hello {{ name }}',
        'variables' => ['name'],
        'is_active' => true,
        'rate_limit_per_hour' => $rateLimit,
    ]);
}

it('records per-template rate limit suppression as an explicit decision', function (): void {
    wp44Template(1);
    Notification::fake();
    $user = User::factory()->create(['email' => 'rate@example.com']);

    $first = app(NotificationDispatcher::class)->dispatch($user, NotificationEventKey::InvoiceIssued, ['name' => 'A']);
    $second = app(NotificationDispatcher::class)->dispatch($user, NotificationEventKey::InvoiceIssued, ['name' => 'B']);

    expect($first->status)->toBe(NotificationDeliveryStatus::Queued)
        ->and($second->status)->toBe(NotificationDeliveryStatus::Suppressed)
        ->and($second->decision)->toBe('rate_limited')
        ->and($second->queued_at)->toBeNull();
});

it('defers immediate notifications that arrive during quiet hours', function (): void {
    wp44Template();
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-12 23:00:00');

    $user = User::factory()->create(['email' => 'quiet@example.com']);
    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'enabled' => true,
        'digest_cadence' => NotificationDigestCadence::Immediate,
        'quiet_hours_start' => '22:00:00',
        'quiet_hours_end' => '07:00:00',
    ]);

    $delivery = app(NotificationDispatcher::class)->dispatch($user, NotificationEventKey::InvoiceIssued, ['name' => 'QUIET']);

    expect($delivery->status)->toBe(NotificationDeliveryStatus::Deferred)
        ->and($delivery->decision)->toBe('quiet_hours')
        ->and($delivery->deferred_until?->format('Y-m-d H:i'))->toBe('2026-09-13 07:00');

    Notification::assertNothingSent();
    CarbonImmutable::setTestNow();
});

it('batches due daily notifications into one digest delivery', function (): void {
    wp44Template();
    Notification::fake();
    $user = User::factory()->create(['email' => 'digest@example.com']);
    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'enabled' => true,
        'digest_cadence' => NotificationDigestCadence::Daily,
    ]);

    $one = app(NotificationDispatcher::class)->dispatch($user, NotificationEventKey::InvoiceIssued, ['name' => 'ONE']);
    $two = app(NotificationDispatcher::class)->dispatch($user, NotificationEventKey::InvoiceIssued, ['name' => 'TWO']);

    NotificationDelivery::query()
        ->whereKey([$one->getKey(), $two->getKey()])
        ->update(['deferred_until' => now()->subMinute()]);

    $result = app(NotificationDigestService::class)->processDue();
    $digest = NotificationDelivery::query()->where('template_key', 'system.digest')->sole();

    expect($result['digests'])->toBe(1)
        ->and($digest->variables['source_delivery_ids'])->toHaveCount(2)
        ->and($digest->decision)->toBe('digest_delivery');
});
