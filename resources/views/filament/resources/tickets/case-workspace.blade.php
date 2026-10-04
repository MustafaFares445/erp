@php
    use App\Enums\TicketEquipmentSource;
    use App\Enums\TicketStatus;
    use App\Enums\WarrantyStatus;
@endphp

<div class="space-y-6" data-testid="ticket-case-workspace">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex flex-wrap items-center gap-2">
                        <span>{{ $ticket->ticket_number }}</span>
                        <x-filament::badge :color="$workspaceState->stage->color()">
                            {{ $workspaceState->stage->label() }}
                        </x-filament::badge>
                        @if(! ($workspaceState->stage === \App\Enums\TicketStage::Closed && $ticket->status === TicketStatus::Closed))
                            <x-filament::badge :color="$ticket->status->color()">
                                {{ $ticket->status->label() }}
                            </x-filament::badge>
                        @endif
                    </div>
                </x-slot>
                <x-slot name="description">{{ $ticket->title }}</x-slot>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="ierp-tile">
                        <div class="ierp-eyebrow">{{ __('Next action') }}</div>
                        <div class="mt-2 font-semibold text-gray-950 dark:text-white">{{ $workspaceState->nextAction }}</div>
                    </div>
                    <div class="ierp-tile">
                        <div class="ierp-eyebrow">{{ __('Blocker') }}</div>
                        <div class="mt-2">
                            <x-filament::badge :color="$workspaceState->blocker->color()">
                                {{ $workspaceState->blocker->label() }}
                            </x-filament::badge>
                        </div>
                    </div>
                    <div class="ierp-tile">
                        <div class="ierp-eyebrow">{{ __('SLA') }}</div>
                        <div class="mt-2">
                            <x-filament::badge :color="$slaColor">{{ $slaLabel }}</x-filament::badge>
                        </div>
                    </div>
                </div>

                @if($ticket->continuedFromTicket)
                    <div class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                        {{ __('Continues ticket') }}
                        <a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $ticket->continuedFromTicket]) }}">
                            {{ $ticket->continuedFromTicket->ticket_number }}
                        </a>
                    </div>
                @endif

                @if(filled($ticket->description))
                    <div class="ierp-tile mt-4 text-sm leading-6 text-gray-700 dark:text-gray-200" data-variant="subtle">
                        {{ $ticket->description }}
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section data-testid="ticket-conversation">
                <x-slot name="heading">{{ __('Conversation & activity') }}</x-slot>
                <x-slot name="description">{{ __('Public replies and internal notes stay together in chronological order.') }}</x-slot>

                <div class="space-y-4">
                    @forelse($messages as $message)
                        <div class="ierp-tile" @if($message->is_internal_note) data-tone="warning" @endif>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $message->sender?->name ?? __('Unknown user') }}</span>
                                    @if($message->is_internal_note)
                                        <x-filament::badge color="warning">{{ __('Internal note') }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="primary">{{ __('Public reply') }}</x-filament::badge>
                                    @endif
                                    @if($message->getKey() === $firstPublicMessageId)
                                        <x-filament::badge color="success">{{ __('First response') }}</x-filament::badge>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $message->created_at?->diffForHumans() }}</span>
                            </div>
                            <div class="mt-3 whitespace-pre-wrap text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $message->message }}</div>
                        </div>
                    @empty
                        <div class="ierp-empty">
                            {{ __('No conversation yet. Post the first support response below.') }}
                        </div>
                    @endforelse

                    @can('message', $ticket)
                        <div class="ierp-tile">
                            <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Write a reply') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('replyMessage')">
                                <textarea
                                    wire:model="replyMessage"
                                    rows="4"
                                    class="block w-full resize-y border-0 bg-transparent px-3 py-2 text-sm text-gray-950 outline-none placeholder:text-gray-400 focus:ring-0 dark:text-white"
                                    placeholder="{{ __('Describe the update, question, or troubleshooting step…') }}"
                                ></textarea>
                            </x-filament::input.wrapper>
                            @error('replyMessage')
                                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" wire:model="replyInternalNote" class="fi-checkbox-input">
                                    <span>{{ __('Internal note — hidden from the customer') }}</span>
                                </label>
                                <x-filament::button wire:click="postMessage" wire:loading.attr="disabled" wire:target="postMessage">
                                    {{ __('Post message') }}
                                </x-filament::button>
                            </div>
                        </div>
                    @endcan
                </div>
            </x-filament::section>

            @if(config('support.knowledge_base_enabled', false) && $suggestedKnowledge->isNotEmpty())
                <x-filament::section data-testid="ticket-knowledge-suggestions">
                    <x-slot name="heading">{{ __('Suggested knowledge') }}</x-slot>
                    <x-slot name="description">{{ __('Published articles matched to this case by equipment, ticket type and issue keywords.') }}</x-slot>

                    <div class="space-y-3">
                        @foreach($suggestedKnowledge as $article)
                            <div class="ierp-tile">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $article->title }}</div>
                                        @if(filled($article->summary))
                                            <div class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $article->summary }}</div>
                                        @endif
                                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            @if($article->category)
                                                <span>{{ $article->category->name }}</span>
                                            @endif
                                            <span>{{ strtoupper($article->locale) }}</span>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        @if(in_array($article->visibility->value, ['customer', 'both'], true))
                                            <x-filament::button
                                                size="sm"
                                                color="primary"
                                                wire:click="shareKnowledgeArticle({{ $article->getKey() }})"
                                                wire:loading.attr="disabled"
                                                wire:target="shareKnowledgeArticle"
                                            >
                                                {{ __('Share with customer') }}
                                            </x-filament::button>
                                        @endif
                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            wire:click="markKnowledgeUsed({{ $article->getKey() }})"
                                            wire:loading.attr="disabled"
                                            wire:target="markKnowledgeUsed"
                                        >
                                            {{ __('Used in resolution') }}
                                        </x-filament::button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ __('Current work') }}</x-slot>
                <x-slot name="description">{{ __('Maintenance jobs raised from this support case.') }}</x-slot>

                <div class="space-y-3">
                    @forelse($maintenanceRecords as $record)
                        <a href="{{ $maintenanceUrl($record) }}" class="ierp-card-link flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-950 dark:text-white">{{ __('Maintenance job #:id', ['id' => $record->getKey()]) }}</div>
                                <div class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $record->description ?: __('No description') }}</div>
                            </div>
                            <x-filament::badge :color="$record->status->color()">{{ $record->status->label() }}</x-filament::badge>
                        </a>
                    @empty
                        <div class="ierp-empty">
                            {{ __('No maintenance job is linked to this ticket.') }}
                        </div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <aside class="space-y-6 xl:col-span-1" data-testid="ticket-context-sidebar">
            <x-filament::section>
                <x-slot name="heading">{{ __('Case context') }}</x-slot>
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Customer') }}</dt>
                        <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $ticket->customer?->company_name ?? __('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Priority') }}</dt>
                        <dd class="mt-1"><x-filament::badge>{{ $ticket->priority->label() }}</x-filament::badge></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Customer impact') }}</dt>
                        <dd class="mt-1">
                            @if($ticket->customer_impact)
                                <x-filament::badge :color="$ticket->customer_impact->color()">{{ $ticket->customer_impact->label() }}</x-filament::badge>
                            @else
                                <span class="text-gray-950 dark:text-white">{{ __('Not reported') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Support team') }}</dt>
                        <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $ticket->supportTeam?->name ?? __('Unrouted') }}</dd>
                        @if($ticket->routedByRule)
                            <dd class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Rule: :rule', ['rule' => $ticket->routedByRule->name]) }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Assigned to') }}</dt>
                        <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $ticket->assignedEmployee?->user?->name ?? __('Unassigned') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Service path') }}</dt>
                        <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $ticket->service_path?->label() ?? __('Not triaged') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Updated') }}</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">{{ $ticket->updated_at?->diffForHumans() }}</dd>
                    </div>
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('Service level') }}</x-slot>
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('First response') }}</dt>
                        <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                            {{ $ticket->first_response_at?->format('Y-m-d H:i') ?? __('Awaiting response') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Response due') }}</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">{{ $ticket->response_due_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Resolution due') }}</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">{{ $ticket->resolution_due_at?->format('Y-m-d H:i') ?? __('Not started') }}</dd>
                    </div>
                    @if($ticket->waiting_customer_since)
                        <div class="ierp-alert" data-tone="warning">
                            <x-filament::icon icon="heroicon-m-pause-circle" class="ierp-alert-icon" />
                            <span>{{ __('Resolution clock paused while waiting for the customer.') }}</span>
                        </div>
                    @endif
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('Equipment & warranty') }}</x-slot>
                @if($ticket->triaged_at)
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Equipment') }}</dt>
                            <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                @if($ticket->equipment_source === TicketEquipmentSource::SoldByUs)
                                    {{ $ticket->serializedInventoryUnit?->productVariant?->name ?? __('Known equipment') }}
                                @else
                                    {{ $ticket->external_equipment_name ?? __('External equipment') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Serial number') }}</dt>
                            <dd class="mt-1 text-gray-950 dark:text-white">
                                {{ $ticket->serializedInventoryUnit?->serial_number ?? $ticket->external_serial_number ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Warranty eligibility') }}</dt>
                            <dd class="mt-1">
                                @if($ticket->warranty_status)
                                    <x-filament::badge :color="$ticket->warranty_status->color()">{{ $ticket->warranty_status->label() }}</x-filament::badge>
                                @else
                                    {{ __('Not checked') }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Equipment and warranty context will appear after triage.') }}</p>
                @endif
            </x-filament::section>

            @if($ticket->diagnostic_fee_required)
                <x-filament::section>
                    <x-slot name="heading">{{ __('Diagnostic fee') }}</x-slot>
                    <div class="space-y-2 text-sm">
                        <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                            {{ number_format((float) $ticket->diagnostic_fee_amount, 2) }} {{ $ticket->diagnostic_fee_currency }}
                        </div>
                        <div class="text-gray-500 dark:text-gray-400">
                            {{ $ticket->paymentLink?->status?->label() ?? __('Payment link not created') }}
                        </div>
                        @if(filled($ticket->paymentLink?->payment_method_reference))
                            <div class="text-gray-500 dark:text-gray-400">
                                {{ __('Payment reference') }}: <span class="text-gray-950 dark:text-white">{{ $ticket->paymentLink->payment_method_reference }}</span>
                            </div>
                        @endif
                        <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                            <span>{{ __('Provider status') }}:</span>
                            @if($providerStatus = $ticket->paymentLink?->providerTransaction?->status)
                                <x-filament::badge :color="$providerStatus->color()">{{ $providerStatus->label() }}</x-filament::badge>
                            @else
                                <span class="text-gray-950 dark:text-white">{{ __('No provider transaction') }}</span>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @endif

            @if($attachments->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">{{ __('Attachments') }}</x-slot>
                    <div class="space-y-2">
                        @foreach($attachments as $media)
                            <a href="{{ route('admin.tickets.media.download', ['ticket' => $ticket, 'media' => $media]) }}" class="block truncate text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $media->file_name }}
                            </a>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </aside>
    </div>
</div>
