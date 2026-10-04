<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeArticleVisibility: string
{
    case Internal = 'internal';
    case Customer = 'customer';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Internal => __('Internal only'),
            self::Customer => __('Customer only'),
            self::Both => __('Internal & customer'),
        };
    }

    /** @return list<string> */
    public static function customerVisibleValues(): array
    {
        return [self::Customer->value, self::Both->value];
    }
}
