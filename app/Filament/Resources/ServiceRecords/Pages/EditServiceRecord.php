<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\ServiceRecordService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditServiceRecord extends EditRecord
{
    protected static string $resource = ServiceRecordResource::class;

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
        /** @var User $actor */
        $actor = auth()->user();
        /** @var MaintenanceTask $record */

        return app(ServiceRecordService::class)->update($record, $data, $actor);
    }
}
