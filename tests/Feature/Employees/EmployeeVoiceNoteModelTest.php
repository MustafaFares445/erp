<?php

declare(strict_types=1);

use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\VoiceNoteTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves its customer visit, employee, and transcription relations', function (): void {
    $visit = CustomerVisit::factory()->create();
    $employee = EmployeeProfile::factory()->create();
    $voiceNote = EmployeeVoiceNote::factory()->create([
        'customer_visit_id' => $visit->getKey(),
        'employee_id' => $employee->getKey(),
    ]);
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    expect($voiceNote->customerVisit()->first()->is($visit))->toBeTrue()
        ->and($voiceNote->employee()->first()->is($employee))->toBeTrue()
        ->and($voiceNote->transcription()->first()->is($transcription))->toBeTrue();
});
