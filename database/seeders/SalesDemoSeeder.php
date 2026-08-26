<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
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
        $invoice = $this->seedInvoice($order, $this->demoDelivery(), $customer, $this->demoVariant($order));

        $this->seedPayment($customer, $this->demoPaymentMethod());
        $this->seedCreditNote($invoice, $customer);
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
}
