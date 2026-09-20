<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuotationResponseType;
use App\Models\Quotation;
use App\Models\QuotationResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationResponse>
 */
final class QuotationResponseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'response_type' => QuotationResponseType::Accepted,
            'note' => fake()->optional()->sentence(),
            'responded_at' => now(),
            'source_channel' => 'dashboard',
        ];
    }
}
