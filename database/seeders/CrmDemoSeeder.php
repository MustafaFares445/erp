<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\Crm\InteractionData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationDecision;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\InteractionService;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Gives the Customer 360 timeline (CR-05) something worth scrolling: twelve
 * months of interactions and a fully back-dated quotation-to-credit-note
 * cycle for Smile Dental Clinic, the richest existing demo customer.
 *
 * Registered last in {@see DatabaseSeeder} because it links interactions to
 * visits and tickets the earlier seeders create, and its historical cycle
 * reuses a product variant from the earlier demo order.
 *
 * Every seeded document here is additive — new rows dated in the past — never
 * a back-dated edit of a document another seeder already created, so AR aging
 * buckets and fiscal-period assertions elsewhere are undisturbed.
 */
final class CrmDemoSeeder extends Seeder
{
    private const string INTERACTION_MARKER = 'Demo workflow: CRM timeline interaction seed.';

    private const string CYCLE_MARKER = 'Demo workflow: CRM timeline historical cycle for Smile Dental Clinic.';

    public function run(): void
    {
        $customer = $this->demoCustomer();

        $this->seedInteractions($customer);
        $this->seedHistoricalCycle($customer);
    }

    private function demoCustomer(): CustomerProfile
    {
        $customer = CustomerProfile::query()->where('customer_code', 'DEMO-SMILE')->first();

        if (! $customer instanceof CustomerProfile) {
            throw new LogicException('CrmDemoSeeder requires the inventory demo customer DEMO-SMILE. Run DatabaseSeeder.');
        }

        return $customer;
    }

    private function seedInteractions(CustomerProfile $customer): void
    {
        $alreadySeeded = Interaction::query()
            ->where('subject_type', CustomerProfile::class)
            ->where('subject_id', $customer->getKey())
            ->where('notes', self::INTERACTION_MARKER)
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $actor = $this->demoActor();
        $ticketIds = $this->intKeys(Ticket::query()->where('customer_id', $customer->getKey())->orderBy('id')->pluck('id'));
        $visitIds = $this->intKeys(CustomerVisit::query()->where('customer_id', $customer->getKey())->orderBy('id')->pluck('id'));

        $types = InteractionType::cases();
        $directions = InteractionDirection::cases();
        $service = app(InteractionService::class);

        $summaries = [
            'Renewal pricing discussion',
            'Equipment onboarding walkthrough',
            'Follow-up on an open service ticket',
            'Quarterly business review',
            'New product line introduction',
            'Field visit debrief',
            'Invoice query resolved',
            'Warranty extension inquiry',
            'Staff training coordination',
            'Delivery schedule confirmation',
            'Satisfaction check-in call',
            'Contract renewal reminder',
        ];

        foreach ($summaries as $index => $summary) {
            $monthsAgo = count($summaries) - 1 - $index;
            $occurredAt = now()->subMonths($monthsAgo)->subDays($index);

            $interaction = $service->log(new InteractionData(
                subject: $customer,
                type: $types[$index % count($types)],
                direction: $directions[$index % count($directions)],
                occurredAt: $occurredAt,
                summary: $summary,
                notes: self::INTERACTION_MARKER,
                customerVisitId: $index % 4 === 0 ? ($visitIds[intdiv($index, 4) % max(count($visitIds), 1)] ?? null) : null,
                ticketId: $index % 4 === 2 ? ($ticketIds[intdiv($index, 4) % max(count($ticketIds), 1)] ?? null) : null,
            ), $actor);

            // The audit trail is written at log() time (now()), which would
            // otherwise pile up all twelve interactions' activity rows at the
            // seeding moment instead of alongside the interaction's own
            // backdated occurred_at in the timeline stream.
            AuditLog::query()
                ->where('subject_type', Interaction::class)
                ->where('subject_id', $interaction->getKey())
                ->latest('id')
                ->first()
                ?->forceFill(['created_at' => $occurredAt, 'updated_at' => $occurredAt])
                ->saveQuietly();
        }
    }

    private function seedHistoricalCycle(CustomerProfile $customer): void
    {
        if (Quotation::query()->where('notes', self::CYCLE_MARKER)->exists()) {
            return;
        }

        $variant = $this->demoVariant($customer);
        $variantId = $variant->getKey();

        if (! is_int($variantId)) {
            throw new LogicException('CrmDemoSeeder requires a persisted product variant.');
        }

        $actor = $this->demoActor();

        $quotationService = app(QuotationService::class);

        $quotation = $quotationService->create([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->subMonths(10)->toDateString(),
            'notes' => self::CYCLE_MARKER,
        ], [
            ['product_variant_id' => $variantId, 'quantity' => '4'],
        ]);
        $quotation = $quotationService->send($quotation);
        $quotation = $quotationService->recordDecision(
            $quotation,
            QuotationDecision::Accepted,
            now()->subMonths(9),
            'Approved after budget review.',
            $actor,
        );

        $order = app(QuotationConversionService::class)->convert($quotation->refresh());
        $order->forceFill([
            'status' => OrderStatus::Closed,
            'closed_at' => now()->subMonths(8),
            'created_at' => now()->subMonths(8),
            'updated_at' => now()->subMonths(8),
        ])->save();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-CRMDEMO-000001',
            'order_id' => $order->getKey(),
            'customer_id' => $customer->getKey(),
            'invoice_date' => now()->subMonths(7)->toDateString(),
            'due_date' => now()->subMonths(6)->toDateString(),
            'description' => 'Historical order fulfilment invoice',
            'subtotal' => $order->subtotal,
            'tax_total' => $order->tax_total,
            'total_amount' => $order->grand_total,
            'amount_paid' => $order->grand_total,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now()->subMonths(7),
        ]);

        $paymentMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-CRMDEMO-000001',
            'customer_id' => $customer->getKey(),
            'payment_method_id' => $paymentMethod->getKey(),
            'amount' => $invoice->total_amount,
            'currency' => 'USD',
            'source' => 'manual',
            'payment_date' => now()->subMonths(6)->toDateString(),
            'external_reference' => 'BANK-CRMDEMO-000001',
            'status' => PaymentStatus::Posted,
            'posted_at' => now()->subMonths(6),
        ]);
        $payment->allocations()->create(['invoice_id' => $invoice->getKey(), 'amount' => $invoice->total_amount]);

        CreditNote::query()->create([
            'credit_note_number' => 'CN-CRMDEMO-000001',
            'invoice_id' => $invoice->getKey(),
            'customer_id' => $customer->getKey(),
            'reason' => 'Historical demo goodwill credit',
            'issue_date' => now()->subMonths(6)->addDays(5)->toDateString(),
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'grand_total' => '0.00',
            'status' => 'draft',
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    private function intKeys(Collection $ids): array
    {
        return array_values($ids
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    private function demoVariant(CustomerProfile $customer): ProductVariant
    {
        $order = Order::query()->where('customer_id', $customer->getKey())->orderBy('id')->first();
        $variant = $order?->lines()->with('productVariant')->first()?->productVariant;

        if (! $variant instanceof ProductVariant) {
            throw new LogicException('CrmDemoSeeder requires an existing order line for the demo customer. Run DatabaseSeeder.');
        }

        return $variant;
    }

    private function demoActor(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin@ierp.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password'), 'user_type' => UserType::Admin],
        );
    }
}
