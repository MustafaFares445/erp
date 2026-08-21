<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupplierConfirmationStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierConfirmation>
 */
final class SupplierConfirmationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'confirmable_type' => PurchaseOrder::class,
            'confirmable_id' => PurchaseOrder::factory(),
            'supplier_id' => Supplier::factory(),
            'confirmation_status' => SupplierConfirmationStatus::Pending,
            'promised_at' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function confirmed(?User $actor = null): self
    {
        return $this->state(fn (): array => [
            'confirmation_status' => SupplierConfirmationStatus::Confirmed,
            'promised_at' => now()->addWeek()->toDateString(),
            'confirmed_by' => $actor?->getKey() ?? User::factory(),
            'confirmed_at' => now(),
        ]);
    }

    public function rejected(?User $actor = null): self
    {
        return $this->state(fn (): array => [
            'confirmation_status' => SupplierConfirmationStatus::Rejected,
            'confirmed_by' => $actor?->getKey() ?? User::factory(),
            'confirmed_at' => now(),
        ]);
    }
}
