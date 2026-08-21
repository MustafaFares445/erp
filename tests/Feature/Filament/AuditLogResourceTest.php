<?php

declare(strict_types=1);

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\PricingTier;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provides an immutable audit list and view to CRM audit reviewers', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $entity = PricingTier::factory()->create();
    $auditLog = AuditLog::factory()->create([
        'subject_type' => $entity::class,
        'subject_id' => $entity->id,
        'causer_type' => User::class,
        'causer_id' => $reviewer->id,
        'attribute_changes' => ['attributes' => ['discount_value' => 10]],
    ]);

    $this->actingAs($reviewer)
        ->get(AuditLogResource::getUrl())
        ->assertOk();

    Livewire::actingAs($reviewer)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$auditLog]);

    $this->actingAs($reviewer)
        ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
        ->assertOk()
        ->assertSeeText("\u{2014}")
        ->assertSeeText('"discount_value": 10');

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and($reviewer->can('update', $auditLog))->toBeFalse()
        ->and($reviewer->can('delete', $auditLog))->toBeFalse();
});

it('denies audit records to users outside the dashboard audit permission', function (): void {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(AuditLogResource::getUrl())
        ->assertForbidden();
});

it('configures the audit infolist and filters immutable records by date range', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $inside = AuditLog::factory()->create(['created_at' => '2026-08-02 12:00:00']);
    $outside = AuditLog::factory()->create(['created_at' => '2026-08-01 12:00:00']);

    expect(AuditLogResource::infolist(Schema::make())->getComponents())->not->toBeEmpty();

    Livewire::actingAs($reviewer)
        ->test(ListAuditLogs::class)
        ->filterTable('created_at', ['from' => '2026-08-02', 'until' => '2026-08-02'])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('filters audit records by subject_id', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $matching = AuditLog::factory()->create(['subject_id' => 42]);
    $other = AuditLog::factory()->create(['subject_id' => 43]);

    Livewire::actingAs($reviewer)
        ->test(ListAuditLogs::class)
        ->filterTable('subject_id', ['value' => 42])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});
