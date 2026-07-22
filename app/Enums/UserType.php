<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Identifies the account channel (constitution: `users.user_type`).
 *
 * Only `Admin` may access the Filament `admin` panel; `Customer` and
 * `Employee` operate through their own app-specific API channels.
 */
enum UserType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case Employee = 'employee';
}
