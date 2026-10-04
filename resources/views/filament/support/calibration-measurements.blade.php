@if($calibration && $calibration->measurements->isNotEmpty())
    <table class="w-full text-start text-sm" data-testid="calibration-measurements">
        <thead><tr class="text-gray-500">
            <th class="text-start">{{ __('Measurement') }}</th><th class="text-start">{{ __('Limits') }}</th><th class="text-start">{{ __('Actual') }}</th><th class="text-start">{{ __('Result') }}</th>
        </tr></thead>
        <tbody>
        @foreach($calibration->measurements as $measurement)
            <tr>
                <td>{{ $measurement->label }}</td>
                <td dir="ltr" class="text-start">{{ $measurement->minimum_value !== null ? rtrim(rtrim($measurement->minimum_value, '0'), '.') : '…' }} – {{ $measurement->maximum_value !== null ? rtrim(rtrim($measurement->maximum_value, '0'), '.') : '…' }} {{ $measurement->unit }}</td>
                <td dir="ltr" class="text-start">{{ $measurement->actual_value !== null ? rtrim(rtrim($measurement->actual_value, '0'), '.') : '—' }}</td>
                <td><x-filament::badge :color="$measurement->result->getColor()">{{ $measurement->result->label() }}</x-filament::badge></td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
