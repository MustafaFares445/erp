<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
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
                TextColumn::make('pending_reason')->label('Blocked by')->placeholder('—')->limit(30),
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
}
