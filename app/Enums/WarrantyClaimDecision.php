<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyClaimDecision: string
{
    case PendingDiagnosis = 'pending_diagnosis';
    case FullyCovered = 'fully_covered';
    case PartiallyCovered = 'partially_covered';
    case Rejected = 'rejected';
    case Goodwill = 'goodwill';
    case ThirdPartyWarranty = 'third_party_warranty';
    case ServiceContract = 'service_contract';

    public function label(): string
    {
        return match ($this) {
            self::PendingDiagnosis => 'Pending diagnosis',
            self::FullyCovered => 'Fully covered',
            self::PartiallyCovered => 'Partially covered',
            self::Rejected => 'Not covered',
            self::Goodwill => 'Goodwill',
            self::ThirdPartyWarranty => 'Manufacturer / supplier warranty',
            self::ServiceContract => 'Service contract',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingDiagnosis => 'warning',
            self::FullyCovered => 'success',
            self::PartiallyCovered => 'info',
            self::Rejected => 'danger',
            self::Goodwill, self::ThirdPartyWarranty, self::ServiceContract => 'primary',
        };
    }
}
