<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'notifiable_type',
    'notifiable_id',
    'template_key',
    'channel',
    'locale',
    'route',
    'subject_document_type',
    'subject_document_id',
    'status',
    'attempt',
    'variables',
    'error',
    'queued_at',
    'sent_at',
    'failed_at',
])]
final class NotificationDelivery extends Model
{
    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'attempt' => 'integer',
            'variables' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function subjectDocument(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_document_type', 'subject_document_id');
    }
}
