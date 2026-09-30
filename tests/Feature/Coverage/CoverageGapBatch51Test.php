<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers every order workflow milestone table color branch', function (): void {
    (new SalesPermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::SalesOfficer->value);

    $table = Livewire::actingAs($actor)
        ->test(ListOrders::class)
        ->instance()
        ->getTable();

    $column = $table->getColumn('workflow_milestone');

    expect($column?->getColor('Cancelled'))->toBe('danger')
        ->and($column?->getColor('Closed'))->toBe('success')
        ->and($column?->getColor('Draft'))->toBe('gray')
        ->and($column?->getColor('Awaiting Release'))->toBe('warning')
        ->and($column?->getColor('Supply Blocked'))->toBe('warning')
        ->and($column?->getColor('Awaiting Logistics Allocation'))->toBe('warning')
        ->and($column?->getColor('Partially Allocated'))->toBe('info')
        ->and($column?->getColor('Ready to Dispatch'))->toBe('info')
        ->and($column?->getColor('In Transit'))->toBe('info')
        ->and($column?->getColor('Invoice Pending'))->toBe('warning')
        ->and($column?->getColor('Payment Pending'))->toBe('warning')
        ->and($column?->getColor('Delivered'))->toBe('success')
        ->and($column?->getColor('Unknown milestone'))->toBe('primary');
});
