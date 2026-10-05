<?php

declare(strict_types=1);

use App\Enums\QualityResolutionType;
use App\Enums\TicketType;
use App\Http\Resources\Api\Customer\SupportTicketResource;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers customer support product-quality resource payload branches', function (): void {
    $method = new ReflectionMethod(SupportTicketResource::class, 'productQualityPayload');

    $general = Ticket::factory()->create(['type' => TicketType::GeneralSupport]);
    expect($method->invoke(new SupportTicketResource($general)))->toBeNull();

    $empty = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    expect($method->invoke(new SupportTicketResource($empty)))->toBe([
        'items' => [],
        'resolution' => null,
    ]);

    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $product = Product::factory()->create(['name' => 'Coverage API Product']);
    $unit = Unit::factory()->create(['name' => 'Pack']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Coverage API Variant',
    ]);
    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-API-88',
    ]);

    TicketProductContext::factory()->create([
        'ticket_id' => $ticket->id,
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => $lot->id,
        'unit_id' => $unit->id,
        'quantity' => '2.500000',
        'notes' => 'Visible customer quality note',
    ]);

    $return = CustomerReturnRequest::factory()->create([
        'customer_id' => $ticket->customer_id,
    ]);
    TicketQualityResolution::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_type' => QualityResolutionType::Replacement,
        'customer_return_request_id' => $return->id,
        'resolved_at' => now(),
    ]);

    $ticket->load([
        'productContexts.productVariant.product',
        'productContexts.inventoryLot',
        'productContexts.unit',
        'qualityResolution.customerReturnRequest',
    ]);

    $payload = $method->invoke(new SupportTicketResource($ticket));

    expect($payload['items'])->toHaveCount(1)
        ->and($payload['items'][0])->toMatchArray([
            'product' => 'Coverage API Product',
            'variant' => 'Coverage API Variant',
            'lot_number' => 'LOT-API-88',
            'quantity' => 2.5,
            'unit' => 'Pack',
            'notes' => 'Visible customer quality note',
        ])
        ->and($payload['resolution']['type'])->toBe(QualityResolutionType::Replacement->value)
        ->and($payload['resolution']['label'])->toBe(QualityResolutionType::Replacement->getLabel())
        ->and($payload['resolution']['return_request_number'])->toBe($return->request_number)
        ->and($payload['resolution']['resolved_at'])->toBeString();
});
