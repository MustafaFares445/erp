<?php

declare(strict_types=1);

use App\Enums\MaintenanceKind;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Events\EquipmentInstallationMilestone;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Support\EquipmentInstallationService;
use App\Services\Support\ServiceAppointmentService;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
});

/** @return array<string, list<string>> deliveries per template key as "class:id:channel" */
function installationDeliveries(): array
{
    return NotificationDelivery::query()
        ->whereIn('template_key', array_map(fn (NotificationEventKey $key): string => $key->value, [
            NotificationEventKey::InstallationScheduled,
            NotificationEventKey::InstallationCompleted,
            NotificationEventKey::CommissioningPassed,
            NotificationEventKey::CommissioningFailed,
            NotificationEventKey::CustomerAcceptanceRecorded,
        ]))
        ->orderBy('id')
        ->get()
        ->groupBy('template_key')
        ->map(fn ($rows) => $rows->map(fn (NotificationDelivery $d): string => class_basename($d->notifiable_type).':'.$d->notifiable_id.':'.$d->channel->value)->all())
        ->all();
}

/** @return array{0: MaintenanceRecord, 1: User, 2: User} record, customer login, manager */
function notifiableInstallation(): array
{
    [$customer, , $record] = InstallationFixtures::scenario();
    $customerUser = User::factory()->customer()->create(['email' => 'lab@example.test']);
    $customer->update(['user_id' => $customerUser->getKey()]);

    return [$record, $customerUser, InstallationFixtures::manager()];
}

it('delivers each milestone to the right recipients without duplicates', function (): void {
    [$record, $customerUser, $manager] = notifiableInstallation();
    $service = app(EquipmentInstallationService::class);
    $technician = InstallationFixtures::agent();
    EmployeeProfile::factory()->create(['user_id' => $technician->id]);
    $installation = $service->createForDeliveredEquipment($record, $manager);

    $service->completeInstallation($installation, $technician);
    $service->failCommissioning($installation, $technician, 'Drift in zone 2');
    InstallationFixtures::passAllChecks($installation->fresh(), $technician);
    $service->completeCommissioning($installation, $technician);
    $service->acceptByCustomer($installation, 'Dr. Salem', $manager);

    $deliveries = installationDeliveries();
    $customerRows = ['User:'.$customerUser->id.':mail', 'User:'.$customerUser->id.':database'];
    $managerRows = ['User:'.$manager->id.':mail', 'User:'.$manager->id.':database'];
    $technicianRows = ['User:'.$technician->id.':mail', 'User:'.$technician->id.':database'];

    foreach ([
        NotificationEventKey::InstallationCompleted->value => [[...$customerRows, ...$managerRows], $technicianRows],
        NotificationEventKey::CommissioningPassed->value => [[...$customerRows, ...$managerRows], $technicianRows],
        NotificationEventKey::CommissioningFailed->value => [[...$technicianRows, ...$managerRows], $customerRows],
        NotificationEventKey::CustomerAcceptanceRecorded->value => [[...$customerRows, ...$managerRows], $technicianRows],
    ] as $key => [$expected, $unexpected]) {
        expect($deliveries[$key])->toContain(...$expected)
            ->and(count($deliveries[$key]))->toBe(count(array_unique($deliveries[$key])), "duplicate delivery for {$key}");

        foreach ($unexpected as $row) {
            expect($deliveries[$key])->not->toContain($row);
        }
    }
});

it('addresses deliveries to the maintenance request and uses the declared variables', function (): void {
    [$record, , $manager] = notifiableInstallation();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $manager);
    $service->completeInstallation($installation, $manager);
    $service->failCommissioning($installation, $manager, 'Drift in zone 2');

    $failed = NotificationDelivery::query()->where('template_key', NotificationEventKey::CommissioningFailed->value)->get();

    expect($failed)->not->toBeEmpty()
        ->and($failed->every(fn (NotificationDelivery $d): bool => $d->subject_document_type === $record->getMorphClass() && (int) $d->subject_document_id === $record->id))->toBeTrue()
        ->and($failed->every(fn (NotificationDelivery $d): bool => $d->status !== NotificationDeliveryStatus::Failed))->toBeTrue()
        ->and($failed->first()->variables)->toMatchArray([
            'maintenance_reference' => '#'.$record->id,
            'serial_number' => $record->serializedInventoryUnit->serial_number,
            'reason' => 'Drift in zone 2',
        ]);

    expect($failed->first()->variables)->not->toHaveKey('scheduled_at');
});

