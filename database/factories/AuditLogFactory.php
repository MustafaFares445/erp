<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'action' => 'inventory.adjustment.confirmed',
            'entity_type' => InventoryAdjustment::class,
            'entity_id' => fake()->numberBetween(1, 1000),
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'confirmed'],
            'source_channel' => 'dashboard',
            'ip_address' => fake()->ipv4(),
        ];
    }
}
