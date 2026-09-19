<?php

declare(strict_types=1);

use App\Enums\NotificationEventKey;
use App\Models\Bill;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\InventoryLot;
use App\Models\NotificationDelivery;
use App\Models\PurchaseOrder;
use App\Models\ReceivableWriteOff;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function seedReminderCoverageTemplates(): void
{
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
}

it('queues expiring lot reminders once and ignores empty physical lots', function (): void {
    seedReminderCoverageTemplates();
    $admin = User::factory()->admin()->create();
    InventoryLot::factory()->create([
        'expires_at' => today()->addDay(),
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
    ]);
    InventoryLot::factory()->canonical()->create([
        'expires_at' => today()->addDay(),
    ]);

    expect(Artisan::call('notifications:expiring-lots'))->toBe(0);
    $firstCount = NotificationDelivery::query()
        ->where('notifiable_id', $admin->getKey())
        ->where('template_key', NotificationEventKey::LotExpiring->value)
        ->count();

    expect($firstCount)->toBe(1);
    expect(Artisan::call('notifications:expiring-lots'))->toBe(0);
    expect(NotificationDelivery::query()
        ->where('notifiable_id', $admin->getKey())
        ->where('template_key', NotificationEventKey::LotExpiring->value)
        ->count())->toBe($firstCount);
});

it('queues visit due reminders once for the assigned employee', function (): void {
    seedReminderCoverageTemplates();
    $employee = EmployeeProfile::factory()->create();
    $visit = CustomerVisit::factory()->for($employee, 'employee')->create(['planned_at' => now()]);
    $recipient = $employee->user;
    expect($recipient)->toBeInstanceOf(User::class);

    expect(Artisan::call('notifications:visits-due'))->toBe(0);

    $firstCount = NotificationDelivery::query()
        ->where('notifiable_id', $recipient->getKey())
        ->where('subject_document_id', $visit->getKey())
        ->where('template_key', NotificationEventKey::VisitDue->value)
        ->count();

    expect($firstCount)->toBe(1);
    expect(Artisan::call('notifications:visits-due'))->toBe(0);
    expect(NotificationDelivery::query()
        ->where('notifiable_id', $recipient->getKey())
        ->where('subject_document_id', $visit->getKey())
        ->where('template_key', NotificationEventKey::VisitDue->value)
        ->count())->toBe($firstCount);
});

it('queues pending approval reminders for every supported document family only once', function (): void {
    seedReminderCoverageTemplates();
    $admin = User::factory()->admin()->create();

    $documents = [
        PurchaseOrder::factory()->pendingApproval()->create(),
        Bill::factory()->create(),
        Expense::factory()->create(),
        Refund::factory()->create(),
        ReceivableWriteOff::factory()->create(),
    ];

    expect(Artisan::call('notifications:pending-approvals'))->toBe(0);

    foreach ($documents as $document) {
        expect(NotificationDelivery::query()
            ->where('notifiable_id', $admin->getKey())
            ->where('subject_document_type', $document->getMorphClass())
            ->where('subject_document_id', $document->getKey())
            ->where('template_key', NotificationEventKey::ApprovalPending->value)
            ->count())->toBe(1);
    }

    $firstCount = NotificationDelivery::query()
        ->where('notifiable_id', $admin->getKey())
        ->where('template_key', NotificationEventKey::ApprovalPending->value)
        ->count();

    expect($firstCount)->toBe(5);
    expect(Artisan::call('notifications:pending-approvals'))->toBe(0);
    expect(NotificationDelivery::query()
        ->where('notifiable_id', $admin->getKey())
        ->where('template_key', NotificationEventKey::ApprovalPending->value)
        ->count())->toBe($firstCount);
});
