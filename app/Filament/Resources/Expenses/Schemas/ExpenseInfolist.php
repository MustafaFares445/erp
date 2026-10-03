<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Expense details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('expense_number')->label(__('Expense number')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (ExpenseStatus $state): string => $state->label())
                        ->color(fn (ExpenseStatus $state): string => $state->color()),
                    TextEntry::make('supplier.name')->label(__('Supplier'))->placeholder(__('Not linked')),
                    TextEntry::make('merchant_name')->label(__('Merchant'))->placeholder(__('Not provided')),
                    TextEntry::make('requestedBy.employee_code')->label(__('Requested by'))->placeholder(__('Not provided')),
                    TextEntry::make('expenseAccount.name')->label(__('Expense account')),
                    TextEntry::make('paymentMethod.name')->label(__('Payment method')),
                    TextEntry::make('expense_date')->label(__('Expense date'))->date(),
                    TextEntry::make('due_date')->label(__('Due date'))->date()->placeholder(__('Not provided')),
                    TextEntry::make('payment_date')->label(__('Payment date'))->date()->placeholder(__('Not paid')),
                    TextEntry::make('subtotal')->label(__('Subtotal'))->money(),
                    TextEntry::make('tax_total')->label(__('Tax'))->money(),
                    TextEntry::make('total_amount')->label(__('Total'))->money(),
                    TextEntry::make('amount_paid')->label(__('Paid'))->money(),
                    TextEntry::make('outstanding_amount')
                        ->label(__('Outstanding'))
                        ->state(fn (Expense $record): float => $record->outstandingAmount())
                        ->money(),
                    TextEntry::make('description')->label(__('Description'))->columnSpanFull(),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder(__('No notes'))->columnSpanFull(),
                ]),
        ]);
    }
}
