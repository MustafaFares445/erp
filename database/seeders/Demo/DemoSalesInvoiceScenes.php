<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\InvoiceConfirmationType;
use App\Jobs\GenerateInvoiceDocument;
use App\Models\Invoice;
use App\Services\Sales\InvoiceConfirmationService;
use App\Services\Sales\InvoiceService;
use Illuminate\Support\Carbon;

/**
 * Invoices raised from delivered goods and the standalone invoices of the month (services,
 * a replacement, tax-free work), with email delivery and receipt confirmation.
 *
 * `send` false leaves the invoice Issued; `draft` leaves it Draft. `confirm` is
 * [type, moment] for receipt evidence on a Sent invoice.
 */
final readonly class DemoSalesInvoiceScenes
{
    /**
     * @var array<string, array{deliveries: string, at: string, send?: bool, draft?: bool, confirm?: array{0: string, 1: string}}>
     */
    public const array FromDeliveries = [
        'I01' => ['deliveries' => 'O01', 'at' => '2026-09-10 11:00', 'confirm' => ['employee', '2026-09-11 10:00']],
        'I09' => ['deliveries' => 'O09', 'at' => '2026-09-11 17:30', 'confirm' => ['employee', '2026-09-12 10:00']],
        'I03' => ['deliveries' => 'O03', 'at' => '2026-09-14 10:30'],
        'I02' => ['deliveries' => 'O02', 'at' => '2026-09-14 16:00', 'confirm' => ['customer', '2026-09-15 12:00']],
        'I12' => ['deliveries' => 'O12', 'at' => '2026-09-15 10:00', 'confirm' => ['customer', '2026-09-16 10:00']],
        'I07' => ['deliveries' => 'O07', 'at' => '2026-09-16 10:30'],
        'I13' => ['deliveries' => 'O13', 'at' => '2026-09-17 10:00', 'confirm' => ['employee', '2026-09-18 09:30']],
        'I16' => ['deliveries' => 'O16', 'at' => '2026-09-18 14:00'],
        'I05' => ['deliveries' => 'O05', 'at' => '2026-09-21 11:00'],
        'I08' => ['deliveries' => 'O08A', 'at' => '2026-09-22 11:00'],
        'I04' => ['deliveries' => 'O04', 'at' => '2026-09-23 10:30', 'send' => false],
        'I11' => ['deliveries' => 'O11', 'at' => '2026-09-24 17:00', 'confirm' => ['customer', '2026-09-25 10:00']],
        'I15' => ['deliveries' => 'O15', 'at' => '2026-09-25 11:00'],
        'I10' => ['deliveries' => 'O10', 'at' => '2026-09-25 11:30', 'confirm' => ['employee', '2026-09-28 10:00']],
        'I06' => ['deliveries' => 'O06', 'at' => '2026-09-28 11:00', 'send' => false],
        'I17' => ['deliveries' => 'O17', 'at' => '2026-10-01 14:00', 'draft' => true],
    ];

    /**
     * Standalone invoices. A `due` date is explicit (no payment term).
     *
     * @var array<string, array{customer: string, at: string, due: string, description: string, lines: list<array{description: string, quantity: int, unit_price: float, tax_amount: float}>, send?: bool, draft?: bool}>
     */
    public const array Standalone = [
        'Z1' => ['customer' => 'C08', 'at' => '2026-09-07 10:00', 'due' => '2026-09-14', 'description' => 'Dental chair maintenance visit (service, VAT exempt)',
            'lines' => [['description' => 'On-site maintenance of three dental chairs', 'quantity' => 1, 'unit_price' => 1500.0, 'tax_amount' => 0.0]]],
        'Z2' => ['customer' => 'C12', 'at' => '2026-09-08 11:00', 'due' => '2026-09-18', 'description' => 'Autoclave installation and commissioning (service, VAT exempt)',
            'lines' => [['description' => 'Installation and commissioning of sterilization unit', 'quantity' => 1, 'unit_price' => 1800.0, 'tax_amount' => 0.0]]],
        'IR' => ['customer' => 'C14', 'at' => '2026-09-25 10:00', 'due' => '2026-10-09', 'description' => 'Replacement invoice for the returned and credited delivery',
            'lines' => [['description' => 'Corrected supply package (replaces credited invoice)', 'quantity' => 1, 'unit_price' => 1700.0, 'tax_amount' => 85.0]]],
        'SD' => ['customer' => 'C13', 'at' => '2026-10-02 10:00', 'due' => '2026-11-01', 'description' => 'Annual equipment service contract (awaiting approval)', 'draft' => true,
            'lines' => [['description' => 'Annual preventive maintenance contract', 'quantity' => 1, 'unit_price' => 2400.0, 'tax_amount' => 120.0]]],
    ];

    public function __construct(
        private DemoSalesKit $kit,
        private DemoSalesTimeline $timeline,
    ) {}

    public function register(): void
    {
        foreach (self::FromDeliveries as $code => $invoice) {
            $this->timeline->add($invoice['at'], "invoice {$code} created", fn () => $this->createFromDeliveries($code));

            if (($invoice['draft'] ?? false) === false) {
                $this->timeline->add($invoice['at'], "invoice {$code} issued", fn () => $this->issue($code));

                if ($invoice['send'] ?? true) {
                    $this->timeline->add($this->after($invoice['at'], 10), "invoice {$code} emailed", fn () => $this->send($code));
                }
            }

            if (isset($invoice['confirm'])) {
                $this->timeline->add($invoice['confirm'][1], "invoice {$code} receipt confirmed", fn () => $this->confirm($code, $invoice['confirm'][0]));
            }
        }

        foreach (self::Standalone as $code => $invoice) {
            $this->timeline->add($invoice['at'], "invoice {$code} created", fn () => $this->createStandalone($code));

            if (($invoice['draft'] ?? false) === false) {
                $this->timeline->add($invoice['at'], "invoice {$code} issued", fn () => $this->issue($code));
                $this->timeline->add($this->after($invoice['at'], 10), "invoice {$code} emailed", fn () => $this->send($code));
            }
        }
    }

    private function createFromDeliveries(string $code): void
    {
        $deliveries = collect($this->kit->deliveries[self::FromDeliveries[$code]['deliveries']])
            ->map(fn ($delivery) => $delivery->refresh());

        $this->kit->invoices[$code] = app(InvoiceService::class)->createFromDeliveries(
            $this->kit->context->as('billing'),
            $deliveries,
        );
    }

    private function createStandalone(string $code): void
    {
        $invoice = self::Standalone[$code];
        $customer = $this->kit->customer($invoice['customer']);

        $this->kit->invoices[$code] = app(InvoiceService::class)->createStandalone(
            $this->kit->context->as('billing'),
            [
                'customer_id' => $customer->getKey(),
                'invoice_date' => Carbon::parse($invoice['at'])->toDateString(),
                'due_date' => $invoice['due'],
                'description' => "[DEMO-SALES] {$invoice['description']}",
            ],
            $invoice['lines'],
        );
    }

    private function issue(string $code): void
    {
        $this->kit->invoices[$code] = app(InvoiceService::class)->issue(
            $this->kit->context->as('billing'),
            $this->kit->invoices[$code]->refresh(),
        );
    }

    private function send(string $code): void
    {
        $billing = $this->kit->context->as('billing');
        $invoice = $this->kit->invoices[$code]->refresh();

        GenerateInvoiceDocument::dispatchSync(DemoContext::keyOf($invoice), DemoContext::keyOf($billing));
        $this->kit->invoices[$code] = app(InvoiceService::class)->send($billing, $invoice->refresh())->refresh();
    }

    private function confirm(string $code, string $type): void
    {
        app(InvoiceConfirmationService::class)->confirm(
            $this->kit->context->as('billing'),
            $this->kit->invoices[$code]->refresh(),
            $type === 'customer' ? InvoiceConfirmationType::CustomerReceived : InvoiceConfirmationType::EmployeeConfirmedReceived,
            $type === 'customer' ? 'Customer confirmed receipt of the invoice by email.' : 'Employee confirmed the invoice was handed over with the goods.',
        );
        $this->kit->invoices[$code] = Invoice::query()->findOrFail(DemoContext::keyOf($this->kit->invoices[$code]));
    }

    private function after(string $moment, int $minutes): string
    {
        return Carbon::parse($moment)->addMinutes($minutes)->format('Y-m-d H:i');
    }
}
