<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $selectedReportType->label() }}</x-slot>
            <x-slot name="description">{{ __('reporting.reports.financial.'.$selectedReportType->value) }}</x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-filament::input.wrapper :label="__('Report')">
                    <x-filament::input.select wire:model.live="reportType">
                        @foreach ($this->reportTypeOptions() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper :label="__('admin.accounting.reports.fiscal_period')">
                    <x-filament::input.select wire:model.live="fiscalPeriodId">
                        <option value="">{{ __('All / custom period') }}</option>
                        @foreach ($this->fiscalPeriodOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                @if ($selectedReportType === \App\Enums\FinancialReportType::BalanceSheet)
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
            </div>

            @if ($selectedReportType === \App\Enums\FinancialReportType::GeneralLedger)
                <div class="mt-4 max-w-2xl">
                    <x-filament::input.wrapper :label="__('admin.accounting.reports.account_filter')">
                        <x-filament::input.select wire:model.live="accountId">
                            <option value="">{{ __('admin.accounting.reports.all_accounts') }}</option>
                            @foreach ($this->accountOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if ($selectedReportType === \App\Enums\FinancialReportType::BalanceSheet)
                    <x-filament::badge color="gray">{{ __('As of') }}: {{ $asOf }}</x-filament::badge>
                @else
                    <x-filament::badge color="gray">{{ __('From') }}: {{ $from }}</x-filament::badge>
                    <x-filament::badge color="gray">{{ __('To') }}: {{ $to }}</x-filament::badge>
                @endif
                @if ($fiscalPeriodId)
                    <x-filament::badge color="gray">{{ __('Fiscal period selected') }}</x-filament::badge>
                @endif
                @if ($accountId)
                    <x-filament::badge color="gray">{{ __('Account filtered') }}</x-filament::badge>
                @endif
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    {{ __('Reset filters') }}
                </button>
            </div>
        </x-filament::section>

        <div wire:loading.delay>
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove>
            @switch($selectedReportType)
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
        </div>
    </div>
</x-filament-panels::page>
