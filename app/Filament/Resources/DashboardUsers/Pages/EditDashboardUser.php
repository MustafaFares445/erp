<?php

declare(strict_types=1);

namespace App\Filament\Resources\DashboardUsers\Pages;

use App\Enums\CrmPermission;
use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Models\User;
use App\Services\Identity\DashboardRoleAssignmentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditDashboardUser extends EditRecord
{
    protected static string $resource = DashboardUserResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User || ! is_string($data['role_name'] ?? null)) {
            throw new LogicException('A dashboard user and fixed role are required.');
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated dashboard role administrator is required.');
        }

        return app(DashboardRoleAssignmentService::class)->assign($record, $data['role_name'], $actor);
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $roleName = $record->roles()->whereIn('name', CrmPermission::fixedRoleNames())->value('name');

        return [...$data, 'role_name' => $roleName];
    }
}
