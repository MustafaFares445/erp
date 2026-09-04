<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Models\User;
use App\Services\Sales\SalesOpportunityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateSalesOpportunity extends CreateRecord
{
    protected static string $resource = SalesOpportunityResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SalesOpportunityService::class)->createManual($data, self::actor());
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated opportunity user is required.');
        }

        return $actor;
    }
}
