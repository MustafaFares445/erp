<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannel: string
{
    case Mail = 'mail';
    case Database = 'database';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
