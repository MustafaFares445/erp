<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Events\EquipmentCalibrationMilestone;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationService;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\CalibrationFixtures;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
});

/** @return list<string> deliveries of one template as "class:id:channel" */
function calibrationDeliveries(NotificationEventKey $key): array
{
    return NotificationDelivery::query()
        ->where('template_key', $key->value)
        ->orderBy('id')
        ->get()
        ->map(fn (NotificationDelivery $d): string => class_basename($d->notifiable_type).':'.$d->notifiable_id.':'.$d->channel->value)
        ->all();
}

/** @return array{0: MaintenanceRecord, 1: User, 2: User} record, customer login, manager */
function notifiableCalibration(): array
{
    [$customer, , $record] = CalibrationFixtures::scenario();
    $customerUser = User::factory()->customer()->create(['email' => 'lab@example.test']);
    $customer->update(['user_id' => $customerUser->getKey()]);

    return [$record, $customerUser, InstallationFixtures::manager()];
}

it('tells the customer and support managers when a calibration is completed, once each', function (): void {
    [$record, $customerUser, $manager] = notifiableCalibration();
    $technician = InstallationFixtures::agent();
    EmployeeProfile::factory()->create(['user_id' => $technician->id]);
    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $technician);

    app(EquipmentCalibrationService::class)->complete($calibration, $technician);

    $rows = calibrationDeliveries(NotificationEventKey::CalibrationCompleted);

    expect($rows)->toContain('User:'.$customerUser->id.':mail', 'User:'.$customerUser->id.':database', 'User:'.$manager->id.':mail', 'User:'.$manager->id.':database')
        ->and($rows)->not->toContain('User:'.$technician->id.':mail')
        ->and(count($rows))->toBe(count(array_unique($rows)));
});

it('tells the technician and support managers, but not the customer, when a calibration fails', function (): void {
    [$record, $customerUser, $manager] = notifiableCalibration();
    $technician = InstallationFixtures::agent();
    EmployeeProfile::factory()->create(['user_id' => $technician->id]);
    $calibration = CalibrationFixtures::started($record, $manager);
    $calibration->update(['performed_by_employee_id' => $technician->employeeProfile->id]);

    app(EquipmentCalibrationService::class)->fail($calibration, $manager, 'Drift of 12 C');

    $rows = calibrationDeliveries(NotificationEventKey::CalibrationFailed);

    expect($rows)->toContain('User:'.$technician->id.':mail', 'User:'.$technician->id.':database', 'User:'.$manager->id.':mail')
        ->and($rows)->not->toContain('User:'.$customerUser->id.':mail')
        ->and(count($rows))->toBe(count(array_unique($rows)));
});

it('sends one delivery per channel when the recipient is both manager and technician', function (): void {
    [$record, , $manager] = notifiableCalibration();
    EmployeeProfile::factory()->create(['user_id' => $manager->id]);
    $calibration = CalibrationFixtures::started($record, $manager);

    app(EquipmentCalibrationService::class)->fail($calibration->refresh(), $manager, 'Out of range');

    expect(calibrationDeliveries(NotificationEventKey::CalibrationFailed))->toBe(['User:'.$manager->id.':database', 'User:'.$manager->id.':mail']);
});

it('addresses deliveries to the maintenance request and passes only the declared variables', function (): void {
    [$record, , $manager] = notifiableCalibration();
    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    app(EquipmentCalibrationService::class)->complete($calibration, $manager);

    $completed = NotificationDelivery::query()->where('template_key', NotificationEventKey::CalibrationCompleted->value)->get();

    expect($completed)->not->toBeEmpty()
        ->and($completed->every(fn (NotificationDelivery $d): bool => $d->subject_document_type === $record->getMorphClass() && (int) $d->subject_document_id === $record->id))->toBeTrue()
        ->and($completed->every(fn (NotificationDelivery $d): bool => $d->status !== NotificationDeliveryStatus::Failed))->toBeTrue()
        ->and($completed->first()->variables)->toMatchArray([
            'maintenance_reference' => '#'.$record->id,
            'serial_number' => $record->serializedInventoryUnit->serial_number,
            'result' => 'Passed',
        ])
        ->and($completed->first()->variables)->not->toHaveKey('reason');

    [, , $failedRecord] = CalibrationFixtures::scenario();
    app(EquipmentCalibrationService::class)->fail(CalibrationFixtures::started($failedRecord, $manager), $manager, 'Drift of 12 C');

    $failed = NotificationDelivery::query()->where('template_key', NotificationEventKey::CalibrationFailed->value)->first();

    expect($failed->variables)->toMatchArray(['reason' => 'Drift of 12 C'])
        ->and($failed->variables)->not->toHaveKey('result');
});

it('respects the calibration feature flag in the listener', function (): void {
    [$record, , $manager] = notifiableCalibration();
    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);

    config(['support.calibration_enabled' => true]);
    app(EquipmentCalibrationService::class)->complete($calibration, $manager);
    $before = NotificationDelivery::query()->count();

    config(['support.calibration_enabled' => false]);
    EquipmentCalibrationMilestone::dispatch($record, NotificationEventKey::CalibrationCompleted);

    expect($before)->toBeGreaterThan(0)
        ->and(NotificationDelivery::query()->count())->toBe($before);
});

it('does not notify when a calibration action is rejected', function (): void {
    [$record, , $manager] = notifiableCalibration();
    $calibration = CalibrationFixtures::started($record, $manager);

    expect(fn () => app(EquipmentCalibrationService::class)->complete($calibration, $manager))->toThrow(ValidationException::class)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

it('uses the recipient language and ships english and arabic templates for every calibration event', function (): void {
    foreach ([NotificationEventKey::CalibrationDue, NotificationEventKey::CalibrationCompleted, NotificationEventKey::CalibrationFailed] as $key) {
        foreach (['en', 'ar'] as $locale) {
            foreach ([NotificationChannel::Mail, NotificationChannel::Database] as $channel) {
                expect(NotificationTemplate::query()->where(['key' => $key->value, 'locale' => $locale, 'channel' => $channel->value, 'is_active' => true])->exists())
                    ->toBeTrue("missing {$locale}/{$channel->value} template for {$key->value}");
            }

            expect($key->label())->not->toStartWith('enums.')
                ->and(__('notification_templates.events.'.str_replace('.', '_', $key->value).'.name', [], $locale))->not->toStartWith('notification_templates.');
        }
    }

    [$record, $customerUser, $manager] = notifiableCalibration();
    $customerUser->forceFill(['locale' => 'ar'])->save();
    $calibration = CalibrationFixtures::started($record, $manager);
    CalibrationFixtures::measure($calibration, $manager);
    app(EquipmentCalibrationService::class)->complete($calibration, $manager);

    $delivery = NotificationDelivery::query()
        ->where('template_key', 'calibration.completed')
        ->where('notifiable_id', $customerUser->id)
        ->where('channel', 'mail')
        ->firstOrFail();

    expect($delivery->locale)->toBe('ar');
});
