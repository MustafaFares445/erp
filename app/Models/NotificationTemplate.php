<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'locale',
    'channel',
    'subject',
    'body',
    'variables',
    'is_active',
])]
final class NotificationTemplate extends Model
{
    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
