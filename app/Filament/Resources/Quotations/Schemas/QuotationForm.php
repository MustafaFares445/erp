<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label(__('admin.sales.fields.customer'))
                ->relationship('customer', 'company_name')
                ->searchable()
                ->searchPrompt('Search by company name...')
                ->searchDebounce(300)
                ->preload()
                ->live()
                ->required(),
            Select::make('employee_id')
                ->label(__('admin.sales.fields.employee'))
                ->relationship('employee', 'job_title')
                ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => (string) $record->employee_code)
                ->searchable()
                ->searchPrompt('Search by employee code...')
                ->searchDebounce(300)
                ->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.employee')),
            Select::make('payment_term_id')
                ->label(__('admin.sales.fields.payment_term'))
                ->relationship('paymentTerm', 'name')
                ->searchable()
                ->searchPrompt('Search by payment term name...')
                ->searchDebounce(300)
                ->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.payment_term')),
            DatePicker::make('issue_date')
                ->label(__('admin.sales.fields.issue_date'))
                ->required()
                ->default(now()),
            DatePicker::make('expires_at')
                ->label(__('admin.sales.fields.expires_at')),
            QuotationLinesRepeater::make()
                ->columnSpanFull(),
        ])->columns(2);
    }
}
