<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * Identifies the account channel (constitution: `users.user_type`).
 *
 * Only `Admin` may access the Filament `admin` panel; `Customer` and
 * `Employee` operate through their own app-specific API channels.
 */
enum UserType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Admin = 'admin';
    case Customer = 'customer';
    case Employee = 'employee';
}
