<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QuotationDecision;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\QuotationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Seeds one coherent, editable sales lifecycle rather than independent rows.
 *
 * It intentionally leaves financial documents in Draft: accounting posting is
 * a separate controlled workflow, so demo data must not manufacture journal
 * effects by directly changing a status flag.
 */
final class SalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $order = $this->demoOrder();
        $customer = $this->demoCustomer($order);
        $variant = $this->demoVariant($order);
        $invoice = $this->seedInvoice($order, $this->demoDelivery(), $customer, $variant);

        $this->seedPayment($customer, $this->demoPaymentMethod());
        $this->seedCreditNote($invoice, $customer);
        $this->seedQuotations($customer, $variant);
    }

    private function demoOrder(): Order
    {
        $order = Order::query()->where('order_number', 'SO-2026-0001')->first();

        if (! $order instanceof Order) {
            throw new LogicException('SalesDemoSeeder requires the inventory demo order. Run DatabaseSeeder.');
        }

        return $order;
    }

    private function demoDelivery(): InventoryOperation
    {
        $delivery = InventoryOperation::query()
            ->where('notes', 'Demo workflow: reserved resin for Smile Dental Clinic.')
            ->first();

        if (! $delivery instanceof InventoryOperation) {
            throw new LogicException('SalesDemoSeeder requires the inventory demo delivery note. Run DatabaseSeeder.');
        }

        return $delivery;
    }

    private function demoCustomer(Order $order): CustomerProfile
    {
        $customer = $order->customer;

        if (! $customer instanceof CustomerProfile) {
            throw new LogicException('SalesDemoSeeder could not resolve the customer from the demo order.');
        }

        return $customer;
    }

    private function demoVariant(Order $order): ProductVariant
    {
        $variant = $order->lines()->with('productVariant')->first()?->productVariant;

        if (! $variant instanceof ProductVariant) {
            throw new LogicException('SalesDemoSeeder could not resolve the product from the demo order.');
        }

        return $variant;
    }

    private function demoPaymentMethod(): PaymentMethod
    {
        $paymentMethod = PaymentMethod::query()->where('is_active', true)->first();

        if (! $paymentMethod instanceof PaymentMethod) {
            throw new LogicException('SalesDemoSeeder requires an active payment method. Run DatabaseSeeder.');
        }

        return $paymentMethod;
    }

    private function seedInvoice(Order $order, InventoryOperation $delivery, CustomerProfile $customer, ProductVariant $variant): Invoice
    {
        $invoice = Invoice::query()->firstOrCreate(
            ['invoice_number' => 'INV-SALES-2026-001'],
            [
                'inventory_operation_id' => $delivery->getKey(),
                'order_id' => $order->getKey(),
                'payment_term_id' => $order->payment_term_id,
                'customer_id' => $customer->getKey(),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'description' => 'Three Formlabs Precision Model Resin cartons supplied to Smile Dental Clinic.',
                'subtotal' => '360.00',
                'tax_total' => '18.00',
                'total_amount' => '378.00',
                'amount_paid' => '0.00',
                'status' => 'draft',
            ],
        );

        $invoice->lines()->updateOrCreate(
            ['sort_order' => 1],
            [
                'product_variant_id' => $variant->getKey(),
                'description' => 'Formlabs Precision Model Resin 1L',
                'quantity' => '3.000',
                'unit_price' => '120.00',
                'tax_amount' => '18.00',
                'line_total' => '378.00',
            ],
        );

        return $invoice;
    }

    private function seedPayment(CustomerProfile $customer, PaymentMethod $paymentMethod): void
    {
        $payment = Payment::query()->firstOrCreate(
            ['payment_number' => 'PAY-SALES-2026-001'],
            [
                'customer_id' => $customer->getKey(),
                'payment_method_id' => $paymentMethod->getKey(),
                'amount' => '120.00',
                'currency' => 'USD',
                'source' => 'manual',
                'payment_date' => now()->toDateString(),
                'external_reference' => 'BANK-SALES-2026-001',
                'notes' => 'Deposit received for the Smile Dental Clinic resin delivery.',
                'status' => 'draft',
            ],
        );

        $payment->manualRecord()->updateOrCreate(
            ['payment_id' => $payment->getKey()],
            ['reference' => 'BANK-SALES-2026-001', 'received_at' => now()],
        );
    }

    private function seedCreditNote(Invoice $invoice, CustomerProfile $customer): void
    {
        $creditNote = CreditNote::query()->firstOrCreate(
            ['credit_note_number' => 'CN-SALES-2026-001'],
            [
                'invoice_id' => $invoice->getKey(),
                'customer_id' => $customer->getKey(),
                'reason' => 'Draft goodwill credit for a delayed delivery appointment.',
                'issue_date' => now()->toDateString(),
                'subtotal' => '50.00',
                'tax_total' => '2.50',
                'grand_total' => '52.50',
                'status' => 'draft',
            ],
        );

        $creditNote->lines()->updateOrCreate(
            ['sort_order' => 1],
            [
                'invoice_line_id' => $invoice->lines()->value('id'),
                'description' => 'Delivery appointment goodwill credit',
                'quantity' => '1.000',
                'unit_price' => '50.00',
                'tax_amount' => '2.50',
                'line_total' => '52.50',
            ],
        );
    }

    private function seedQuotations(CustomerProfile $customer, ProductVariant $variant): void
    {
        $draftNote = 'Demo workflow: draft resin top-up quotation for Smile Dental Clinic.';

        if (Quotation::query()->where('notes', $draftNote)->exists()) {
            return;
        }

        $service = app(QuotationService::class);
        $variantId = $variant->getKey();

        if (! is_int($variantId)) {
            throw new LogicException('SalesDemoSeeder requires a persisted product variant.');
        }

        $service->create([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'notes' => $draftNote,
        ], [
            ['product_variant_id' => $variantId, 'quantity' => '2'],
        ]);

        $sent = $service->create([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->subDays(5)->toDateString(),
            'notes' => 'Demo workflow: resin top-up quotation sent to Smile Dental Clinic, awaiting a decision.',
        ], [
            ['product_variant_id' => $variantId, 'quantity' => '5'],
        ]);
        $service->send($sent);

        $accepted = $service->create([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->subDays(10)->toDateString(),
            'notes' => 'Demo workflow: resin quotation accepted by Smile Dental Clinic.',
        ], [
            ['product_variant_id' => $variantId, 'quantity' => '3'],
        ]);
        $accepted = $service->send($accepted);
        $service->recordDecision(
            $accepted,
            QuotationDecision::Accepted,
            now()->subDays(9),
            'Approved by Smile Dental Clinic procurement.',
            $this->demoActor(),
        );
    }

    private function demoActor(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin@ierp.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password')],
        );
    }
}
