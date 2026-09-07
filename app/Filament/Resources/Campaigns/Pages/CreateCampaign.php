<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Pages;

use App\Data\Crm\CampaignData;
use App\Enums\CampaignChannel;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\User;
use App\Services\Crm\CampaignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated CRM user is required.');
        }

        $templateId = $data['content_template_id'] ?? null;

        return app(CampaignService::class)->create(new CampaignData(
            name: self::requiredString($data, 'name'),
            channel: CampaignChannel::from(self::requiredString($data, 'channel')),
            contentTemplateId: is_numeric($templateId) ? (int) $templateId : null,
        ), $actor);
    }

    /** @param array<mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new LogicException(sprintf('Expected a non-empty string for "%s".', $key));
        }

        return $value;
    }
}
