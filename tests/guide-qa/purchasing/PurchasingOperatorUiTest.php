<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Test the worker-local Purchasing form despite the shared Composer classmap.
require_once __DIR__.'/../../../app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderForm.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->officer = User::factory()->admin()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
});

it('renders the Purchasing PO create form in both languages with its heading and input form', function (): void {
    $this->actingAs($this->manager);
    app()->setLocale('en');
    Livewire::test(CreatePurchaseOrder::class)
        ->assertSuccessful()
        ->assertSee('Create Purchase Order');
    app()->setLocale('ar');
    Livewire::test(CreatePurchaseOrder::class)
        ->assertSuccessful()
        ->assertSee('شروط الدفع')
        ->assertSee('أمر الشراء يوثق الالتزام التجاري');
});

it('shows Draft submit to a buyer but hides approval and send without authorization', function (): void {
    $this->actingAs($this->officer);
    $draft = PurchaseOrder::factory()->create();
    $accepted = PurchaseOrder::factory()->accepted()->create();

    Livewire::test(ViewPurchaseOrder::class, ['record' => $draft->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible(TestAction::make('submit'))
        ->assertActionHidden(TestAction::make('approve'))
        ->assertActionHidden(TestAction::make('send'));

    Livewire::test(ViewPurchaseOrder::class, ['record' => $accepted->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('submit'))
        ->assertActionHidden(TestAction::make('send'));
});

it('exposes Approve and Return for revision only to the authorized manager for a pending PO', function (): void {
    $pending = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::PendingApproval]);
    $this->actingAs($this->officer);
    Livewire::test(ViewPurchaseOrder::class, ['record' => $pending->getRouteKey()])
        ->assertActionHidden(TestAction::make('approve'))
        ->assertActionHidden(TestAction::make('reject'));
    $this->actingAs($this->manager);
    Livewire::test(ViewPurchaseOrder::class, ['record' => $pending->getRouteKey()])
        ->assertActionVisible(TestAction::make('approve'))
        ->assertActionVisible(TestAction::make('reject'));
});
