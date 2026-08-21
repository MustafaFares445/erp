<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\User;
use App\Policies\MaintenanceRecordPolicy;
use App\Policies\MaintenanceTaskPolicy;
use App\Policies\SlaPolicyPolicy;
use App\Policies\TicketPolicy;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('denies restore to a Support Manager on tickets, maintenance requests, and service records', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    expect(app(TicketPolicy::class)->restore($manager))->toBeFalse()
        ->and(app(MaintenanceRecordPolicy::class)->restore($manager))->toBeFalse()
        ->and(app(MaintenanceTaskPolicy::class)->restore($manager))->toBeFalse();
});

it('grants restore only to System Admin on tickets, maintenance requests, and service records', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    expect(app(TicketPolicy::class)->restore($admin))->toBeTrue()
        ->and(app(TicketPolicy::class)->restoreAny($admin))->toBeTrue()
        ->and(app(MaintenanceRecordPolicy::class)->restore($admin))->toBeTrue()
        ->and(app(MaintenanceTaskPolicy::class)->restore($admin))->toBeTrue();
});

it('lets a Support Manager view a single SLA policy row', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    expect(app(SlaPolicyPolicy::class)->view($manager))->toBeTrue();
});

it('denies posting a ticket message to a user holding no message permission at all', function (): void {
    $outsider = User::factory()->customer()->create();
    $ticket = Ticket::factory()->create();

    expect(app(TicketPolicy::class)->message($outsider, $ticket))->toBeFalse();
});
