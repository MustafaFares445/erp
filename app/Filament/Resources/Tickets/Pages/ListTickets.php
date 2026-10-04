<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListTickets extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = TicketResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'support.tickets';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->icon(Heroicon::OutlinedQueueList),
            'mine' => Tab::make(__('My Queue'))
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereHas(
                    'assignedEmployee',
                    static fn (Builder $employee): Builder => $employee->where('user_id', auth()->id()),
                )),
            'triage' => Tab::make(__('Needs Triage'))
                ->badge(Ticket::query()->where('status', TicketStatus::Pending->value)->count())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', TicketStatus::Pending->value)),
            'unassigned' => Tab::make(__('Unassigned'))
                ->badge(Ticket::query()->where('status', TicketStatus::Live->value)->whereNull('assigned_employee_id')->count())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                    ->where('status', TicketStatus::Live->value)
                    ->whereNull('assigned_employee_id')),
            'sla_risk' => Tab::make(__('SLA Risk'))
                ->modifyQueryUsing(self::slaRiskQuery(...)),
            'waiting_customer' => Tab::make(__('Waiting Customer'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', TicketStatus::WaitingCustomer->value)),
            'resolved' => Tab::make(__('Resolved'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereIn('status', [
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                ])),
        ];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function slaRiskQuery(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(fn (Builder $query): Builder => $query->responseBreached())
                ->orWhere(fn (Builder $query): Builder => $query->resolutionBreached())
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('first_response_at')
                        ->whereNotNull('response_due_at')
                        ->whereBetween('response_due_at', [now(), now()->addHour()]);
                })
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('resolved_at')
                        ->whereNotNull('resolution_due_at')
                        ->whereBetween('resolution_due_at', [now(), now()->addHours(4)]);
                });
        });
    }
}
