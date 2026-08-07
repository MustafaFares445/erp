<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceNotes\Pages;

use App\Filament\Resources\VoiceNotes\VoiceNoteResource;
use Filament\Resources\Pages\ListRecords;

final class ListVoiceNotes extends ListRecords
{
    protected static string $resource = VoiceNoteResource::class;
}
