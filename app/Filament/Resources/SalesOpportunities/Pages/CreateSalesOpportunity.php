<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Data\Sales\OpportunityData;
use App\Enums\OpportunityOrigin;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Models\User;
use App\Services\Sales\OpportunityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateSalesOpportunity extends CreateRecord
{
    protected static string $resource = SalesOpportunityResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $summary = $data['summary'] ?? null;
        if (! is_string($summary) || mb_trim($summary) === '') {
            throw new LogicException('An opportunity summary is required.');
        }

        return app(OpportunityService::class)->create(new OpportunityData(
            summary: $summary,
            customerId: self::toIntOrNull($data['customer_id'] ?? null), leadId: self::toIntOrNull($data['lead_id'] ?? null),
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            estimatedValueMinor: self::toIntOrNull($data['estimated_value_minor'] ?? null),
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : 'AED',
            expectedCloseDate: is_string($data['expected_close_date'] ?? null) ? $data['expected_close_date'] : null,
            probabilityPercent: self::toIntOrNull($data['probability_percent'] ?? null), ownerId: self::toIntOrNull($data['owner_id'] ?? null),
            origin: OpportunityOrigin::Manual,
        ), self::actor());
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('Authenticated user required.');
        }

        return $actor;
    }
}
