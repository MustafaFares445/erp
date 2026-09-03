<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\NotificationPreferences\Pages\ListNotificationPreferences;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Widgets\FailedNotifications;
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
        ->assertCanSeeTableRecords([$delivery]);

    Livewire::actingAs($admin)
        ->test(ListNotificationPreferences::class)
        ->assertCanSeeTableRecords([$preference]);

    expect(NotificationDeliveryResource::canCreate())->toBeFalse();

    $widget = app(FailedNotifications::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('1');
});
