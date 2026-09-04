<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignSendStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'campaign_id', 'recipient_type', 'recipient_id', 'email', 'phone', 'send_status', 'send_error',
    'sent_at', 'notification_delivery_id',
])]
final class CampaignRecipient extends Model
{
    protected $attributes = ['send_status' => 'pending'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'send_status' => CampaignSendStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return MorphTo<Model, $this> */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<NotificationDelivery, $this> */
    public function notificationDelivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class);
    }

    /** @return HasMany<CampaignResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(CampaignResponse::class)->orderBy('occurred_at');
    }
}
