<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a system administrator to access the admin panel', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('denies a customer access to the admin panel', function (): void {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('denies an employee access to the admin panel', function (): void {
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee)->get('/admin')->assertForbidden();
});

it('redirects an unauthenticated guest to the admin login page', function (): void {
    $response = $this->get('/admin');

    $response->assertStatus(302);

    expect((string) $response->headers->get('Location'))->toContain('/admin/login');
});
