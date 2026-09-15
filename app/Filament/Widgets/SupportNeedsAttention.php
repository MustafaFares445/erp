<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Services\Support\TicketSlaStateResolver;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class SupportNeedsAttention extends TableWidget
{
    protected static ?string $heading = 'Needs attention';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(self::attentionQuery())
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(static fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket #')->badge(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('title')->label('Issue')->limit(36),
                TextColumn::make('status')->badge(),
                TextColumn::make('blocked_by')
                    ->label('Blocked by')
                    ->getStateUsing(static fn (Ticket $record): string => self::blockedBy($record))
                    ->badge(),
                TextColumn::make('sla_state')
                    ->label('SLA')
                    ->badge()
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                    ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                TextColumn::make('assignedEmployee.user.name')->label('Assignee')->placeholder('Unassigned'),
                TextColumn::make('updated_at')->label('Last update')->since(),
            ])
            ->paginated([5, 10]);
    }

    /** @return Builder<Ticket> */
    private static function attentionQuery(): Builder
    {
        return Ticket::query()
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
            ->where(function (Builder $query): void {
                $query->where('status', TicketStatus::Pending->value)
                    ->orWhere('status', TicketStatus::PendingPayment->value)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', TicketStatus::Live->value)->whereNull('assigned_employee_id');
                    })
                    ->orWhere('response_breached', true)
                    ->orWhere('resolution_breached', true)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('first_response_at')
                            ->whereNotNull('response_due_at')
                            ->where('response_due_at', '<', now());
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('resolved_at')
                            ->whereNotNull('resolution_due_at')
                            ->where('resolution_due_at', '<', now());
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', TicketStatus::WaitingCustomer->value)
                            ->where('waiting_customer_since', '<=', now()->subDay());
                    });
            });
    }

    private static function blockedBy(Ticket $ticket): string
    {
        if ($ticket->status === TicketStatus::Pending) {
            return 'Awaiting triage';
        }

        if ($ticket->status === TicketStatus::PendingPayment) {
            return 'Payment';
        }

        if ($ticket->status === TicketStatus::Live && $ticket->assigned_employee_id === null) {
            return 'Assignment';
        }

        if ($ticket->status === TicketStatus::WaitingCustomer) {
            return 'Customer';
        }

        if ($ticket->isResponseBreached() || $ticket->isResolutionBreached()) {
            return 'SLA breach';
        }

        return $ticket->pending_reason ?: 'Action required';
    }
}
