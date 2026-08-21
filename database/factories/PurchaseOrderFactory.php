<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
final class PurchaseOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_number' => 'PO-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'destination_warehouse_id' => Warehouse::factory(),
            'status' => PurchaseOrderStatus::Draft,
            'currency_code' => 'AED',
            'ordered_at' => now()->toDateString(),
            'expected_at' => now()->addWeek()->toDateString(),
            'total_amount' => '0.00',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Waiting on an approver, with the submission stamped.
     */
    public function pendingApproval(?User $submitter = null): self
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::PendingApproval,
            'submitted_by' => $submitter?->getKey() ?? User::factory(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Approved but not yet transmitted.
     *
     * The approver is stamped even when the state is used for an auto-approval
     * scenario, because SC-005 requires every state change to be attributable
     * and "nobody approved it" is not a truthful record (R-004).
     */
    public function approved(?User $approver = null): self
    {
        return $this->state(function () use ($approver): array {
            $userId = $approver?->getKey() ?? User::factory();

            return [
                'status' => PurchaseOrderStatus::Approved,
                'submitted_by' => $userId,
                'submitted_at' => now()->subMinute(),
                'approved_by' => $userId,
                'approved_at' => now(),
            ];
        });
    }

    /**
     * Transmitted to the supplier — past the immutability boundary (FR-025) and
     * the first state in which a receipt may be initiated.
     */
    public function sent(): self
    {
        return $this->approved()->state(fn (): array => [
            'status' => PurchaseOrderStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function partiallyReceived(): self
    {
        return $this->sent()->state(fn (): array => [
            'status' => PurchaseOrderStatus::PartiallyReceived,
        ]);
    }

    public function received(): self
    {
        return $this->sent()->state(fn (): array => [
            'status' => PurchaseOrderStatus::Received,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }
}
