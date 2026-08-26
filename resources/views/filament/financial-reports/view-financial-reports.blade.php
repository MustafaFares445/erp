<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('admin.resources.financial_reports') }}</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="reportType">
                    @foreach ($this->reportTypeOptions() as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="fiscalPeriodId">
                    <option value="">{{ __('admin.accounting.reports.fiscal_period') }}</option>
                    @foreach ($this->fiscalPeriodOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            @if ($reportType === \App\Enums\FinancialReportType::BalanceSheet)
                <x-filament::input.wrapper :label="__('admin.accounting.reports.as_of')">
                    <x-filament::input type="date" wire:model.live="asOf" />
                </x-filament::input.wrapper>
            @else
                <x-filament::input.wrapper :label="__('admin.accounting.reports.from')">
                    <x-filament::input type="date" wire:model.live="from" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper :label="__('admin.accounting.reports.to')">
                    <x-filament::input type="date" wire:model.live="to" />
                </x-filament::input.wrapper>
            @endif

            @if ($reportType === \App\Enums\FinancialReportType::GeneralLedger)
                <x-filament::input.wrapper :label="__('admin.accounting.reports.account_filter')">
                    <x-filament::input.select wire:model.live="accountId">
                        <option value="">{{ __('admin.accounting.reports.all_accounts') }}</option>
                        @foreach ($this->accountOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
        </div>
    </x-filament::section>

    @switch($reportType)
        @case (\App\Enums\FinancialReportType::TrialBalance)
            @include('filament.financial-reports.partials.trial-balance', ['report' => $report])
            @break

        @case (\App\Enums\FinancialReportType::GeneralLedger)
            @include('filament.financial-reports.partials.general-ledger', ['report' => $report])
            @break

        @case (\App\Enums\FinancialReportType::ProfitAndLoss)
            @include('filament.financial-reports.partials.profit-and-loss', ['report' => $report])
            @break

        @case (\App\Enums\FinancialReportType::BalanceSheet)
            @include('filament.financial-reports.partials.balance-sheet', ['report' => $report])
            @break

        @case (\App\Enums\FinancialReportType::PostingRegister)
            @include('filament.financial-reports.partials.posting-register', ['report' => $report])
            @break
    @endswitch
</x-filament-panels::page>
