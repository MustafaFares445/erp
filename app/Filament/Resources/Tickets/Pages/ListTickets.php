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
            'all' => Tab::make(__('All')),
            'open' => Tab::make(__('Open'))
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
            'new' => Tab::make(__('New'))
                ->badge(Ticket::query()->where('status', TicketStatus::Pending->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::Pending->value)),
            'pending_payment' => Tab::make(__('Pending Payment'))
                ->badge(Ticket::query()->where('status', TicketStatus::PendingPayment->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::PendingPayment->value)),
            'unassigned' => Tab::make(__('Unassigned'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', TicketStatus::Live->value)
                    ->whereNull('assigned_employee_id')),
            'in_progress' => Tab::make(__('In Progress'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    TicketStatus::Assigned->value,
                    TicketStatus::InProgress->value,
                ])),
            'waiting_customer' => Tab::make(__('Waiting Customer'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TicketStatus::WaitingCustomer->value)),
            'sla_breached' => Tab::make(__('SLA Breached'))
                ->modifyQueryUsing(self::slaBreachedQuery(...)),
            'resolved' => Tab::make(__('Resolved'))
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
