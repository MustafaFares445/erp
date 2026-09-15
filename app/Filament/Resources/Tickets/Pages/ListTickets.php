<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'open' => Tab::make('Open')
                ->badge(Ticket::query()->whereNotIn('status', [
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                    TicketStatus::Cancelled->value,
                ])->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('status', [
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                    TicketStatus::Cancelled->value,
                ])),
            'new' => Tab::make('New')
                ->badge(Ticket::query()->where('status', TicketStatus::Pending->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::Pending->value)),
            'pending_payment' => Tab::make('Pending Payment')
                ->badge(Ticket::query()->where('status', TicketStatus::PendingPayment->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::PendingPayment->value)),
            'unassigned' => Tab::make('Unassigned')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', TicketStatus::Live->value)
                    ->whereNull('assigned_employee_id')),
            'in_progress' => Tab::make('In Progress')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    TicketStatus::Assigned->value,
                    TicketStatus::InProgress->value,
                ])),
            'waiting_customer' => Tab::make('Waiting Customer')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::WaitingCustomer->value)),
            'sla_breached' => Tab::make('SLA Breached')
                ->modifyQueryUsing(self::slaBreachedQuery(...)),
            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                ])),
        ];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function slaBreachedQuery(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(fn (Builder $query): Builder => $query->responseBreached())
                ->orWhere(fn (Builder $query): Builder => $query->resolutionBreached());
        });
    }
}
