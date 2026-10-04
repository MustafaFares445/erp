<div class="space-y-6" data-testid="support-equipment-360">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-filament::section>
                <x-slot name="heading">{{ __('Equipment identity') }}</x-slot>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div><div class="text-xs text-gray-500">{{ __('Serial') }}</div><div class="mt-1 font-semibold">{{ $unit->serial_number }}</div></div>
                    <div><div class="text-xs text-gray-500">{{ __('Product') }}</div><div class="mt-1 font-semibold">{{ $unit->productVariant?->product?->name ?? '—' }}</div></div>
                    <div><div class="text-xs text-gray-500">{{ __('SKU') }}</div><div class="mt-1">{{ $unit->productVariant?->sku ?? '—' }}</div></div>
                    <div><div class="text-xs text-gray-500">{{ __('Customer') }}</div><div class="mt-1">{{ $customer?->company_name ?? __('Not currently in customer custody') }}</div></div>
                    <div><div class="text-xs text-gray-500">{{ __('Inventory status') }}</div><div class="mt-1">{{ $unit->status?->label() ?? '—' }}</div></div>
                    <div><div class="text-xs text-gray-500">{{ __('Condition') }}</div><div class="mt-1">{{ $unit->stock_condition?->label() ?? '—' }}</div></div>
                </div>
            </x-filament::section>

            <x-filament::section data-testid="equipment-installation-section">
                <x-slot name="heading">{{ __('Installation & Commissioning') }}</x-slot>
                @if($installationProgress)
                    @include('filament.support.installation-progress', ['progress' => $installationProgress])
                    @if($installation)
                        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                            <div><dt class="text-gray-500">{{ __('Installed') }}</dt><dd class="mt-1">{{ $installation->installed_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Installed by') }}</dt><dd class="mt-1">{{ $installation->installedBy?->user?->name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Shipment') }}</dt><dd class="mt-1">{{ $installation->shipment?->tracking_number ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Commissioning') }}</dt><dd class="mt-1">{{ $installation->commissioning_status->label() }}@if($installation->commissioned_at) · {{ $installation->commissioned_at->toDayDateTimeString() }}@endif</dd></div>
                            <div><dt class="text-gray-500">{{ __('Commissioned by') }}</dt><dd class="mt-1">{{ $installation->commissionedBy?->user?->name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Commissioning failure reason') }}</dt><dd class="mt-1">{{ $installation->commissioning_failure_reason ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Customer acceptance') }}</dt><dd class="mt-1">{{ $installation->customer_acceptance_status->label() }}@if($installation->customer_accepted_at) · {{ $installation->customer_accepted_at->toDayDateTimeString() }}@endif</dd></div>
                            <div><dt class="text-gray-500">{{ __('Signatory') }}</dt><dd class="mt-1">{{ $installation->customer_signatory_name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Rejection reason') }}</dt><dd class="mt-1">{{ $installation->customer_rejection_reason ?? '—' }}</dd></div>
                        </dl>
                        @can('view', $installation)
                            <div class="mt-4 text-sm" data-testid="equipment-installation-evidence">
                                <span class="text-gray-500">{{ __('Evidence files') }}:</span> {{ $installation->media->count() }}
                                @foreach($installation->media as $media)
                                    <a class="ms-2 text-primary-600" href="{{ route('admin.equipment-installations.media.preview', ['installation' => $installation, 'media' => $media]) }}" target="_blank" rel="noopener">{{ $media->file_name }}</a>
                                @endforeach
                            </div>
                        @endcan
                    @endif
                    @if($installationRecord)
                        <div class="mt-3 text-sm"><a class="text-primary-600" href="{{ $maintenanceUrl($installationRecord) }}">{{ __('Open installation request #:id', ['id' => $installationRecord->id]) }}</a></div>
                    @endif
                @else
                    <p class="text-sm text-gray-500">{{ __('No installation recorded for this equipment.') }}</p>
                @endif
            </x-filament::section>

            @if(config('support.calibration_enabled', true))
                <x-filament::section data-testid="equipment-calibration-section">
                    <x-slot name="heading">{{ __('Calibration') }}</x-slot>
                    @if($lastCalibration)
                        <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3" data-testid="equipment-last-calibration">
                            <div><dt class="text-gray-500">{{ __('Last calibration') }}</dt><dd class="mt-1">{{ $lastCalibration->calibrated_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Result') }}</dt><dd class="mt-1"><x-filament::badge :color="$lastCalibration->result->getColor()">{{ $lastCalibration->result->label() }}</x-filament::badge></dd></div>
                            <div><dt class="text-gray-500">{{ __('Certificate') }}</dt><dd class="mt-1">{{ $lastCalibration->certificate_number ?? '—' }}@if($lastCalibration->certificate_expires_on) · {{ __('Expires :date', ['date' => $lastCalibration->certificate_expires_on->toFormattedDateString()]) }}@endif</dd></div>
                            <div><dt class="text-gray-500">{{ __('Next calibration due') }}</dt><dd class="mt-1">{{ $lastCalibration->next_calibration_due_on?->toFormattedDateString() ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Technician') }}</dt><dd class="mt-1">{{ $lastCalibration->performedBy?->user?->name ?? $lastCalibration->externalProvider?->name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Failure reason') }}</dt><dd class="mt-1">{{ $lastCalibration->failure_reason ?? '—' }}</dd></div>
                        </dl>
                        @include('filament.support.calibration-measurements', ['calibration' => $lastCalibration])
                        @can('view', $lastCalibration)
                            <div class="mt-4 text-sm" data-testid="equipment-calibration-evidence">
                                <span class="text-gray-500">{{ __('Evidence files') }}:</span> {{ $lastCalibration->media->count() }}
                                @foreach($lastCalibration->media as $media)
                                    <a class="ms-2 text-primary-600" href="{{ route('admin.equipment-calibrations.media.preview', ['calibration' => $lastCalibration, 'media' => $media]) }}" target="_blank" rel="noopener">{{ $media->file_name }}</a>
                                @endforeach
                            </div>
                        @endcan
                    @else
                        <p class="text-sm text-gray-500">{{ __('No calibration recorded for this equipment.') }}</p>
                    @endif
                    @if($calibrationProgress)
                        <div class="mt-4" data-testid="equipment-open-calibration">
                            @include('filament.support.calibration-progress', ['progress' => $calibrationProgress])
                            <div class="mt-3 text-sm"><a class="text-primary-600" href="{{ $maintenanceUrl($openCalibrationRecord) }}">{{ __('Open calibration request #:id', ['id' => $openCalibrationRecord->id]) }}</a></div>
                        </div>
                    @endif
                </x-filament::section>
            @endif

            @if(config('support.loaner_equipment_enabled', false) && ($loansAsOriginal->isNotEmpty() || $loansAsLoaner->isNotEmpty()))
                <x-filament::section data-testid="equipment-loaner-section">
                    <x-slot name="heading">{{ __('Temporary Replacement') }}</x-slot>
                    @foreach($loansAsOriginal as $loan)
                        <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-4" data-testid="equipment-loan-as-original">
                            <div><dt class="text-gray-500">{{ __('Loaner serial') }}</dt><dd class="mt-1">{{ $loan->loanerUnit?->serial_number ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd class="mt-1"><x-filament::badge :color="$loan->status->getColor()">{{ $loan->status->label() }}</x-filament::badge></dd></div>
                            <div><dt class="text-gray-500">{{ __('Issued') }}</dt><dd class="mt-1">{{ $loan->issued_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Returned') }}</dt><dd class="mt-1">{{ $loan->returned_at?->toDayDateTimeString() ?? ($loan->expected_return_at ? __('Expected :date', ['date' => $loan->expected_return_at->toDayDateTimeString()]) : '—') }}</dd></div>
                        </dl>
                    @endforeach
                    @if($loansAsLoaner->isNotEmpty())
                        <div class="mt-4 text-sm font-medium">{{ __('Loan history') }}</div>
                        <table class="mt-1 w-full text-start text-sm" data-testid="equipment-loan-history">
                            <thead><tr class="text-gray-500"><th class="text-start">{{ __('Customer') }}</th><th class="text-start">{{ __('Maintenance request') }}</th><th class="text-start">{{ __('Issued') }}</th><th class="text-start">{{ __('Returned') }}</th><th class="text-start">{{ __('Status') }}</th></tr></thead>
                            <tbody>
                            @foreach($loansAsLoaner as $loan)
                                <tr>
                                    <td>{{ $loan->customer?->company_name ?? '—' }}</td>
                                    <td><a class="text-primary-600" href="{{ $maintenanceUrl($loan->maintenanceRecord) }}">#{{ $loan->maintenance_record_id }}</a></td>
                                    <td>{{ $loan->issued_at?->toDateString() ?? '—' }}</td>
                                    <td>{{ $loan->returned_at?->toDateString() ?? '—' }}</td>
                                    <td>{{ $loan->status->label() }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            @endif

            @if(config('support.external_repair_enabled', false) && ($externalRepairs->isNotEmpty() || $replacementFor))
                <x-filament::section data-testid="equipment-rma-section">
                    <x-slot name="heading">{{ __('Supplier Repair (RMA)') }}</x-slot>
                    @if($replacementFor)
                        <p class="text-sm" data-testid="equipment-replacement-for">{{ __('Replacement for :serial', ['serial' => $replacementFor->serializedInventoryUnit?->serial_number ?? '—']) }}</p>
                    @endif
                    @foreach($externalRepairs as $repair)
                        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-4" data-testid="equipment-external-repair">
                            <div><dt class="text-gray-500">{{ __('Supplier') }}</dt><dd class="mt-1">{{ $repair->supplier?->name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('RMA number') }}</dt><dd class="mt-1">{{ $repair->rma_number ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd class="mt-1"><x-filament::badge :color="$repair->status->getColor()">{{ $repair->status->label() }}</x-filament::badge></dd></div>
                            <div><dt class="text-gray-500">{{ __('Request') }}</dt><dd class="mt-1"><a class="text-primary-600" href="{{ $maintenanceUrl($repair->maintenanceRecord) }}">#{{ $repair->maintenance_record_id }}</a></dd></div>
                        </dl>
                        @if($repair->replacementUnit)
                            <p class="mt-1 text-sm" data-testid="equipment-replaced-by">{{ __('Replaced by :serial', ['serial' => $repair->replacementUnit->serial_number]) }}</p>
                        @endif
                    @endforeach
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ __('Support history') }}</x-slot>
                <div class="space-y-3">
                    @forelse($tickets as $ticket)
                        <a href="{{ $ticketUrl($ticket) }}" class="ierp-card-link flex items-center justify-between">
                            <div>
                                <div class="font-medium">{{ $ticket->ticket_number }} — {{ $ticket->title }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ $ticket->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                            <x-filament::badge :color="$ticket->status->color()">{{ $ticket->status->label() }}</x-filament::badge>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No support tickets for this equipment.') }}</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('Maintenance history') }}</x-slot>
                <div class="space-y-3">
                    @forelse($maintenanceRecords as $job)
                        <a href="{{ $maintenanceUrl($job) }}" class="ierp-card-link flex items-center justify-between">
                            <div>
                                <div class="font-medium">{{ __('Job #:id', ['id' => $job->id]) }} — {{ $job->description }}</div>
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $job->maintenance_kind?->label() ?? __('Other') }}
                                    @if($job->failure_category) · {{ $job->failure_category->label() }} @endif
                                </div>
                            </div>
                            <x-filament::badge :color="$job->status->color()">{{ $job->status->label() }}</x-filament::badge>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No maintenance history for this equipment.') }}</p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <aside class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">{{ __('Reliability') }}</x-slot>
                <div class="grid grid-cols-2 gap-3">
                    <div class="ierp-tile" data-variant="subtle"><div class="text-xs text-gray-500">{{ __('Open tickets') }}</div><div class="mt-1 text-xl font-semibold">{{ $metrics->activeTickets }}</div></div>
                    <div class="ierp-tile" data-variant="subtle"><div class="text-xs text-gray-500">{{ __('Active jobs') }}</div><div class="mt-1 text-xl font-semibold">{{ $metrics->activeMaintenanceJobs }}</div></div>
                    <div class="ierp-tile" data-variant="subtle"><div class="text-xs text-gray-500">{{ __('MTTR') }}</div><div class="mt-1 font-semibold">{{ $metrics->mttrMinutes !== null ? __(':minutes min', ['minutes' => number_format($metrics->mttrMinutes, 1)]) : __('Not enough data') }}</div></div>
                    <div class="ierp-tile" data-variant="subtle"><div class="text-xs text-gray-500">{{ __('MTBF') }}</div><div class="mt-1 font-semibold">{{ $metrics->mtbfHours !== null ? __(':hours h', ['hours' => number_format($metrics->mtbfHours, 1)]) : __('Not enough data') }}</div></div>
                </div>
                <div class="mt-4 text-sm">
                    <div class="text-gray-500">{{ __('Corrective failures') }}</div>
                    <div class="mt-1 font-medium">{{ $metrics->correctiveFailureCount }}</div>
                    @if($metrics->repeatFailureCategories !== [])
                        <div class="ierp-alert mt-3" data-tone="warning">
                            {{ __('Repeat failures:') }}
                            @foreach($metrics->repeatFailureCategories as $category => $count)
                                <span class="font-medium">{{ \App\Enums\WarrantyFailureCategory::tryFrom((string) $category)?->label() ?? str($category)->headline() }} × {{ $count }}</span>@if(!$loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('Warranty & service') }}</x-slot>
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">{{ __('Warranty expires') }}</dt><dd class="mt-1 font-medium">{{ $unit->warranty_expires_on?->toDateString() ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">{{ __('Support entitlement') }}</dt>
                        <dd class="mt-1">
                            @if($supportEntitlement)
                                <span class="font-medium">{{ $supportEntitlement->serviceLevel->name }}</span>
                                <span class="text-gray-500">· {{ __('Valid until') }} {{ $supportEntitlement->ends_on?->toDateString() ?? '—' }}</span>
                            @else
                                {{ __('No active support entitlement') }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">{{ __('Last service') }}</dt><dd class="mt-1">{{ $metrics->lastServiceAt ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Next preventive due') }}</dt><dd class="mt-1">{{ $metrics->nextPreventiveDueOn ?? '—' }}</dd></div>
                </dl>
            </x-filament::section>

            @can('support.maintenance-cost.view')
                <x-filament::section>
                    <x-slot name="heading">{{ __('Lifetime service economics') }}</x-slot>
                    <dl class="space-y-3 text-sm">
                        <div><dt class="text-gray-500">{{ __('Lifetime service cost') }}</dt><dd class="mt-1 font-semibold">{{ number_format($metrics->lifetimeServiceCostMinor / 100, 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Warranty-covered cost') }}</dt><dd class="mt-1">{{ number_format($metrics->warrantyCoveredCostMinor / 100, 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Recovery claimed') }}</dt><dd class="mt-1">{{ number_format($metrics->recoveryClaimedMinor / 100, 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Recovery received') }}</dt><dd class="mt-1">{{ number_format($metrics->recoveryReceivedMinor / 100, 2) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Recovery outstanding') }}</dt><dd class="mt-1">{{ number_format($metrics->recoveryOutstandingMinor / 100, 2) }}</dd></div>
                    </dl>
                </x-filament::section>
            @endcan

            <x-filament::section>
                <x-slot name="heading">{{ __('Preventive maintenance') }}</x-slot>
                <dl class="mb-4 space-y-1 text-sm" data-testid="equipment-next-due">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Next preventive maintenance') }}</dt><dd>{{ $nextDueByKind->get('preventive')?->toFormattedDateString() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Next inspection') }}</dt><dd>{{ $nextDueByKind->get('inspection')?->toFormattedDateString() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Next calibration') }}</dt><dd>{{ $nextDueByKind->get('calibration')?->toFormattedDateString() ?? '—' }}</dd></div>
                </dl>
                <div class="space-y-3 text-sm">
                    @forelse($schedules as $schedule)
                        <div class="ierp-tile">
                            <div class="font-medium">{{ $schedule->name }}</div>
                            <div class="mt-1 text-gray-500">{{ $schedule->schedule_number }} · {{ $schedule->next_due_on?->toDateString() ?? __('No next due date') }}</div>
                        </div>
                    @empty
                        <p class="text-gray-500">{{ __('No preventive schedule configured.') }}</p>
                    @endforelse
                </div>
            </x-filament::section>
        </aside>
    </div>
</div>
