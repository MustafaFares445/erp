<?php

declare(strict_types=1);

namespace App\Data\Crm;

use App\Enums\LeadSource;

final readonly class LeadData
{
    public function __construct(
        public LeadSource $source,
        public ?string $sourceDetail = null,
        public ?int $campaignId = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $companyName = null,
        public ?string $jobTitle = null,
        public ?string $email = null,
        public ?string $phone = null,
        public string $preferredLanguage = 'en',
        public ?int $assignedTo = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_detail' => $this->sourceDetail,
            'campaign_id' => $this->campaignId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company_name' => $this->companyName,
            'job_title' => $this->jobTitle,
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language' => $this->preferredLanguage,
            'assigned_to' => $this->assignedTo,
        ];
    }
}
