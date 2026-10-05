<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\QualityResolutionType;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\InventoryOperationLine;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketProductContextService;
use App\Services\Support\TicketQualityResolutionService;
use LogicException;

/**
 * Dental-lab product-quality demo: repeated complaints against one delivered
 * lot raise the informational lot signal, while one complaint demonstrates
 * the existing customer-return integration and another demonstrates a lot
 * investigation. No stock is quarantined or moved by this seeder.
 */
final class DemoProductQualitySeeder extends DemoSeeder
{
    private const array Titles = [
        '[DEMO] Resin shade inconsistency - case 1',
        '[DEMO] Resin shade inconsistency - case 2',
        '[DEMO] Resin shade inconsistency - case 3',
    ];

    protected function seed(DemoContext $context): void
    {
        $previousEnabled = config('support.product_quality_enabled');
        $previousThreshold = config('support.lot_complaint_threshold');

        config([
            'support.product_quality_enabled' => true,
            'support.lot_complaint_threshold' => 3,
        ]);

        try {
            $manager = $context->as('support_manager');
            [$customer, $line] = $this->qualityDeliveryLine();
            $customerUser = $customer->user;

            if (! $customerUser instanceof User) {
                throw new LogicException('The demo quality-complaint customer has no login user.');
            }

            $remaining = (float) app(TicketProductContextService::class)->remainingQuantity($line);
            $quantity = number_format(min(1.0, $remaining), 6, '.', '');

            $tickets = [];

            foreach (self::Titles as $index => $title) {
                $ticket = Ticket::query()->where('title', $title)->first();

                if (! $ticket instanceof Ticket) {
                    $context->at('2026-10-0'.($index + 1).' '.(10 + $index).':15:00');

                    $ticket = app(TicketIntakeService::class)->createForCustomer([
                        'type' => TicketType::ProductQualityIssue,
                        'customer_impact' => TicketCustomerImpact::Degraded,
                        'title' => $title,
                        'description' => match ($index) {
                            0 => 'Printed crowns from this resin lot show a visible shade shift after curing.',
                            1 => 'A second case from the same delivered lot shows inconsistent surface finish.',
                            default => 'Repeated quality concern on the same delivered resin lot; requesting lot review.',
                        },
                        'product_contexts' => [[
                            'original_inventory_operation_line_id' => $line->getKey(),
                            'quantity' => $quantity,
                            'notes' => 'Affected material was taken from the same delivered lot.',
                        ]],
                    ], $customerUser);
                }

                $tickets[] = $ticket;
            }

            $context->at('2026-10-03 15:30:00');
            $first = $tickets[0];

            if (! $first->qualityResolution()->exists()) {
                app(TicketQualityResolutionService::class)->resolve(
                    $first,
                    QualityResolutionType::CustomerReturn,
                    $manager,
                    ['notes' => 'Customer return requested for the affected quantity; Inventory will handle disposition after review.'],
                );
            }

            $second = $tickets[1];

            if (! $second->qualityResolution()->exists()) {
                app(TicketQualityResolutionService::class)->resolve(
                    $second,
                    QualityResolutionType::LotInvestigation,
                    $manager,
                    ['notes' => 'Repeated complaint confirmed; lot investigation opened. Inventory retains authority over any quarantine decision.'],
                );
            }

            $this->note('Seeded repeated product-quality complaints, a return-linked resolution, and a lot-quality threshold signal.');
        } finally {
            config([
                'support.product_quality_enabled' => $previousEnabled,
                'support.lot_complaint_threshold' => $previousThreshold,
            ]);
        }
    }

    /** @return array{CustomerProfile, InventoryOperationLine} */
    private function qualityDeliveryLine(): array
    {
        $service = app(TicketProductContextService::class);

        $customers = CustomerProfile::query()
            ->where('is_active', true)
            ->whereHas('user')
            ->with('user')
            ->orderBy('id')
            ->get();

        $fallback = null;

        foreach ($customers as $customer) {
            $lines = $service->eligibleLines($customer)
                ->filter(static fn (InventoryOperationLine $candidate): bool => $candidate->inventory_lot_id !== null)
                ->values();

            $lines->loadMissing('productVariant.product');

            $resin = $lines->first(static function (InventoryOperationLine $candidate): bool {
                $variant = $candidate->productVariant;
                $haystack = mb_strtolower(collect([
                    $variant?->sku,
                    $variant?->name,
                    $variant?->product?->name,
                ])->filter()->implode(' '));

                return str_contains($haystack, 'resin');
            });

            if ($resin instanceof InventoryOperationLine) {
                return [$customer, $resin];
            }

            $first = $lines->first();

            if ($fallback === null && $first instanceof InventoryOperationLine) {
                $fallback = [$customer, $first];
            }
        }

        if (is_array($fallback)) {
            return $fallback;
        }

        throw new LogicException('No non-serialized, lot-tracked delivered demo line is available for product-quality scenarios.');
    }
}
