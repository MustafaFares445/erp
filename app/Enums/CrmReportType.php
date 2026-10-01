<?php

declare(strict_types=1);

namespace App\Enums;

enum CrmReportType: string
{
    case LeadsBySource = 'leads_by_source';
    case StageConversion = 'stage_conversion';
    case CampaignPerformance = 'campaign_performance';
    case PipelineValueAndAge = 'pipeline_value_and_age';
    case AttributedRevenue = 'attributed_revenue';

    public function label(): string
    {
        return match ($this) {
            self::LeadsBySource => __(__('Leads by source')),
            self::StageConversion => __(__('Stage conversion')),
            self::CampaignPerformance => __(__('Campaign performance')),
            self::PipelineValueAndAge => __(__('Pipeline value and age')),
            self::AttributedRevenue => __(__('Attributed revenue')),
        };
    }
}
