@php
    $steps = $getSteps();
@endphp

<div {{ $getExtraAttributeBag()->class(['fi-ierp-workflow-stepper']) }}>
    <ol class="ierp-stepper" role="list">
        @foreach ($steps as $key => $label)
            @php
                $status = $statusFor((string) $key);
            @endphp

            <li class="ierp-stepper-step" data-state="{{ $status }}" @if ($status === 'current') aria-current="step" @endif>
                <span class="ierp-stepper-marker" aria-hidden="true">
                    @if ($status === 'completed')
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-7" /></svg>
                    @elseif ($status === 'terminal')
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><path d="m4.5 4.5 7 7m0-7-7 7" /></svg>
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>

                <span class="ierp-stepper-label">
                    {{ $label }}
                    <span class="sr-only">
                        — {{ match ($status) {
                            'completed' => __('Completed'),
                            'current' => __('Current step'),
                            'terminal' => __('Stopped'),
                            default => __('Upcoming'),
                        } }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>
</div>
