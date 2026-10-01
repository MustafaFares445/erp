<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyFailureCategory: string
{
    case ManufacturingDefect = 'manufacturing_defect';
    case NormalComponentFailure = 'normal_component_failure';
    case AccidentalDamage = 'accidental_damage';
    case Misuse = 'misuse';
    case Consumable = 'consumable';
    case InstallationIssue = 'installation_issue';
    case ExternalCause = 'external_cause';
    case Unknown = 'unknown';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
