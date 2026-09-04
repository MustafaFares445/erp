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
            self::LeadsBySource => 'Leads by source',
            self::StageConversion => 'Stage conversion',
            self::CampaignPerformance => 'Campaign performance',
            self::PipelineValueAndAge => 'Pipeline value and age',
            self::AttributedRevenue => 'Attributed revenue',
        };
    }
}
