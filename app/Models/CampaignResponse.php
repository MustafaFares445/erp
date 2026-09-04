<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignResponseType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_recipient_id', 'type', 'occurred_at', 'payload', 'created_lead_id'])]
final class CampaignResponse extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CampaignResponseType::class,
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function createdLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'created_lead_id');
    }
}