it('describes the acceptance outcome in the notification', function (): void {
    [$record, , $manager] = notifiableInstallation();
    $service = app(EquipmentInstallationService::class);
    $installation = InstallationFixtures::installation($record, $manager, 3);

    $service->rejectByCustomer($installation, 'Exhaust noise', $manager);

    $reason = NotificationDelivery::query()->where('template_key', NotificationEventKey::CustomerAcceptanceRecorded->value)->first()->variables['reason'];

    expect($reason)->toBe('Rejected: Exhaust noise');
});

it('notifies the customer and the assigned technician when an installation visit is scheduled', function (): void {
    [$record, $customerUser, $manager] = notifiableInstallation();
    $technicianProfile = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->getKey()]);

    app(ServiceAppointmentService::class)->createScheduled(
        $task,
        $technicianProfile,
        now()->addDay()->startOfHour(),
        now()->addDay()->startOfHour()->addHours(2),
        null,
        $manager,
    );

    $rows = installationDeliveries()[NotificationEventKey::InstallationScheduled->value];

    expect($rows)->toContain('User:'.$customerUser->id.':mail')
        ->and($rows)->toContain('User:'.$technicianProfile->user_id.':mail')
        ->and($rows)->not->toContain('User:'.$manager->id.':mail')
        ->and(NotificationDelivery::query()->where('template_key', 'installation.scheduled')->first()->variables)->toHaveKey('scheduled_at');
});

it('does not notify for scheduling a non-installation visit', function (): void {
    [$record, , $manager] = notifiableInstallation();
    $record->update(['maintenance_kind' => MaintenanceKind::Corrective]);
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->getKey()]);

    app(ServiceAppointmentService::class)->createScheduled(
        $task,
        EmployeeProfile::factory()->create(),
        now()->addDay()->startOfHour(),
        now()->addDay()->startOfHour()->addHours(2),
        null,
        $manager,
    );

    expect(NotificationDelivery::query()->where('template_key', 'installation.scheduled')->count())->toBe(0);
});

it('respects the installation feature flag in the listener', function (): void {
    [$record, , $manager] = notifiableInstallation();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $manager);

    config(['support.equipment_installation_enabled' => true]);
    $service->completeInstallation($installation, $manager);
    $before = NotificationDelivery::query()->count();

    config(['support.equipment_installation_enabled' => false]);
    EquipmentInstallationMilestone::dispatch($record, NotificationEventKey::CommissioningPassed);

    expect($before)->toBeGreaterThan(0)
        ->and(NotificationDelivery::query()->count())->toBe($before);
});

it('uses the customer language and ships english and arabic templates for every installation event', function (): void {
    foreach ([
        NotificationEventKey::InstallationScheduled,
        NotificationEventKey::InstallationCompleted,
        NotificationEventKey::CommissioningPassed,
        NotificationEventKey::CommissioningFailed,
        NotificationEventKey::CustomerAcceptanceRecorded,
    ] as $key) {
        foreach (['en', 'ar'] as $locale) {
            foreach ([NotificationChannel::Mail, NotificationChannel::Database] as $channel) {
                expect(NotificationTemplate::query()->where(['key' => $key->value, 'locale' => $locale, 'channel' => $channel->value, 'is_active' => true])->exists())
                    ->toBeTrue("missing {$locale}/{$channel->value} template for {$key->value}");
            }

            expect($key->label())->not->toStartWith('enums.')
                ->and(__('notification_templates.events.'.str_replace('.', '_', $key->value).'.name', [], $locale))->not->toStartWith('notification_templates.');
        }
    }

    [$record, $customerUser, $manager] = notifiableInstallation();
    $customerUser->forceFill(['locale' => 'ar'])->save();
    $installation = app(EquipmentInstallationService::class)->createForDeliveredEquipment($record, $manager);
    app(EquipmentInstallationService::class)->completeInstallation($installation, $manager);

    $delivery = NotificationDelivery::query()
        ->where('template_key', 'installation.completed')
        ->where('notifiable_id', $customerUser->id)
        ->where('channel', 'mail')
        ->firstOrFail();

    expect($delivery->locale)->toBe('ar');
});

it('sends one delivery per channel when a recipient holds several roles in the milestone', function (): void {
    [$record, $customerUser, $manager] = notifiableInstallation();
    $technicianProfile = EmployeeProfile::factory()->create(['user_id' => $customerUser->id]);
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->getKey()]);

    app(ServiceAppointmentService::class)->createScheduled(
        $task,
        $technicianProfile,
        now()->addDay()->startOfHour(),
        now()->addDay()->startOfHour()->addHours(2),
        null,
        $manager,
    );

    $rows = installationDeliveries()[NotificationEventKey::InstallationScheduled->value];

    expect($rows)->toBe(['User:'.$customerUser->id.':database', 'User:'.$customerUser->id.':mail']);
});
