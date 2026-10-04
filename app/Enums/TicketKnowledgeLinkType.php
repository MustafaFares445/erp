<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketKnowledgeLinkType: string
{
    case Suggested = 'suggested';
    case SharedWithCustomer = 'shared_with_customer';
    case UsedInResolution = 'used_in_resolution';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => __('Suggested'),
            self::SharedWithCustomer => __('Shared with customer'),
            self::UsedInResolution => __('Used in resolution'),
        };
    }
}
