<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;

final class DeliveryNotesCalendar extends ResourceCalendarPage
{
    protected static string $resource = DeliveryNoteResource::class;

    protected static string $calendarKey = 'delivery-notes';
}
