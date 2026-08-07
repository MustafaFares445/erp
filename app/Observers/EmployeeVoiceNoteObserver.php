<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EmployeeVoiceNote;
use App\Services\Audit\AuditLogger;

final readonly class EmployeeVoiceNoteObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function deleted(EmployeeVoiceNote $voiceNote): void
    {
        $this->auditLogger->log(
            action: 'voice_note.deleted',
            entity: $voiceNote,
            oldValues: $voiceNote->getOriginal(),
        );
    }
}
