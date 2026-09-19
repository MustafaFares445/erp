<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
});

it('renders a styled, navigable page when a role is denied a resource', function (): void {
    // Previously this returned Symfony's bare "403 Forbidden" text page: no
    // panel shell, no explanation and no way back into the app.
    $agent = User::factory()->admin()->create();
    $agent->syncRoles([DashboardRole::SupportAgent->value]);

    $this->actingAs($agent)
        ->get('/admin/invoices')
        ->assertForbidden()
        ->assertSee(__('admin.errors.403.title'))
        ->assertSee(__('admin.errors.back_to_dashboard'))
        ->assertSee(url('/admin'));
});

it('renders a styled page for a missing record', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->syncRoles([DashboardRole::SystemAdmin->value]);

    $this->actingAs($admin)
        ->get('/admin/invoices/999999')
        ->assertNotFound()
        ->assertSee(__('admin.errors.404.title'))
        ->assertSee(__('admin.errors.back_to_dashboard'));
});
