<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\ScopesSupportTickets;
use App\Models\Ticket;
use App\Services\Support\TicketBlockerResolver;
use App\Services\Support\TicketSlaStateResolver;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open tickets that are blocked — awaiting triage, payment, assignment or
 * the customer — or breaching their SLA, latest activity first. A
 * current-state work queue, so it ignores the date range but respects the
 * assignee and priority filters.
 */
final class SupportNeedsAttention extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use ScopesSupportTickets;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.attention'))
            ->query(fn (): Builder => $this->scopeTickets(self::attentionQuery())
                ->with(['customer:id,company_name', 'assignedEmployee.user:id,name']))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(static fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('ticket_number')
                    ->label(__('dashboards.support.columns.ticket'))
                    ->description(static fn (Ticket $record): string => str($record->title)->limit(24)->toString())
                    ->tooltip(static fn (Ticket $record): string => $record->title)
                    ->weight('medium'),
                TextColumn::make('customer.company_name')
                    ->label(__('dashboards.support.columns.customer'))
                    ->description(static fn (Ticket $record): string => $record->assignedEmployee->user->name ?? __('dashboards.support.columns.unassigned'))
                    ->wrap(),
                TextColumn::make('blocked_by')
                    ->label(__('dashboards.support.columns.blocked_by'))
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketBlockerResolver::class)->resolve($record)->label())
                    ->badge()
                    ->color(static fn (Ticket $record): string => app(TicketBlockerResolver::class)->resolve($record)->color()),
                TextColumn::make('sla_state')
                    ->label(__('dashboards.support.columns.sla'))
                    ->badge()
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                    ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
            ]);
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
}
