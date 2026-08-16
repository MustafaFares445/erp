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
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        // The admin panel's own auth middleware guarantees an authenticated User, and
        // Filament's own EditRecord routing guarantees $record matches this resource's model.
        if (! $actor instanceof User) {
            abort(403);
        }

        if (! $record instanceof MaintenanceTask) {
            abort(404);
        }

        // @codeCoverageIgnoreEnd

        return app(ServiceRecordService::class)->update($record, $data, $actor);
    }
}
