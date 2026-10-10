<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('enforces role and state restrictions on the Purchase Order draft and sent forms', function (): void {
    (new PurchasePermissionSeeder)->run();
    $officer = User::factory()->create();
    $officer->assignRole(DashboardRole::PurchasingOfficer->value);

    $manager = User::factory()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);

    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $draft = PurchaseOrder::factory()->create();
    $sent = PurchaseOrder::factory()->sent()->create();

    expect($draft->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($officer->can('update', $draft))->toBeTrue()
        ->and($officer->can('submit', $draft))->toBeTrue()
        ->and($officer->can('approve', $draft))->toBeFalse()
        ->and($officer->can('send', $draft))->toBeFalse()
        ->and($officer->can('update', $sent))->toBeFalse()
        ->and($officer->can('submit', $sent))->toBeFalse()
        ->and($manager->can('update', $sent))->toBeFalse()
        ->and($manager->can('receive', $sent))->toBeFalse()
        ->and($reviewer->can('create', PurchaseOrder::class))->toBeFalse();
});

it('denies Purchasing receipt rights for every purchase order status', function (): void {
    (new PurchasePermissionSeeder)->run();
    $buyer = User::factory()->create();
    $buyer->assignRole(DashboardRole::PurchasingManager->value);
    foreach (PurchaseOrderStatus::cases() as $status) {
        $record = PurchaseOrder::factory()->create(['status' => $status]);
        expect($buyer->can('receive', $record))->toBeFalse($status->value);
    }
});
