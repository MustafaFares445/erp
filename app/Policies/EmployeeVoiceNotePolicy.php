<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DashboardRole;
use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class EmployeeVoiceNotePolicy
{
    use ChecksEmployeePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'view');
    }

    /**
     * The permission catalogue has no `voice-note.manage` ability — a voice
     * note is a field-captured record this dashboard reviews, not one it
     * normally creates, edits, or deletes. Deletion (FR-084) is therefore a
     * `System Admin`-only escape hatch, checked directly rather than through
     * {@see self::employeePermissionMap()}, so no other fixed role gains it
     * implicitly through `voice-note.view`/`voice-note.play`.
     *
     * This must check the `System Admin` *role* specifically
     * (`hasRole()`), never `$user->isAdmin()` — that method only reports
     * the dashboard *channel* (`user_type`), which every fixed role shares,
     * so it would silently grant this to Employee Manager/Payroll
     * Officer/Reviewer too.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    public function update(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    public function delete(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    public function restore(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasRole(DashboardRole::SystemAdmin->value);
    }

    /**
     * Listening to the audio itself is a separate ability from viewing the
     * voice note's metadata — a `Reviewer` may see that a note exists
     * without being able to play it.
     */
    public function play(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'play');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::VoiceNoteView->value,
            'view' => EmployeePermission::VoiceNoteView->value,
            'play' => EmployeePermission::VoiceNotePlay->value,
        ];
    }
}
