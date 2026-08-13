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
            'log_name' => 'default',
            'description' => 'inventory.adjustment.confirmed',
            'subject_type' => InventoryAdjustment::class,
            'subject_id' => fake()->numberBetween(1, 1000),
            'causer_type' => User::class,
            'causer_id' => User::factory(),
            'attribute_changes' => [
                'old' => ['status' => 'draft'],
                'attributes' => ['status' => 'confirmed'],
            ],
            'properties' => [
                'source_channel' => 'dashboard',
                'ip_address' => fake()->ipv4(),
            ],
        ];
    }
}
