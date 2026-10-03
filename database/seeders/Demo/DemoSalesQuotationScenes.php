<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationResponseService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The quotation book of the month: 28 quotations issued by the six sales employees, covering
 * every reachable status. Six are accepted and converted into the orders the fulfilment and
 * finance scenes carry on with.
 *
 * `path` values: draft | sent | accepted | rejected | expired | cancelled | changes | converted.
 * Timestamps are scene moments (application timezone); the quotation's own `issue_date` is the
 * day part of `issued`.
 */
final class DemoSalesQuotationScenes
{
    /**
     * @var array<string, array{
     *     customer: string, emp: int, term: string, issued: string, lines: array<string, int>, path: string,
     *     expires?: string, decided?: string, note?: string, converted?: string, release?: string, order?: string,
     *     requote?: string, cancelled?: string, unsent?: bool
     * }>
     */
    public const array Quotes = [
        // Converted into the orders carried through delivery, invoicing and collection.
        'QC1' => ['customer' => 'C01', 'emp' => 1, 'term' => 'Net 15', 'issued' => '2026-09-07 10:00', 'path' => 'converted', 'order' => 'O01',
            'lines' => ['P004-35X12' => 10, 'P003-1L' => 3, 'P002-5L' => 2, 'P005-35MM' => 6],
            'decided' => '2026-09-08 11:00', 'converted' => '2026-09-08 11:30', 'release' => '2026-09-08 14:00',
            'note' => 'Approved by the clinic purchasing office.'],
        'QC3' => ['customer' => 'C09', 'emp' => 3, 'term' => 'Net 7', 'issued' => '2026-09-08 09:30', 'path' => 'converted', 'order' => 'O03',
            'lines' => ['P019-HANDPIECE' => 1, 'P003-250ML' => 4, 'P001-500ML' => 5, 'P006-HEAVY' => 12, 'P013-1L' => 5, 'P005-35MM' => 2],
            'decided' => '2026-09-09 15:00', 'converted' => '2026-09-10 09:00', 'release' => '2026-09-10 10:00',
            'note' => 'Accepted by email, PO to follow.'],
        'QC2' => ['customer' => 'C02', 'emp' => 2, 'term' => 'Net 15', 'issued' => '2026-09-09 11:00', 'path' => 'converted', 'order' => 'O02',
            'lines' => ['P004-35X10' => 8, 'P009-STD' => 6, 'P012-21MM' => 2, 'P005-45MM' => 1],
            'decided' => '2026-09-10 14:00', 'converted' => '2026-09-11 09:30', 'release' => '2026-09-11 10:00',
            'note' => 'Accepted on the phone with the clinic manager.'],
        'QC5' => ['customer' => 'C05', 'emp' => 1, 'term' => 'Net 15', 'issued' => '2026-09-14 10:00', 'path' => 'converted', 'order' => 'O08',
            'lines' => ['P005-45MM' => 20, 'P018-LOWER' => 20, 'P007-S' => 20],
            'decided' => '2026-09-15 16:00', 'converted' => '2026-09-16 10:00', 'release' => '2026-09-16 11:00',
            'note' => 'Delivery can be split into two shipments.'],
        'QC4' => ['customer' => 'C13', 'emp' => 4, 'term' => 'Net 30', 'issued' => '2026-09-15 14:00', 'path' => 'converted', 'order' => 'O05',
            'lines' => ['P010-A2' => 10, 'P006-LIGHT' => 10, 'P007-M' => 20],
            'decided' => '2026-09-16 12:00', 'converted' => '2026-09-17 09:30', 'release' => '2026-09-17 10:30',
            'note' => 'Accepted via the customer portal.'],
        'QC6' => ['customer' => 'C03', 'emp' => 5, 'term' => 'Net 45', 'issued' => '2026-09-18 10:30', 'path' => 'converted', 'order' => 'O11',
            'lines' => ['P006-PUTTY' => 20, 'P009-STD' => 10, 'P011-5ML' => 6],
            'decided' => '2026-09-19 11:30', 'converted' => '2026-09-22 09:00', 'release' => '2026-09-22 09:30',
            'note' => 'Procurement committee approved.'],

        // Accepted, waiting for conversion.
        'AC1' => ['customer' => 'C11', 'emp' => 2, 'term' => 'Net 45', 'issued' => '2026-09-16 11:00', 'path' => 'accepted',
            'lines' => ['P014-10G' => 7, 'P015-20X30' => 6, 'P011-5ML' => 10], 'decided' => '2026-09-21 10:00', 'note' => 'Accepted, awaiting budget code before ordering.'],
        'AC2' => ['customer' => 'C13', 'emp' => 3, 'term' => 'Net 30', 'issued' => '2026-09-18 15:00', 'path' => 'accepted',
            'lines' => ['P010-A2' => 7, 'P006-LIGHT' => 14], 'decided' => '2026-09-25 09:30', 'note' => 'Accepted for the October refill.'],
        'AC3' => ['customer' => 'C06', 'emp' => 4, 'term' => 'Net 7', 'issued' => '2026-09-22 12:00', 'path' => 'accepted',
            'lines' => ['P020-PRO' => 6, 'P016-BASIC' => 9, 'P018-UPPER' => 21], 'decided' => '2026-09-28 14:00', 'note' => 'Accepted, delivery window to be agreed.'],
        'AC4' => ['customer' => 'C03', 'emp' => 1, 'term' => 'Net 45', 'issued' => '2026-09-24 09:30', 'path' => 'accepted',
            'lines' => ['P019-MOTOR' => 1, 'P003-1L' => 5, 'P001-1L' => 7], 'decided' => '2026-10-01 11:00', 'note' => 'Large framework quote, conversion pending sign-off.'],

        // Rejected with a recorded reason.
        'RJ1' => ['customer' => 'C08', 'emp' => 6, 'term' => 'Net 15', 'issued' => '2026-09-08 13:00', 'path' => 'rejected',
            'lines' => ['P004-35X10' => 10, 'P002-5L' => 3, 'P009-STD' => 8], 'decided' => '2026-09-17 10:00', 'note' => 'Price higher than a competing offer.'],
        'RJ2' => ['customer' => 'C14', 'emp' => 2, 'term' => 'Net 7', 'issued' => '2026-09-10 16:00', 'path' => 'rejected',
            'lines' => ['P010-A3' => 9, 'P006-PUTTY' => 15], 'decided' => '2026-09-18 09:00', 'note' => 'Clinic refit postponed to next year.'],
        'RJ3' => ['customer' => 'C05', 'emp' => 5, 'term' => 'Net 15', 'issued' => '2026-09-23 10:00', 'path' => 'rejected',
            'lines' => ['P007-L' => 19, 'P013-1L' => 15], 'decided' => '2026-09-29 15:30', 'note' => 'Budget not approved this quarter.'],

        // Lapsed unanswered; the expiry sweep closes them.
        'EX1' => ['customer' => 'C09', 'emp' => 1, 'term' => 'Net 7', 'issued' => '2026-09-04 10:00', 'path' => 'expired', 'expires' => '2026-09-14',
            'lines' => ['P012-25MM' => 15, 'P010-A2' => 8, 'P005-35MM' => 7], 'decided' => '2026-09-15 06:00'],
        'EX2' => ['customer' => 'C15', 'emp' => 6, 'term' => 'Net 30', 'issued' => '2026-09-07 15:00', 'path' => 'expired', 'expires' => '2026-09-17',
            'lines' => ['P018-UPPER' => 22, 'P017-STD' => 6], 'decided' => '2026-09-18 06:00'],
        'EX3' => ['customer' => 'C04', 'emp' => 3, 'term' => 'Net 30', 'issued' => '2026-09-11 09:00', 'path' => 'expired', 'expires' => '2026-09-21',
            'lines' => ['P008-90X230' => 10, 'P018-LOWER' => 3], 'decided' => '2026-09-22 06:00'],

        // Sent, awaiting a decision (two expiring within a week of the demo "today").
        'A1' => ['customer' => 'C03', 'emp' => 3, 'term' => 'Net 45', 'issued' => '2026-09-21 10:00', 'path' => 'sent', 'expires' => '2026-10-05',
            'lines' => ['P019-HANDPIECE' => 2, 'P003-1L' => 4, 'P002-5L' => 2]],
        'A2' => ['customer' => 'C12', 'emp' => 1, 'term' => 'Net 15', 'issued' => '2026-09-28 11:00', 'path' => 'sent', 'expires' => '2026-10-08',
            'lines' => ['P004-40X10' => 8, 'P015-20X30' => 5, 'P005-45MM' => 8]],
        'A3' => ['customer' => 'C01', 'emp' => 4, 'term' => 'Net 15', 'issued' => '2026-09-11 14:30', 'path' => 'sent', 'expires' => '2026-10-11',
            'lines' => ['P004-35X12' => 14, 'P003-250ML' => 12, 'P001-1L' => 4]],
        'A4' => ['customer' => 'C10', 'emp' => 5, 'term' => 'Net 30', 'issued' => '2026-09-14 09:30', 'path' => 'sent', 'expires' => '2026-10-14',
            'lines' => ['P016-PREMIUM' => 8, 'P017-STD' => 10, 'P020-BASIC' => 3]],

        // Customer asked for changes; one was re-quoted into a new draft.
        'CR1' => ['customer' => 'C01', 'emp' => 1, 'term' => 'Net 15', 'issued' => '2026-09-11 10:00', 'path' => 'changes', 'decided' => '2026-09-15 11:00',
            'lines' => ['P003-250ML' => 11, 'P001-500ML' => 8, 'P002-1L' => 5], 'requote' => '2026-09-16 09:30',
            'note' => 'Please split the delivery and revise the resin quantity.'],
        'CR2' => ['customer' => 'C12', 'emp' => 4, 'term' => 'Net 15', 'issued' => '2026-09-17 13:00', 'path' => 'changes', 'decided' => '2026-09-24 10:30',
            'lines' => ['P005-35MM' => 16, 'P004-35X10' => 4], 'note' => 'Needs a revised unit price for the abutments.'],

        // Withdrawn before a decision (the single permitted direct status write).
        'CA1' => ['customer' => 'C07', 'emp' => 4, 'term' => 'Net 30', 'issued' => '2026-09-09 10:30', 'path' => 'cancelled', 'cancelled' => '2026-09-16 15:00',
            'lines' => ['P015-15X20' => 8, 'P014-05G' => 6]],
        'CA2' => ['customer' => 'C10', 'emp' => 6, 'term' => 'Net 30', 'issued' => '2026-09-14 11:30', 'path' => 'cancelled', 'cancelled' => '2026-09-14 16:00', 'unsent' => true,
            'lines' => ['P013-1L' => 12, 'P008-135X280' => 17]],

        // Still being drafted.
        'D1' => ['customer' => 'C14', 'emp' => 6, 'term' => 'Net 7', 'issued' => '2026-09-04 11:00', 'path' => 'draft',
            'lines' => ['P013-500ML' => 11, 'P007-M' => 9]],
        'D2' => ['customer' => 'C09', 'emp' => 3, 'term' => 'Net 7', 'issued' => '2026-09-29 10:00', 'path' => 'draft',
            'lines' => ['P001-1L' => 2, 'P006-HEAVY' => 9]],
        'D3' => ['customer' => 'C07', 'emp' => 2, 'term' => 'Net 30', 'issued' => '2026-10-01 09:30', 'path' => 'draft',
            'lines' => ['P004-35X10' => 4, 'P005-35MM' => 10, 'P009-STD' => 6]],
    ];

