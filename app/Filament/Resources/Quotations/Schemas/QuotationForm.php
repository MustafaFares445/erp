<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Services\Inventory\PriceResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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
            Placeholder::make('active_pricing_tier')
                ->label(__('admin.sales.fields.active_pricing_tier'))
                ->content(static fn (Get $get): string => self::activeTierLabel($get('customer_id'))),
            Select::make('employee_id')
                ->label(__('admin.sales.fields.employee'))
                ->relationship('employee', 'job_title', static fn (Builder $query): Builder => $query->with('user:id,name'))
                ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => sprintf('%s — %s', $record->employee_code, $record->user?->name))
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

    private static function activeTierLabel(mixed $customerId): string
    {
        if (! is_numeric($customerId)) {
            return __('admin.sales.hints.active_pricing_tier_none');
        }

        $customer = CustomerProfile::find((int) $customerId)?->user;

        $tier = $customer !== null ? app(PriceResolver::class)->activeTierFor($customer) : null;

        return $tier !== null
            ? __('admin.sales.hints.active_pricing_tier', ['tier' => $tier->name])
            : __('admin.sales.hints.active_pricing_tier_none');
    }
}
