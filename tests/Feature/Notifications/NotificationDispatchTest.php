<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Listeners\MarkNotificationDeliverySent;
use App\Models\CustomerProfile;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BusinessNotification;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function wp210DispatchTemplate(
    NotificationChannel $channel = NotificationChannel::Mail,
    string $locale = 'en',
): NotificationTemplate {
    return NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => $locale,
        'channel' => $channel,
        'subject' => 'Invoice {{ name }}',
        'body' => 'Hello {{ name }}',
        'variables' => ['name'],
        'is_active' => true,
    ]);
}

it('queues mail delivery with recipient and subject evidence', function (): void {
    wp210DispatchTemplate();
    Notification::fake();

    $user = User::factory()->create(['email' => 'Customer@Example.com']);

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-1'],
        $user,
    );

    expect($delivery->status)->toBe(NotificationDeliveryStatus::Queued)
        ->and($delivery->channel)->toBe(NotificationChannel::Mail)
        ->and($delivery->route)->toBe('customer@example.com')
        ->and($delivery->variables)->toBe(['name' => 'INV-1'])
        ->and($delivery->queued_at)->not->toBeNull()
        ->and($delivery->notifiable?->is($user))->toBeTrue()
        ->and($delivery->subjectDocument?->is($user))->toBeTrue();

    Notification::assertSentOnDemand(
        BusinessNotification::class,
        fn (BusinessNotification $notification, array $channels, object $notifiable): bool => $channels === ['mail']
            && $notification->deliveryId === $delivery->getKey()
            && ($notifiable->routes['mail'] ?? null) === 'customer@example.com',
    );
});

it('suppresses a disabled user preference and preserves the preference relation', function (): void {
    wp210DispatchTemplate();
    Notification::fake();

    $user = User::factory()->create();
    $preference = NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-2'],
    );

    expect($preference->channel)->toBe(NotificationChannel::Mail)
        ->and($preference->enabled)->toBeFalse()
        ->and($preference->user->is($user))->toBeTrue()
        ->and($delivery->status)->toBe(NotificationDeliveryStatus::Suppressed)
        ->and($delivery->queued_at)->toBeNull();

    Notification::assertNothingSent();
});

it('suppresses a communication address at send time', function (): void {
    wp210DispatchTemplate();
    Notification::fake();

    $user = User::factory()->create(['email' => 'blocked@example.com']);

    DB::table('communication_suppressions')->insert([
        'channel' => NotificationChannel::Mail->value,
        'address' => 'blocked@example.com',
        'reason' => 'unsubscribed',
        'suppressed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-3'],
    );

    expect($delivery->status)->toBe(NotificationDeliveryStatus::Suppressed);

    Notification::assertNothingSent();
});

it('records unsupported channels and invalid routes as failed without throwing', function (): void {
    wp210DispatchTemplate(NotificationChannel::Sms);
    wp210DispatchTemplate(NotificationChannel::Mail);

    $user = User::factory()->create(['email' => 'not-an-email']);

    $sms = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-SMS'],
        channel: NotificationChannel::Sms,
    );
    $mail = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-MAIL'],
    );

    expect($sms->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($sms->error)->toContain('No provider is configured')
        ->and($sms->failed_at)->not->toBeNull()
        ->and($mail->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($mail->error)->toBe('The notification recipient has no valid email route.');
});

it('queues database delivery only for application users', function (): void {
    wp210DispatchTemplate(NotificationChannel::Database);
    Notification::fake();

    $user = User::factory()->create();

    $queued = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'IN-APP'],
        channel: NotificationChannel::Database,
    );

    expect($queued->status)->toBe(NotificationDeliveryStatus::Queued)
        ->and($queued->route)->toBeNull();

    Notification::assertSentTo($user, BusinessNotification::class);

    $customer = CustomerProfile::factory()->create();
    $failed = app(NotificationDispatcher::class)->dispatch(
        $customer,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'NO-USER'],
        channel: NotificationChannel::Database,
    );

    expect($failed->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($failed->error)->toBe('Database notifications require an application user recipient.');
});

it('uses a model preferred language when one is present', function (): void {
    wp210DispatchTemplate(locale: 'ar');
    Notification::fake();

    $customer = CustomerProfile::factory()->create(['email' => 'arabic@example.com']);
    $customer->setAttribute('preferred_language', 'ar');

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $customer,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'AR'],
    );

    expect($delivery->locale)->toBe('ar');
});

it('records synchronous transport failures instead of leaking them to callers', function (): void {
    wp210DispatchTemplate();

    $user = User::factory()->create();

    Notification::shouldReceive('route')
        ->once()
        ->andThrow(new \RuntimeException('transport boom'));

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-FAIL'],
    );

    expect($delivery->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($delivery->error)->toBe('transport boom');
});

