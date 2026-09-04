<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'channel', 'content_template_id', 'scheduled_at', 'segment_criteria'])]
final class Campaign extends Model
{
    use SoftDeletes;

    protected $attributes = ['status' => 'draft'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'channel' => CampaignChannel::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'segment_criteria' => 'array',
        ];
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function contentTemplate(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'content_template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
