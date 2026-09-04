<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\Lead;
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

    /** @return Collection<int, array{campaign_number: string, name: string, recipients_count: int, interested_count: int, leads_count: int}> */
    public function byCampaign(): Collection
    {
        $campaigns = Campaign::query()
            ->withCount(['recipients', 'leads'])
            ->withCount(['recipients as interested_count' => fn ($query) => $query->whereHas('responses', fn ($query) => $query->where('type', 'interested'))])
            ->orderByDesc('created_at')->get();
        $rows = [];

        foreach ($campaigns as $campaign) {
            $rows[] = [
                'campaign_number' => $this->stringValue($campaign->getAttribute('campaign_number')),
                'name' => $this->stringValue($campaign->getAttribute('name')),
                'recipients_count' => $this->intValue($campaign->getAttribute('recipients_count')),
                'interested_count' => $this->intValue($campaign->getAttribute('interested_count')),
                'leads_count' => $this->intValue($campaign->getAttribute('leads_count')),
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{status: string, lead_count: int, average_age_days: float}> */
    public function pipelineAge(): Collection
    {
        /** @var array<string, list<int>> $agesByStatus */
        $agesByStatus = [];

        foreach (Lead::query()
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Disqualified->value])
            ->get(['status', 'created_at']) as $lead) {
            $createdAt = $lead->created_at;
            $age = $createdAt === null
                ? 0
                : (int) $createdAt->copy()->startOfDay()->diffInDays(today());
            $agesByStatus[$lead->status->value][] = $age;
        }

        $rows = [];
        ksort($agesByStatus);

        foreach ($agesByStatus as $status => $ages) {
            $count = count($ages);
            $rows[] = [
                'status' => $status,
                'lead_count' => $count,
                'average_age_days' => $count === 0 ? 0.0 : array_sum($ages) / $count,
            ];
        }

        return collect($rows);
    }

    /** @return Collection<int, array{campaign_id: int, collected_amount: float}> */
    public function attributedRevenue(): Collection
    {
        $queryRows = DB::table('leads')
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
        $rows = [];

        foreach ($queryRows as $row) {
            $rows[] = [
                'campaign_id' => $this->intValue(data_get($row, 'campaign_id')),
                'collected_amount' => $this->floatValue(data_get($row, 'collected_amount')),
            ];
        }

        return collect($rows);
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