    public function __construct(
        private readonly DemoSalesKit $kit,
        private readonly DemoSalesTimeline $timeline,
    ) {}

    public function register(): void
    {
        foreach (self::Quotes as $code => $quote) {
            $this->timeline->add($quote['issued'], "quotation {$code} created", fn () => $this->create($code));

            if ($quote['path'] !== 'draft' && ! ($quote['unsent'] ?? false)) {
                $this->timeline->add($quote['issued'], "quotation {$code} sent", fn () => $this->send($code));
            }

            match ($quote['path']) {
                'accepted' => $this->timeline->add($quote['decided'], "quotation {$code} accepted", fn () => $this->accept($code)),
                'converted' => $this->registerConversion($code),
                'rejected' => $this->timeline->add($quote['decided'], "quotation {$code} rejected", fn () => $this->reject($code)),
                'changes' => $this->registerChanges($code),
                'expired' => $this->timeline->add($quote['decided'], "quotation {$code} swept as expired", fn () => $this->sweep()),
                'cancelled' => $this->registerCancellation($code),
                default => null,
            };
        }
    }

    private function create(string $code): void
    {
        $quote = self::Quotes[$code];
        $customer = $this->kit->customer($quote['customer']);
        $employee = $this->kit->employee($quote['emp']);
        $this->kit->context->as('sales_manager');

        $attributes = [
            'customer_id' => $customer->getKey(),
            'employee_id' => $employee->getKey(),
            'payment_term_id' => $this->kit->term($quote['term'])->getKey(),
            'issue_date' => Carbon::parse($quote['issued'])->toDateString(),
            'notes' => "[DEMO-SALES] {$code} - ".$customer->user?->name.' / '.$employee->user?->name,
        ];

        if (isset($quote['expires'])) {
            $attributes['expires_at'] = $quote['expires'];
        }

        $this->kit->quotes[$code] = app(QuotationService::class)->create($attributes, $this->kit->lines($quote['lines']));
    }

