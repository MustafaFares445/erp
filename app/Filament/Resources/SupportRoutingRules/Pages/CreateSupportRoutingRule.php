<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules\Pages;

use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportRoutingRule extends CreateRecord
{
    protected static string $resource = SupportRoutingRuleResource::class;
}
