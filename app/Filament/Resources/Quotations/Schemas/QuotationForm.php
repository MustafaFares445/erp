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
use Illuminate\Support\HtmlString;

final class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label(__('admin.sales.fields.customer'))
                ->relationship('customer', 'company_name')
                ->searchable()
                ->searchPrompt(__('Search by company name...'))
                ->searchDebounce(300)
                ->preload()
                ->live()
                ->required(),
            Placeholder::make('active_pricing_tier')
                ->label(__('admin.sales.fields.active_pricing_tier'))
                ->content(static fn (Get $get): HtmlString => self::activeTierCard($get('customer_id'))),
            Select::make('employee_id')
                ->label(__('admin.sales.fields.employee'))
                ->relationship('employee', 'job_title', static fn (Builder $query): Builder => $query->with('user:id,name'))
                ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => sprintf('%s — %s', $record->employee_code, $record->user?->name))
                ->searchable()
                ->searchPrompt(__('Search by employee code...'))
                ->searchDebounce(300)
                ->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.employee')),
            Select::make('payment_term_id')
                ->label(__('admin.sales.fields.payment_term'))
                ->relationship('paymentTerm', 'name')
                ->searchable()
                ->searchPrompt(__('Search by payment term name...'))
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

    private static function activeTierCard(mixed $customerId): HtmlString
    {
        $tier = null;

        if (is_numeric($customerId)) {
            $customer = CustomerProfile::find((int) $customerId)?->user;
            $tier = $customer !== null ? app(PriceResolver::class)->activeTierFor($customer) : null;
        }

        if ($tier !== null) {
            return new HtmlString(
                '<div class="flex items-start gap-2 rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">'
                .'<svg class="mt-0.5 h-5 w-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>'
                .'<p>'.e(__('admin.sales.hints.active_pricing_tier', ['tier' => $tier->name])).'</p>'
                .'</div>'
            );
        }

        return new HtmlString(
            '<div class="flex items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm text-gray-700 dark:border-gray-500/30 dark:bg-gray-500/10 dark:text-gray-400">'
            .'<svg class="mt-0.5 h-5 w-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>'
            .'<p>'.e(__('admin.sales.hints.active_pricing_tier_none')).'</p>'
            .'</div>'
        );
    }
}
