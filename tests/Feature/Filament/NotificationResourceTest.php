<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\NotificationDeliveries\Widgets\FailedNotifications;
use App\Filament\Resources\NotificationPreferences\Pages\ListNotificationPreferences;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders notification templates deliveries and preferences for an administrator', function (): void {
    $admin = User::factory()->admin()->create();

    $template = NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Invoice {{ invoice_number }}',
        'body' => 'Invoice {{ invoice_number }}',
        'variables' => ['invoice_number'],
        'is_active' => true,
    ]);
    $delivery = NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $admin->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => $admin->email,
        'status' => NotificationDeliveryStatus::Failed,
        'attempt' => 1,
        'error' => 'Transport failed',
        'failed_at' => now(),
    ]);
    $preference = NotificationPreference::query()->create([
        'user_id' => $admin->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);

    Livewire::actingAs($admin)
        ->test(ListNotificationTemplates::class)
        ->assertCanSeeTableRecords([$template]);

    Livewire::actingAs($admin)
        ->test(ListNotificationDeliveries::class)
        ->assertCanSeeTableRecords([$delivery])
        ->assertSeeLivewire(FailedNotifications::class);

    Livewire::actingAs($admin)
        ->test(ListNotificationPreferences::class)
        ->assertCanSeeTableRecords([$preference]);

    expect(NotificationDeliveryResource::canCreate())->toBeFalse();

    $widget = app(FailedNotifications::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('1')
        ->and($stats[0]->getColor())->toBe('danger');
});

it('links the failed-notifications card to the delivery history filtered to failures', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $delivery = static fn (NotificationDeliveryStatus $status): NotificationDelivery => NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $admin->getKey(),
        'template_key' => NotificationEventKey::InvoiceIssued->value,
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => $admin->email,
        'status' => $status,
        'attempt' => 1,
    ]);
    $failed = $delivery(NotificationDeliveryStatus::Failed);
    $sent = $delivery(NotificationDeliveryStatus::Sent);

    $widget = app(FailedNotifications::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    parse_str((string) parse_url((string) $stats[0]->getUrl(), PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)
        ->test(ListNotificationDeliveries::class)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);
});

it('shows a success state when no notification failed in the last 24 hours', function (): void {
    $widget = app(FailedNotifications::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[0]->getValue())->toBe('0')
        ->and($stats[0]->getDescription())->toBe('No failed business notifications in the last 24 hours.')
        ->and($stats[0]->getColor())->toBe('success');
});
