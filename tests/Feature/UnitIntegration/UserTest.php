<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('denies access to an unknown panel by default, regardless of user type', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    $otherPanel = Panel::make()->id('other');

    expect($admin->canAccessPanel($otherPanel))->toBeFalse()
        ->and($customer->canAccessPanel($otherPanel))->toBeFalse();
});

it('reports isAdmin based on the user_type attribute', function (): void {
    $admin = User::factory()->admin()->make();
    $customer = User::factory()->customer()->make();

    expect($admin->isAdmin())->toBeTrue()
        ->and($customer->isAdmin())->toBeFalse();
});

it('casts user_type to the UserType enum', function (): void {
    $user = User::factory()->employee()->make();

    expect($user->user_type)->toBe(UserType::Employee);
});
