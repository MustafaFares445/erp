<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EmployeeVoiceNote;

final readonly class EmployeeVoiceNoteObserver
{
    public function deleted(EmployeeVoiceNote $voiceNote): void
    {
        activity()
            ->performedOn($voiceNote)
            ->withChanges(['old' => $voiceNote->getOriginal()])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('voice_note.deleted');
    }
}
