<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\SalesOpportunity;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CrmFunnelReportService
{
    /** @return Collection<int, array{source: string, lead_count: int, converted_count: int}> */
    public function bySource(): Collection
    {
        $queryRows = Lead::query()->select('source')
            ->selectRaw('COUNT(*) as lead_count')
            ->selectRaw("SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count")
            ->groupBy('source')->orderBy('source')->get();
        $rows = [];

        foreach ($queryRows as $row) {
            $rows[] = [
                'source' => $this->stringValue($row->getAttribute('source')),
                'lead_count' => $this->intValue($row->getAttribute('lead_count')),
                'converted_count' => $this->intValue($row->getAttribute('converted_count')),
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{status: string, lead_count: int}> */
    public function byStage(): Collection
    {
        $queryRows = Lead::query()->select('status')
            ->selectRaw('COUNT(*) as lead_count')
            ->groupBy('status')->orderBy('status')->get();
        $rows = [];

        foreach ($queryRows as $row) {
            $rows[] = [
                'status' => $this->stringValue($row->getAttribute('status')),
                'lead_count' => $this->intValue($row->getAttribute('lead_count')),
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{campaign_number: string, name: string, recipients_count: int, interested_count: int, leads_count: int, opportunities_count: int}> */
    public function byCampaign(): Collection
    {
        $campaigns = Campaign::query()->orderByDesc('created_at')->get();
        $rows = [];

        foreach ($campaigns as $campaign) {
            $campaignId = $this->intValue($campaign->getKey());
            $interestedCount = DB::table('campaign_responses')
                ->join('campaign_recipients', 'campaign_recipients.id', '=', 'campaign_responses.campaign_recipient_id')
                ->where('campaign_recipients.campaign_id', $campaignId)
                ->where('campaign_responses.type', 'interested')
                ->distinct()
                ->count('campaign_responses.campaign_recipient_id');

            $rows[] = [
                'campaign_number' => $this->stringValue($campaign->getAttribute('campaign_number')),
                'name' => $this->stringValue($campaign->getAttribute('name')),
                'recipients_count' => $campaign->recipients()->count(),
                'interested_count' => $interestedCount,
                'leads_count' => $campaign->leads()->count(),
                'opportunities_count' => $campaign->opportunities()->count(),
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{stage: string, currency: string, opportunity_count: int, average_age_days: float, pipeline_value_minor: int}> */
    public function pipelineAge(): Collection
    {
        /** @var array<string, array{stage: string, currency: string, ages: list<int>, pipeline_value_minor: int}> $groups */
        $groups = [];

        foreach (SalesOpportunity::query()
            ->whereNotIn('stage', ['closed_won', 'closed_lost'])
            ->get(['stage', 'currency', 'estimated_value_minor', 'created_at']) as $opportunity) {
            $createdAt = $opportunity->created_at;
            $age = $createdAt === null
                ? 0
                : (int) $createdAt->copy()->startOfDay()->diffInDays(today());
            $stage = $opportunity->stage->value;
            $currency = $opportunity->currency;
            $key = $stage.'|'.$currency;

            $groups[$key] ??= [
                'stage' => $stage,
                'currency' => $currency,
                'ages' => [],
                'pipeline_value_minor' => 0,
            ];
            $groups[$key]['ages'][] = $age;
            $groups[$key]['pipeline_value_minor'] += is_numeric($opportunity->estimated_value_minor)
                ? (int) $opportunity->estimated_value_minor
                : 0;
        }

        ksort($groups);
        $rows = [];

        foreach ($groups as $group) {
            $count = count($group['ages']);
            $rows[] = [
                'stage' => $group['stage'],
                'currency' => $group['currency'],
                'opportunity_count' => $count,
                'average_age_days' => (float) ($count === 0 ? 0 : array_sum($group['ages']) / $count),
                'pipeline_value_minor' => $group['pipeline_value_minor'],
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{campaign_id: int, collected_amount: float}> */
    public function attributedRevenue(): Collection
    {
        $leadRows = DB::table('leads')
            ->join('invoices', 'invoices.customer_id', '=', 'leads.converted_customer_id')
            ->leftJoin('orders', 'orders.id', '=', 'invoices.order_id')
            ->leftJoin('quotations', 'quotations.id', '=', 'orders.quotation_id')
            ->leftJoin('sales_opportunities', 'sales_opportunities.id', '=', 'quotations.sales_opportunity_id')
            ->join('payment_allocations', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNotNull('leads.campaign_id')
            ->whereNull('sales_opportunities.campaign_id')
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereNull('leads.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->whereNull('payments.deleted_at')
            ->select('leads.campaign_id')
            ->selectRaw('SUM(payment_allocations.amount) as collected_amount')
            ->groupBy('leads.campaign_id')
            ->get();

        $opportunityRows = DB::table('sales_opportunities')
            ->join('quotations', 'quotations.sales_opportunity_id', '=', 'sales_opportunities.id')
            ->join('orders', 'orders.quotation_id', '=', 'quotations.id')
            ->join('invoices', 'invoices.order_id', '=', 'orders.id')
            ->join('payment_allocations', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNotNull('sales_opportunities.campaign_id')
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereNull('invoices.deleted_at')
            ->whereNull('payments.deleted_at')
            ->select('sales_opportunities.campaign_id')
            ->selectRaw('SUM(payment_allocations.amount) as collected_amount')
            ->groupBy('sales_opportunities.campaign_id')
            ->get();

        /** @var array<int, float> $totals */
        $totals = [];

        foreach ([$leadRows, $opportunityRows] as $sourceRows) {
            foreach ($sourceRows as $row) {
                $campaignId = $this->intValue(data_get($row, 'campaign_id'));

                if ($campaignId === 0) {
                    continue;
                }

                $totals[$campaignId] = ($totals[$campaignId] ?? 0.0)
                    + $this->floatValue(data_get($row, 'collected_amount'));
            }
        }

        ksort($totals);

        return collect($totals)
            ->map(fn (float $amount, int $campaignId): array => [
                'campaign_id' => $campaignId,
                'collected_amount' => $amount,
            ])
            ->values();
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
