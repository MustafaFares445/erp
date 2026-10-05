<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Ticket;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.product_quality_enabled', true);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.sla_v2_enabled', true);
    (new SlaPolicySeeder)->run();
});

function coverage91DeliveredLine(CustomerProfile $customer): InventoryOperationLine
{
    $operation = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->id,
        'operation_type' => OperationType::Delivery,
        'stage' => OperationStage::Done,
    ]);

    return InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->id,
        'serialized_inventory_unit_id' => null,
        'quantity' => '3.000000',
        'transaction_quantity' => '3.000000',
    ]);
}

it('creates a product-quality complaint through the customer API and returns its product context', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = coverage91DeliveredLine($customer);
    Sanctum::actingAs($customer->user, ['customer:*']);

    $response = $this->postJson('/api/customer/support/product-quality-complaints', [
        'customer_impact' => 'degraded',
        'title' => 'Material batch quality issue',
        'description' => 'Several packs from the delivered batch show the same defect.',
        'product_contexts' => [[
            'original_inventory_operation_line_id' => $line->id,
            'quantity' => 1.5,
            'notes' => 'Affected packs from the same delivery.',
        ]],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.type', TicketType::ProductQualityIssue->value)
        ->assertJsonPath('data.product_quality.items.0.quantity', 1.5)
        ->assertJsonPath('data.product_quality.items.0.notes', 'Affected packs from the same delivery.')
        ->assertJsonPath('data.product_quality.resolution', null);

    $ticket = Ticket::query()->where('customer_id', $customer->id)->sole();

    expect($ticket->type)->toBe(TicketType::ProductQualityIssue)
        ->and($ticket->productContexts()->count())->toBe(1);
});

it('covers product-quality complaint validation rules', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $this->postJson('/api/customer/support/product-quality-complaints', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'description', 'product_contexts']);

    $this->postJson('/api/customer/support/product-quality-complaints', [
        'title' => str_repeat('x', 256),
        'description' => str_repeat('y', 10001),
        'product_contexts' => [[
            'original_inventory_operation_line_id' => 'not-an-id',
            'quantity' => 0,
            'notes' => str_repeat('z', 1001),
        ]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'title',
            'description',
            'product_contexts.0.original_inventory_operation_line_id',
            'product_contexts.0.quantity',
            'product_contexts.0.notes',
        ]);
});

it('returns not found when product-quality complaints are disabled', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);
    config()->set('support.product_quality_enabled', false);

    $this->postJson('/api/customer/support/product-quality-complaints', [
        'title' => 'Disabled',
        'description' => 'Feature disabled',
        'product_contexts' => [[
            'original_inventory_operation_line_id' => 1,
            'quantity' => 1,
        ]],
    ])->assertNotFound();
});
