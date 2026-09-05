<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDeliveryLink>
 */
final class InvoiceDeliveryLinkFactory extends Factory
{
    protected $model = InvoiceDeliveryLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'inventory_operation_id' => InventoryOperation::factory()->delivery()->done(),
        ];
    }
}
