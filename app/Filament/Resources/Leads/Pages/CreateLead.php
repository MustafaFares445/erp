<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Data\Crm\LeadData;
use App\Enums\LeadSource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\User;
use App\Services\Crm\LeadService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(LeadService::class)->create(self::dto($data), self::actor());
    }

    /** @param array<string, mixed> $data */
    private static function dto(array $data): LeadData
    {
        $preferredLanguage = $data['preferred_language'] ?? 'en';
        $assignedTo = $data['assigned_to'] ?? null;

        return new LeadData(
            source: LeadSource::from(self::requiredString($data, 'source')),
            sourceDetail: is_string($data['source_detail'] ?? null) ? $data['source_detail'] : null,
            firstName: is_string($data['first_name'] ?? null) ? $data['first_name'] : null,
            lastName: is_string($data['last_name'] ?? null) ? $data['last_name'] : null,
            companyName: is_string($data['company_name'] ?? null) ? $data['company_name'] : null,
            jobTitle: is_string($data['job_title'] ?? null) ? $data['job_title'] : null,
            email: is_string($data['email'] ?? null) ? $data['email'] : null,
            phone: is_string($data['phone'] ?? null) ? $data['phone'] : null,
            preferredLanguage: is_string($preferredLanguage) ? $preferredLanguage : 'en',
            assignedTo: is_numeric($assignedTo) ? (int) $assignedTo : null,
        );
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new LogicException(sprintf('Expected a non-empty string for "%s".', $key));
        }

        return $value;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated CRM user is required.');
        }

        return $actor;
    }
}
