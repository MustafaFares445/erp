<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CrmFunnelReportService
{
    /** @return Collection<int, object> */
    public function bySource(): Collection
    {
        return Lead::query()->select('source')
            ->selectRaw('COUNT(*) as lead_count')
            ->selectRaw("SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count")
            ->groupBy('source')->orderBy('source')->get();
    }

    /** @return Collection<int, object> */
    public function byStage(): Collection
    {
        return Lead::query()->select('status')
            ->selectRaw('COUNT(*) as lead_count')
            ->groupBy('status')->orderBy('status')->get();
    }

    /** @return Collection<int, Campaign> */
    public function byCampaign(): Collection
    {
        return Campaign::query()
            ->withCount(['recipients', 'leads'])
            ->withCount(['recipients as interested_count' => fn ($query) => $query->whereHas('responses', fn ($query) => $query->where('type', 'interested'))])
            ->orderByDesc('created_at')->get();
    }

    /** @return Collection<int, object> */
    public function pipelineAge(): Collection
    {
        return Lead::query()
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Disqualified->value])
            ->get(['status', 'created_at'])
            ->groupBy(fn (Lead $lead): string => $lead->status->value)
            ->map(function (Collection $leads, string $status): object {
                $averageAge = $leads->avg(
                    fn (Lead $lead): int => (int) $lead->created_at->copy()->startOfDay()->diffInDays(today()),
                );

                return (object) [
                    'status' => $status,
                    'lead_count' => $leads->count(),
                    'average_age_days' => (float) ($averageAge ?? 0),
                ];
            })
            ->sortBy('status')
            ->values();
    }

    /** @return Collection<int, object> */
    public function attributedRevenue(): Collection
    {
        return DB::table('leads')
            ->join('invoices', 'invoices.customer_id', '=', 'leads.converted_customer_id')
            ->join('payment_allocations', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNotNull('leads.campaign_id')
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereNull('leads.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->whereNull('payments.deleted_at')
            ->select('leads.campaign_id')
            ->selectRaw('SUM(payment_allocations.amount) as collected_amount')
            ->groupBy('leads.campaign_id')->orderBy('leads.campaign_id')->get();
    }
}
