<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Quotation>
 */
final class QuotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_number' => 'QT-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'issue_date' => Carbon::today(),
            'expires_at' => Carbon::today()->addDays(30),
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'grand_total' => '0.00',
            'status' => QuotationStatus::Draft,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function accepted(): self
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Accepted,
            'sent_at' => now()->subDay(),
            'decided_at' => Carbon::today(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Sent,
            'sent_at' => now()->subDays(60),
            'expires_at' => Carbon::today()->subDays(30),
        ]);
    }
}