it('requeues failed deliveries below the cap and rejects invalid retry states', function (): void {
    wp210DispatchTemplate();
    Notification::fake();

    $user = User::factory()->create(['email' => 'retry@example.com']);

    $delivery = NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'retry@example.com',
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 1,
        'variables' => ['name' => 'RETRY'],
        'failed_at' => now(),
    ]);

    $retried = app(NotificationDispatcher::class)->retry($delivery);

    expect($retried->status)->toBe(NotificationDeliveryStatus::Queued)
        ->and($retried->attempt)->toBe(2)
        ->and($retried->failed_at)->toBeNull()
        ->and($retried->error)->toBeNull();

    $retried->forceFill(['status' => NotificationDeliveryStatus::Sent])->save();

    expect(fn () => app(NotificationDispatcher::class)->retry($retried))
        ->toThrow(\DomainException::class, 'Only failed notification deliveries below the retry cap can be re-queued.');

    $retried->forceFill([
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 3,
    ])->save();

    expect(fn () => app(NotificationDispatcher::class)->retry($retried))
        ->toThrow(\DomainException::class, 'Only failed notification deliveries below the retry cap can be re-queued.');
});

it('refuses retry when the original recipient no longer exists', function (): void {
    wp210DispatchTemplate();

    $delivery = NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => 999999999,
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'missing@example.com',
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 1,
        'variables' => ['name' => 'MISSING'],
    ]);

    expect(fn () => app(NotificationDispatcher::class)->retry($delivery))
        ->toThrow(\DomainException::class, 'The notification recipient no longer exists.');
});

it('marks queued deliveries sent from framework events and records final queue failure', function (): void {
    $user = User::factory()->create();
    $delivery = NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Database,
        'locale' => 'en',
        'status' => NotificationDeliveryStatus::Queued,
        'attempt' => 1,
    ]);

    $business = new BusinessNotification(
        (int) $delivery->getKey(),
        NotificationChannel::Database,
        'Subject',
        'Body',
    );

    app(MarkNotificationDeliverySent::class)->handle(
        new NotificationSent($user, $business, 'database', null),
    );

    expect($delivery->refresh()->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->error)->toBeNull();

    $other = new class extends LaravelNotification
    {
        public function via(mixed $notifiable): array
        {
            return [];
        }
    };

    app(MarkNotificationDeliverySent::class)->handle(
        new NotificationSent($user, $other, 'database', null),
    );

    $business->failed(new \RuntimeException(str_repeat('x', 550)));

    expect($delivery->refresh()->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and(mb_strlen((string) $delivery->error))->toBe(500)
        ->and($delivery->failed_at)->not->toBeNull();
});

it('exposes mail database and unsupported channel payload behavior', function (): void {
    $mail = new BusinessNotification(1, NotificationChannel::Mail, 'Subject', 'Body');
    $database = new BusinessNotification(2, NotificationChannel::Database, null, 'Database body');
    $sms = new BusinessNotification(3, NotificationChannel::Sms, null, 'Sms body');
    $whatsapp = new BusinessNotification(4, NotificationChannel::Whatsapp, null, 'Whatsapp body');

    expect($mail->via(null))->toBe(['mail'])
        ->and($database->via(null))->toBe(['database'])
        ->and($sms->via(null))->toBe([])
        ->and($whatsapp->via(null))->toBe([])
        ->and($mail->toMail(null)->subject)->toBe('Subject')
        ->and($database->toMail(null)->subject)->toBeNull()
        ->and($database->toArray(null))->toBe([
            'delivery_id' => 2,
            'subject' => null,
            'body' => 'Database body',
        ]);
});

it('retries failed deliveries from the command and reports irrecoverable rows', function (): void {
    wp210DispatchTemplate();
    Notification::fake();

    $user = User::factory()->create(['email' => 'command-retry@example.com']);

    NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'command-retry@example.com',
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 1,
        'variables' => ['name' => 'GOOD'],
    ]);
    NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => 999999999,
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'missing@example.com',
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 1,
        'variables' => ['name' => 'BAD'],
    ]);
    NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'capped@example.com',
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 3,
        'variables' => ['name' => 'CAPPED'],
    ]);

    $this->artisan('notifications:retry-failed')->assertExitCode(1);

    expect(NotificationDelivery::query()->where('route', 'command-retry@example.com')->value('attempt'))->toBe(2)
        ->and(NotificationDelivery::query()->where('route', 'capped@example.com')->value('attempt'))->toBe(3);

    NotificationDelivery::query()
        ->where('status', NotificationDeliveryStatus::Failed->value)
        ->where('attempt', '<', 3)
        ->delete();

    $this->artisan('notifications:retry-failed')->assertSuccessful();
});
