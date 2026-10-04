<div class="w-full">
    <ol class="ierp-stepper" role="list">
        @foreach ($steps as $index => $step)
            @php
                $state = match ($step['state']) {
                    'done' => 'completed',
                    'current' => 'current',
                    default => 'upcoming',
                };
            @endphp

            <li class="ierp-stepper-step" data-state="{{ $state }}" @if ($state === 'current') aria-current="step" @endif>
                <span class="ierp-stepper-marker" aria-hidden="true">
                    @if ($state === 'completed')
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-7" /></svg>
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>

                <span class="ierp-stepper-label">
                    {{ $step['label'] }}
                    <span class="sr-only">
                        — {{ match ($state) {
                            'completed' => __('Completed'),
                            'current' => __('Current step'),
                            default => __('Upcoming'),
                        } }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>
</div>