    private function send(string $code): void
    {
        $this->kit->context->as('sales_manager');
        $this->kit->quotes[$code] = app(QuotationService::class)->send($this->kit->quotes[$code]->refresh());
    }

    private function accept(string $code): void
    {
        $quote = self::Quotes[$code];
        $this->kit->quotes[$code] = app(QuotationResponseService::class)->accept(
            $this->kit->quotes[$code]->refresh(),
            Carbon::parse($quote['decided']),
            $quote['note'] ?? null,
            null,
            $this->kit->context->as('sales_manager'),
        );
    }

    private function reject(string $code): void
    {
        $quote = self::Quotes[$code];
        $this->kit->quotes[$code] = app(QuotationResponseService::class)->reject(
            $this->kit->quotes[$code]->refresh(),
            Carbon::parse($quote['decided']),
            (string) $quote['note'],
            null,
            $this->kit->context->as('sales_manager'),
        );
    }

    private function registerConversion(string $code): void
    {
        $quote = self::Quotes[$code];
        $this->timeline->add($quote['decided'], "quotation {$code} accepted", fn () => $this->accept($code));
        $this->timeline->add($quote['converted'], "quotation {$code} converted to {$quote['order']}", function () use ($code, $quote): void {
            $this->kit->context->as('sales_manager');
            $order = app(QuotationConversionService::class)->convert($this->kit->quotes[$code]->refresh());
            $this->kit->orders[$quote['order']] = $order;
            $this->kit->quotes[$code] = $this->kit->quotes[$code]->refresh();
        });
        $this->timeline->add($quote['release'], "order {$quote['order']} released", function () use ($quote): void {
            $this->kit->orders[$quote['order']] = app(SalesOrderService::class)->release(
                $this->kit->context->as('sales_manager'),
                $this->kit->orders[$quote['order']]->refresh(),
            );
        });
    }

