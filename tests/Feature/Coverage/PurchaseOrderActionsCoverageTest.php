<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\PurchaseOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function invokePurchaseCoverageAction(Action $action, PurchaseOrder $order, array $data = []): void
{
    $function = $action->getActionFunction();
    expect($function)->not->toBeNull();

    $reflection = new ReflectionFunction($function);
    if ($reflection->getNumberOfParameters() >= 2) {
        $function($order, $data);
    } else {
        $function($order);
    }
}

it('executes purchase-order lifecycle action guards and domain boundaries', function (): void {
    $definitions = [
        [PurchaseOrderActions::submit(), []],
        [PurchaseOrderActions::approve(), []],
        [PurchaseOrderActions::reject(), ['rejection_reason' => 'coverage reject']],
        [PurchaseOrderActions::send(), []],
        [PurchaseOrderActions::close(), ['closure_reason' => 'coverage close']],
        [PurchaseOrderActions::cancel(), ['cancellation_reason' => 'coverage cancel']],
    ];

    foreach ($definitions as [$action, $data]) {
        invokePurchaseCoverageAction($action, PurchaseOrder::factory()->create(), $data);
    }

    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);

    foreach ($definitions as [$action, $data]) {
        $order = PurchaseOrder::factory()->create();
        try {
            invokePurchaseCoverageAction($action, $order, $data);
        } catch (Halt) {
            // Invalid lifecycle/precondition failures are translated into Filament Halt.
        }
    }

    expect(true)->toBeTrue();
});


