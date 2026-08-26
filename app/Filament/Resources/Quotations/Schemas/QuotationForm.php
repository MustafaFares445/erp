<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

final class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label(__('admin.sales.fields.customer'))
                ->relationship('customer', 'company_name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('employee_id')
                ->label(__('admin.sales.fields.employee'))
                ->relationship('employee', 'job_title')
                ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => (string) $record->employee_code)
                ->searchable()
                ->preload(),
            Select::make('payment_term_id')
                ->label(__('admin.sales.fields.payment_term'))
                ->relationship('paymentTerm', 'name')
                ->searchable()
                ->preload(),
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