    private function registerChanges(string $code): void
    {
        $quote = self::Quotes[$code];
        $this->timeline->add($quote['decided'], "quotation {$code} changes requested", function () use ($code, $quote): void {
            $this->kit->quotes[$code] = app(QuotationResponseService::class)->requestChanges(
                $this->kit->quotes[$code]->refresh(),
                Carbon::parse($quote['decided']),
                (string) $quote['note'],
                null,
                $this->kit->context->as('sales_manager'),
            );
        });

        if (isset($quote['requote'])) {
            $this->timeline->add($quote['requote'], "quotation {$code} re-quoted", function () use ($code): void {
                $this->kit->context->as('sales_manager');
                $this->kit->quotes["{$code}-REQUOTE"] = app(QuotationService::class)->requote($this->kit->quotes[$code]->refresh());
            });
        }
    }

    private function registerCancellation(string $code): void
    {
        $quote = self::Quotes[$code];

        $this->timeline->add($quote['cancelled'], "quotation {$code} cancelled", function () use ($code): void {
            // No service owns this transition; Quotation::update is the only writer (see QuotationStatus).
            $this->kit->context->as('sales_manager');
            $this->kit->quotes[$code]->refresh()->update(['status' => QuotationStatus::Cancelled]);
        });
    }

    private function sweep(): void
    {
        Artisan::call('sales:quotations:expire');
    }

    /** Number of quotations a rerun guard may rely on. */
    public static function marker(): string
    {
        return '[DEMO-SALES]';
    }

    public static function exists(): bool
    {
        return Quotation::query()->where('notes', 'like', self::marker().'%')->exists();
    }
}
