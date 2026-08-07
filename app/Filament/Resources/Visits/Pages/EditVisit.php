<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\CustomerVisit;
use App\Services\Employees\VisitReviewService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditVisit extends EditRecord
{
    protected static string $resource = VisitResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof CustomerVisit) {
            throw new LogicException('Expected a CustomerVisit record.');
        }

        return app(VisitReviewService::class)->updateFieldRecordedVisit($record, $data);
    }
}
