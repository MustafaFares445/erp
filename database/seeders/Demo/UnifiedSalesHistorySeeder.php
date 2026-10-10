<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\QuotationDecision;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Crm\LeadService;
use App\Services\Sales\QuotationService;
use Illuminate\Support\Carbon;

/**
 * Twelve months of genuine CRM events and quotations for ONE developer database.
 * Actions use domain services, never direct writes to workflow status columns.
 * All records have stable synthetic keys and are safe to resume after partial failure.
 */
final class UnifiedSalesHistorySeeder extends DemoSeeder
{
    private const int Leads = 140;

    private const int Quotations = 330;

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()))
            ->startOfDay();

        $this->leads($context, $anchor);
        $this->quotations($context, $anchor);
        $this->note('Added synthetic CRM contacts and 12-month quotations via domain services.');
    }

    private function leads(DemoContext $context, Carbon $anchor): void
    {
        $service = app(LeadService::class);
        $actor = $context->actor('crm_manager');
        $given = ['Hamad', 'Maha', 'Reem', 'Faris', 'Lina', 'Omar', 'Hind', 'Yousef', 'Salma', 'Tariq', 'Nour', 'Zaid'];
        $family = ['Al Qasimi', 'Haddad', 'Al Falasi', 'Rahman', 'Al Nuaimi', 'Karam', 'Mansour', 'Al Ketbi', 'Siddiqui', 'Khoury'];
        $company = ['Dental Wellness', 'Orthodontic Studio', 'Clinical Laboratory', 'Implant Clinic', 'Medical Distribution', 'Dental Care Group'];
        $sources = [LeadSource::Website, LeadSource::Referral, LeadSource::Exhibition, LeadSource::Partner, LeadSource::Campaign, LeadSource::ColdCall, LeadSource::FieldObservation];

        for ($n = 1; $n <= self::Leads; $n++) {
            $email = sprintf('history.lead%03d@prospect.test', $n);
            if (Lead::query()->where('email', $email)->exists()) {
                continue;
            }

            // Spread activity evenly over 12 completed months plus the current month.
            $createdAt = $anchor->copy()->subMonths(12 - ($n % 13))
                ->startOfMonth()->addDays(2 + (($n * 7) % 25))->setTime(9 + $n % 8, 15);
            if ($createdAt->greaterThan($anchor)) {
                $createdAt = $anchor->copy()->subDays($n % 9 + 1);
            }

            $context->at($createdAt->toDateTimeString());
            $context->as('crm_manager');
            $lead = $service->create(new LeadData(
                source: $sources[$n % count($sources)],
                sourceDetail: 'Synthetic dental market enquiry '.sprintf('%03d', $n),
                firstName: $given[$n % count($given)],
                lastName: $family[$n % count($family)],
                companyName: $company[$n % count($company)].' '.sprintf('%03d', $n),
                jobTitle: ['Dentist', 'Procurement Manager', 'Lab Director', 'Clinic Manager'][$n % 4],
                email: $email,
                phone: sprintf('+97150090%04d', $n),
                preferredLanguage: $n % 3 === 0 ? 'ar' : 'en',
            ), $actor);

            if ($n % 7 === 0) {
                continue; // Deliberately untouched new lead.
            }

            $followUp = $createdAt->copy()->addHours(4);
            $context->at($followUp->toDateTimeString());
            $data = new InteractionData(
                subject: $lead,
                type: $n % 3 === 0 ? InteractionType::Email : InteractionType::Call,
                direction: InteractionDirection::Outbound,
                occurredAt: $followUp,
                summary: sprintf('[DEMO-HISTORY] Qualification contact %03d', $n),
                outcome: $n % 11 === 0 ? InteractionOutcome::NoAnswer : InteractionOutcome::Positive,
                notes: 'Discussed sample catalogue, minimum order and UAE fulfilment lead times.',
            );
            $service->logAndAdvance($lead, $data, LeadStatus::Contacted, $actor);

            if ($n % 3 !== 0) {
                continue;
            }

            $next = $createdAt->copy()->addDays(3)->setTime(10, 0);
            $context->at($next->toDateTimeString());
            $service->logAndAdvance($lead->refresh(), new InteractionData(
                subject: $lead,
                type: InteractionType::Meeting,
                direction: InteractionDirection::Outbound,
                occurredAt: $next,
                summary: sprintf('[DEMO-HISTORY] Product demonstration %03d', $n),
                outcome: InteractionOutcome::Positive,
                notes: 'Reviewed expected demand, sample packs and procurement timelines.',
            ), LeadStatus::Qualified, $actor);
        }
    }

    private function quotations(DemoContext $context, Carbon $anchor): void
    {
        $service = app(QuotationService::class);
        $actor = $context->actor('sales_manager');
        $customers = CustomerProfile::query()
            ->where('customer_code', 'like', 'DEMO-CUST-%')
            ->where('is_active', true)->orderBy('customer_code')->get();
        $variants = ProductVariant::query()
            ->where('sku', 'like', 'DEMO-REAL-%')
            ->where('is_active', true)->orderBy('sku')->get();

        if ($customers->isEmpty() || $variants->isEmpty()) {
            throw new \LogicException('Unified master data must be seeded before historical quotations.');
        }

        $customersCount = $customers->count();
        $variantsCount = $variants->count();

        for ($n = 1; $n <= self::Quotations; $n++) {
            $key = sprintf('[DEMO-HISTORY] Quotation %04d', $n);
            if (Quotation::query()->where('notes', $key)->exists()) {
                continue;
            }

            $month = 12 - (($n - 1) % 13);
            $createdAt = $anchor->copy()->subMonths($month)->startOfMonth()
                ->addDays(1 + (($n * 11) % 25))->setTime(8 + ($n % 9), 10);
            if ($createdAt->greaterThan($anchor)) {
                $createdAt = $anchor->copy()->subDays($n % 9 + 1);
            }

            $context->at($createdAt->toDateTimeString());
            $context->as('sales_manager');
            $customer = $customers[($n * 17) % $customersCount]
                ?? throw new \LogicException('Missing customer for historical quote.');
            $variant = $variants[($n * 13) % $variantsCount]
                ?? throw new \LogicException('Missing variant for historical quote.');
            $quotation = $service->create([
                'customer_id' => $customer->getKey(),
                'issue_date' => $createdAt->toDateString(),
                'expires_at' => $createdAt->copy()->addDays(30)->toDateString(),
                'notes' => $key,
            ], [[
                'product_variant_id' => DemoContext::keyOf($variant),
                'unit_id' => (int) $variant->unit_id,
                'quantity' => 1 + ($n % 4),
            ]]);

            if ($n % 8 === 0) {
                continue; // Legitimate pending draft.
            }

            $service->send($quotation);

            if ($n % 8 === 1) {
                $decisionAt = $createdAt->copy()->addDays(2);
                $context->at($decisionAt->toDateTimeString());
                $service->recordDecision(
                    $quotation, QuotationDecision::Rejected, $decisionAt,
                    'Customer selected an alternative solution.', $actor,
                );
            } elseif ($n % 8 === 2) {
                $decisionAt = $createdAt->copy()->addDays(3);
                $context->at($decisionAt->toDateTimeString());
                $service->recordDecision(
                    $quotation, QuotationDecision::Accepted, $decisionAt,
                    'Accepted and awaiting order conversion.', $actor,
                );
            } elseif ($n % 8 === 3 && $createdAt->copy()->addDays(31)->lessThanOrEqualTo($anchor)) {
                $context->at($createdAt->copy()->addDays(31)->toDateTimeString());
                $service->expire($quotation);
            }
        }
    }
}
