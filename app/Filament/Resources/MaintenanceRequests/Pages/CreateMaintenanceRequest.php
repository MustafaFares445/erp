<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/**
 * Supports two entry points (FR-060/061): a standalone create, and
 * "raise from this ticket" (linked from `Tickets/Tables/TicketsTable.php`
 * via a `?ticket_id=` query parameter, read through a Livewire `#[Url]`
 * property — matching {@see ModulePlaceholder}'s own
 * `$group`/`$item` pattern — which pre-fills the customer and description
 * from that ticket.
 */
final class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    #[Url(as: 'ticket_id')]
    public ?int $ticketId = null;

    #[\Override]
    public function mount(): void
    {
        parent::mount();

        $ticket = $this->ticketFromId($this->ticketId);

        if ($ticket instanceof Ticket) {
            $this->data['ticket_id'] = $ticket->getKey();
            $this->data['customer_id'] = $ticket->customer_id;
            $this->data['description'] = $ticket->description;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        // The admin panel's own auth middleware guarantees an authenticated User here.
        if (! $actor instanceof User) {
            abort(403);
        }

        // @codeCoverageIgnoreEnd

        $ticket = $this->ticketFromId($data['ticket_id'] ?? null);

        if ($ticket instanceof Ticket) {
            return app(MaintenanceRecordService::class)->createFromTicket($ticket, $data, $actor);
        }

        return app(MaintenanceRecordService::class)->createStandalone($data, $actor);
    }

    private function ticketFromId(mixed $value): ?Ticket
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Ticket::query()->find((int) $value);
        }

        return null;
    }
}
