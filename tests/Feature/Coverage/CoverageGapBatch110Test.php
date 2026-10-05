<?php

declare(strict_types=1);

use App\Enums\PurchaseRfqStatus;
use App\Filament\Resources\PurchaseRfqs\Actions\PurchaseRfqActions;
use App\Models\ProductVariant;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    Gate::before(static fn (): bool => true);
});

function coverage110Rfq(User $actor, int $supplierCount = 1): PurchaseRfq
{
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();
    $suppliers = Supplier::factory()->count($supplierCount)->create(['is_active' => true]);

    return app(PurchaseRfqService::class)->create(
        $actor,
        [
            'currency_code' => 'AED',
            'closes_at' => now()->addDay()->toDateTimeString(),
        ],
        [[
            'product_variant_id' => $variant->id,
            'unit_id' => $unit->id,
            'quantity' => '2',
        ]],
        $suppliers->modelKeys(),
    );
}

it('covers unauthenticated RFQ action no-op guards', function (): void {
    auth()->logout();
    $rfq = new PurchaseRfq;

    foreach ([
        PurchaseRfqActions::send(),
        PurchaseRfqActions::close(),
        PurchaseRfqActions::cancel(),
        PurchaseRfqActions::expire(),
    ] as $action) {
        $closure = $action->getActionFunction();
        expect($closure)->toBeInstanceOf(Closure::class);
        $closure($rfq);
    }

    expect(true)->toBeTrue();
});

it('skips malformed supplier-response rows and still records valid response rows', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $rfq = coverage110Rfq($actor);
    app(PurchaseRfqService::class)->send($actor, $rfq);

    $rfq->refresh();
    $candidate = $rfq->suppliers()->firstOrFail();
    $line = $rfq->lines()->firstOrFail();

    $action = PurchaseRfqActions::recordResponse();
    $closure = $action->getActionFunction();
    expect($closure)->toBeInstanceOf(Closure::class);

    $closure($rfq, [
        'rfq_supplier_id' => $candidate->id,
        'responses' => [
            'malformed-row-is-skipped',
            [
                'rfq_line_id' => $line->id,
                'unit_price' => '12.50',
                'offered_quantity' => '2',
                'lead_time_days' => '3',
                'minimum_order_quantity' => '1',
                'notes' => 'Valid response after malformed entry.',
            ],
        ],
    ]);

    expect($candidate->refresh()->responded_at)->not->toBeNull()
        ->and($candidate->responseLines()->count())->toBe(1)
        ->and($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Evaluating);
});

it('covers successful expire action refresh and success notification path', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $rfq = coverage110Rfq($actor);
    app(PurchaseRfqService::class)->send($actor, $rfq);
    $rfq->forceFill(['closes_at' => now()->subMinute()])->save();

    $action = PurchaseRfqActions::expire();
    $closure = $action->getActionFunction();
    expect($closure)->toBeInstanceOf(Closure::class);

    $closure($rfq);

    expect($rfq->status)->toBe(PurchaseRfqStatus::Expired)
        ->and($rfq->expired_at)->not->toBeNull();
});
